<?php
/**
 * Server-side Rate Limiting
 * Sprint 1.3: Rate Limit تعميم + Cleanup
 * 
 * Uses SQLite for persistence across requests/restarts
 * Includes automatic cleanup to prevent table bloat
 * 
 * @since Sprint 0 (Critical Security Fix)
 * @updated Sprint 1 (Generalization + Cleanup)
 */

// Cleanup probability (1 in N requests triggers cleanup)
define('RATE_LIMIT_CLEANUP_PROBABILITY', 100);

// Maximum age for rate limit entries (in seconds)
define('RATE_LIMIT_MAX_AGE', 3600); // 1 hour

/**
 * Ensure rate_limits table exists
 * 
 * @param PDO $pdo
 */
function ensure_rate_limit_table(PDO $pdo): void {
    static $created = false;
    if ($created) return;
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS rate_limits (
            k TEXT NOT NULL,
            window_start INTEGER NOT NULL,
            count INTEGER NOT NULL,
            created_at INTEGER DEFAULT (strftime('%s', 'now')),
            PRIMARY KEY (k, window_start)
        )
    ");
    
    // Create index for cleanup queries
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_rate_limits_window ON rate_limits(window_start)");
    
    $created = true;
}

/**
 * Cleanup old rate limit entries
 * Called probabilistically to avoid overhead on every request
 * 
 * @param PDO $pdo
 * @param bool $force Force cleanup regardless of probability
 */
function rate_limit_cleanup(PDO $pdo, bool $force = false): void {
    // Probabilistic cleanup (1 in N requests)
    if (!$force && rand(1, RATE_LIMIT_CLEANUP_PROBABILITY) !== 1) {
        return;
    }
    
    try {
        $cutoff = time() - RATE_LIMIT_MAX_AGE;
        $stmt = $pdo->prepare("DELETE FROM rate_limits WHERE window_start < ?");
        $stmt->execute([$cutoff]);
        
        $deleted = $stmt->rowCount();
        if ($deleted > 0) {
            error_log("[RateLimit] Cleaned up $deleted old entries");
        }
    } catch (Exception $e) {
        // Ignore cleanup errors - non-critical
        error_log("[RateLimit] Cleanup error: " . $e->getMessage());
    }
}

/**
 * Check rate limit and return 429 if exceeded
 * 
 * @param PDO $pdo Database connection
 * @param string $key Unique identifier (e.g., "send:IP:USER_ID")
 * @param int $limit Max requests allowed in window
 * @param int $windowSeconds Time window in seconds
 * @return void Exits with 429 if limit exceeded
 */
function rate_limit_or_429(PDO $pdo, string $key, int $limit, int $windowSeconds): void {
    $now = time();
    $windowStart = $now - ($now % $windowSeconds);

    ensure_rate_limit_table($pdo);
    
    // Probabilistic cleanup
    rate_limit_cleanup($pdo);

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("SELECT count FROM rate_limits WHERE k = :k AND window_start = :w");
        $stmt->execute([':k' => $key, ':w' => $windowStart]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $count = $row ? (int)$row['count'] : 0;

        if ($count >= $limit) {
            $pdo->rollBack();
            http_response_code(429);
            header('Content-Type: application/json; charset=utf-8');
            header('Retry-After: ' . (($windowStart + $windowSeconds) - $now));
            echo json_encode([
                'error' => 'Too Many Requests',
                'message' => 'تم تجاوز الحد المسموح من الطلبات',
                'retry_after_seconds' => ($windowStart + $windowSeconds) - $now
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($row) {
            $upd = $pdo->prepare("UPDATE rate_limits SET count = count + 1 WHERE k = :k AND window_start = :w");
            $upd->execute([':k' => $key, ':w' => $windowStart]);
        } else {
            $ins = $pdo->prepare("INSERT INTO rate_limits (k, window_start, count) VALUES (:k, :w, 1)");
            $ins->execute([':k' => $key, ':w' => $windowStart]);
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        // On error, allow request (fail-open for availability)
        error_log("Rate limit error: " . $e->getMessage());
    }
}

/**
 * Get remaining requests in current window
 * 
 * @param PDO $pdo Database connection
 * @param string $key Unique identifier
 * @param int $limit Max requests allowed
 * @param int $windowSeconds Time window in seconds
 * @return array ['remaining' => int, 'reset_at' => int]
 */
function rate_limit_status(PDO $pdo, string $key, int $limit, int $windowSeconds): array {
    $now = time();
    $windowStart = $now - ($now % $windowSeconds);

    try {
        $stmt = $pdo->prepare("SELECT count FROM rate_limits WHERE k = :k AND window_start = :w");
        $stmt->execute([':k' => $key, ':w' => $windowStart]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $count = $row ? (int)$row['count'] : 0;

        return [
            'remaining' => max(0, $limit - $count),
            'reset_at' => $windowStart + $windowSeconds
        ];
    } catch (Exception $e) {
        return ['remaining' => $limit, 'reset_at' => $now + $windowSeconds];
    }
}

// ============================================
// Pre-configured Rate Limiters
// ============================================

/**
 * Rate limit for WhatsApp send operations
 * 30 requests per minute per user
 */
function rate_limit_whatsapp(PDO $pdo, $userId): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = 'whatsapp:' . $userId . ':' . substr(md5($ip), 0, 8);
    rate_limit_or_429($pdo, $key, 30, 60);
}

/**
 * Rate limit for job creation
 * 10 jobs per minute per user
 */
function rate_limit_jobs(PDO $pdo, $userId): void {
    $key = 'jobs:' . $userId;
    rate_limit_or_429($pdo, $key, 10, 60);
}

/**
 * Rate limit for login attempts
 * 5 attempts per 15 minutes per IP
 */
function rate_limit_login(PDO $pdo): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = 'login:' . $ip;
    rate_limit_or_429($pdo, $key, 5, 900); // 15 minutes
}

/**
 * Rate limit for API calls (general)
 * 100 requests per minute per user
 */
function rate_limit_api(PDO $pdo, $userId): void {
    $key = 'api:' . $userId;
    rate_limit_or_429($pdo, $key, 100, 60);
}

/**
 * Rate limit for search operations
 * 20 searches per minute per user
 */
function rate_limit_search(PDO $pdo, $userId): void {
    $key = 'search:' . $userId;
    rate_limit_or_429($pdo, $key, 20, 60);
}
