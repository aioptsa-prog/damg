<?php
/**
 * Unified API Authentication & RBAC
 * Sprint 1.1: Auth/RBAC + Secrets
 * 
 * Roles:
 * - admin: Full access to all endpoints
 * - supervisor: Can manage agents and view all data
 * - sales/agent: Can only access own data
 * 
 * Auth Methods:
 * - Bearer token (preferred for API)
 * - Session (for web UI)
 * 
 * @since Sprint 1
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/public_auth.php';

// Role hierarchy (higher index = more permissions)
define('ROLE_HIERARCHY', [
    'sales' => 1,
    'agent' => 1,  // alias for sales
    'supervisor' => 2,
    'admin' => 3,
]);

/**
 * Get current authenticated user from any auth method
 * Checks: Bearer token (admin) → Bearer token (public) → Session
 * 
 * @return array|null User data with 'role' field
 */
function get_api_user(): ?array {
    // 1. Try admin/agent Bearer token
    $admin = current_user();
    if ($admin) {
        return array_merge($admin, ['auth_type' => 'admin']);
    }
    
    // 2. Try public user Bearer token
    $public = current_public_user();
    if ($public) {
        return array_merge($public, [
            'role' => 'sales', // Public users are sales role
            'auth_type' => 'public'
        ]);
    }
    
    return null;
}

/**
 * Require authentication for API endpoint
 * 
 * @return array User data
 * @throws Exits with 401 if not authenticated
 */
function require_api_user(): array {
    $user = get_api_user();
    if (!$user) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'error' => 'UNAUTHORIZED',
            'message' => 'Authentication required'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return $user;
}

/**
 * Require minimum role level
 * 
 * @param string $minRole Minimum required role (sales|supervisor|admin)
 * @return array User data
 * @throws Exits with 403 if insufficient permissions
 */
function require_role(string $minRole): array {
    $user = require_api_user();
    
    $userRole = $user['role'] ?? 'sales';
    $userLevel = ROLE_HIERARCHY[$userRole] ?? 0;
    $requiredLevel = ROLE_HIERARCHY[$minRole] ?? 0;
    
    if ($userLevel < $requiredLevel) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'error' => 'FORBIDDEN',
            'message' => 'Insufficient permissions',
            'required_role' => $minRole,
            'your_role' => $userRole
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    return $user;
}

/**
 * Check if user has at least the specified role
 * 
 * @param array $user User data
 * @param string $minRole Minimum role to check
 * @return bool
 */
function has_role(array $user, string $minRole): bool {
    $userRole = $user['role'] ?? 'sales';
    $userLevel = ROLE_HIERARCHY[$userRole] ?? 0;
    $requiredLevel = ROLE_HIERARCHY[$minRole] ?? 0;
    return $userLevel >= $requiredLevel;
}

/**
 * Check if user can access a specific resource
 * Admin/Supervisor can access all, Sales can only access own
 * 
 * @param array $user Current user
 * @param string|int $resourceOwnerId Owner ID of the resource
 * @return bool
 */
function can_access_resource(array $user, $resourceOwnerId): bool {
    // Admin and supervisor can access all
    if (has_role($user, 'supervisor')) {
        return true;
    }
    
    // Sales can only access own resources
    return (string)$user['id'] === (string)$resourceOwnerId;
}

/**
 * Filter query results based on user role
 * Returns SQL WHERE clause addition
 * 
 * @param array $user Current user
 * @param string $ownerColumn Column name for owner ID
 * @return string SQL WHERE clause (empty for admin/supervisor)
 */
function get_ownership_filter(array $user, string $ownerColumn = 'user_id'): string {
    if (has_role($user, 'supervisor')) {
        return ''; // No filter for admin/supervisor
    }
    
    // Sales can only see own data
    return " AND {$ownerColumn} = " . (int)$user['id'];
}

/**
 * Log authentication event (without sensitive data)
 * 
 * @param string $action Action type (login, logout, auth_fail, etc.)
 * @param array|null $user User data (if available)
 * @param array $extra Extra data to log (no secrets!)
 */
function log_auth_event(string $action, ?array $user = null, array $extra = []): void {
    $pdo = db();
    
    // Sanitize extra data - remove any potential secrets
    $safeExtra = array_filter($extra, function($key) {
        $forbidden = ['password', 'token', 'secret', 'key', 'api_key', 'auth'];
        foreach ($forbidden as $word) {
            if (stripos($key, $word) !== false) {
                return false;
            }
        }
        return true;
    }, ARRAY_FILTER_USE_KEY);
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, after, created_at)
            VALUES (?, ?, ?, ?, ?, datetime('now'))
        ");
        $stmt->execute([
            $user['id'] ?? 'anonymous',
            'auth_' . $action,
            'auth',
            $user['id'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            json_encode($safeExtra)
        ]);
    } catch (Exception $e) {
        error_log("Failed to log auth event: " . $e->getMessage());
    }
}

// ============================================
// Secret Protection Helpers
// ============================================

/**
 * Get secret from environment (never from DB or logs)
 * 
 * @param string $name Environment variable name
 * @param string|null $default Default value if not set
 * @return string|null
 */
function get_secret(string $name, ?string $default = null): ?string {
    $value = getenv($name);
    if ($value === false || $value === '') {
        return $default;
    }
    return $value;
}

/**
 * Mask a secret for safe logging
 * Shows first 4 and last 4 characters only
 * 
 * @param string $secret
 * @return string
 */
function mask_secret(string $secret): string {
    $len = strlen($secret);
    if ($len <= 8) {
        return str_repeat('*', $len);
    }
    return substr($secret, 0, 4) . str_repeat('*', $len - 8) . substr($secret, -4);
}

/**
 * Safe error logging - strips potential secrets
 * 
 * @param string $message
 * @param array $context
 */
function safe_log(string $message, array $context = []): void {
    // Patterns that might contain secrets
    $patterns = [
        '/(["\']?(?:api[_-]?key|secret|token|password|auth)["\']?\s*[=:]\s*)["\']?[^"\'}\s,]+["\']?/i',
        '/Bearer\s+[A-Za-z0-9\-_]+/i',
        '/AIza[A-Za-z0-9\-_]{35}/i', // Google API key pattern
        '/sk-[A-Za-z0-9]{48}/i', // OpenAI key pattern
    ];
    
    $safeMessage = $message;
    foreach ($patterns as $pattern) {
        $safeMessage = preg_replace($pattern, '$1[REDACTED]', $safeMessage);
    }
    
    // Also sanitize context
    $safeContext = [];
    foreach ($context as $key => $value) {
        if (is_string($value)) {
            $safeValue = $value;
            foreach ($patterns as $pattern) {
                $safeValue = preg_replace($pattern, '[REDACTED]', $safeValue);
            }
            $safeContext[$key] = $safeValue;
        } else {
            $safeContext[$key] = $value;
        }
    }
    
    error_log($safeMessage . ($safeContext ? ' ' . json_encode($safeContext) : ''));
}
