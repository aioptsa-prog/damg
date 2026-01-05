<?php
/**
 * Server-side Rate Limiting (Critical Security Fix)
 * Uses SQLite for persistence across requests/restarts
 */

/**
 * Check rate limit and return 429 if exceeded
 * 
 * @param PDO $pdo Database connection
 * @param string $key Unique identifier (e.g., "send:IP:API_KEY_HASH")
 * @param int $limit Max requests allowed in window
 * @param int $windowSeconds Time window in seconds
 * @return void Exits with 429 if limit exceeded
 */
function rate_limit_or_429(PDO $pdo, string $key, int $limit, int $windowSeconds): void {
    $now = time();
    $windowStart = $now - ($now % $windowSeconds);

    // Ensure table exists (idempotent)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS rate_limits (
            k TEXT NOT NULL,
            window_start INTEGER NOT NULL,
            count INTEGER NOT NULL,
            PRIMARY KEY (k, window_start)
        )
    ");

    // Clean old entries (best-effort, non-blocking)
    try {
        $cutoff = $now - ($windowSeconds * 2);
        $pdo->prepare("DELETE FROM rate_limits WHERE window_start < ?")->execute([$cutoff]);
    } catch (Exception $e) {
        // Ignore cleanup errors
    }

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
