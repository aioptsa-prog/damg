<?php
/**
 * Integration Authentication Helper
 * 
 * Provides functions to verify integration tokens issued by the exchange endpoint.
 * 
 * @since Phase 1
 */

/**
 * Verify an integration token from Authorization header
 * 
 * @return array|null User info if valid, null otherwise
 *   ['op_target_user_id' => string, 'forge_role' => string, 'metadata' => array]
 */
function verify_integration_token(): ?array {
    // Check if integration auth bridge is enabled
    if (!function_exists('integration_flag') || !integration_flag('auth_bridge')) {
        return null;
    }
    
    // Get Authorization header
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($authHeader === '') {
        // Try alternate header name
        $authHeader = $_SERVER['HTTP_X_INTEGRATION_TOKEN'] ?? '';
    }
    
    if ($authHeader === '') {
        return null;
    }
    
    // Extract token from "Bearer <token>" format
    $token = $authHeader;
    if (stripos($authHeader, 'Bearer ') === 0) {
        $token = substr($authHeader, 7);
    }
    
    if ($token === '' || strlen($token) !== 64) {
        return null;
    }
    
    $pdo = db();
    $now = date('Y-m-d H:i:s');
    
    // Lookup token
    $stmt = $pdo->prepare("
        SELECT op_target_user_id, forge_role, expires_at, metadata, last_used_at
        FROM integration_sessions 
        WHERE token = ? AND expires_at > ?
    ");
    $stmt->execute([$token, $now]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$session) {
        return null;
    }
    
    // Update last_used_at
    $stmt = $pdo->prepare("UPDATE integration_sessions SET last_used_at = ? WHERE token = ?");
    $stmt->execute([$now, $token]);
    
    // Parse metadata
    $metadata = [];
    if (!empty($session['metadata'])) {
        $metadata = json_decode($session['metadata'], true) ?? [];
    }
    
    return [
        'op_target_user_id' => $session['op_target_user_id'],
        'forge_role' => $session['forge_role'],
        'metadata' => $metadata,
    ];
}

/**
 * Get current user from either regular auth or integration token
 * 
 * This extends the existing auth system without breaking it.
 * Priority: Regular session/cookie auth > Integration token
 * 
 * @return array|null User info or null
 */
function current_user_or_integration(): ?array {
    // First try regular auth
    $user = current_user();
    if ($user) {
        return $user;
    }
    
    // Then try integration token
    $integration = verify_integration_token();
    if ($integration) {
        // Return a pseudo-user compatible with existing code
        return [
            'id' => 'integration:' . $integration['op_target_user_id'],
            'username' => 'integration_user',
            'role' => $integration['forge_role'],
            'is_integration' => true,
            'op_target_user_id' => $integration['op_target_user_id'],
        ];
    }
    
    return null;
}

/**
 * Cleanup expired integration sessions
 * Call this periodically (e.g., from a cron job or on each request with low probability)
 */
function cleanup_integration_sessions(): int {
    $pdo = db();
    $now = date('Y-m-d H:i:s');
    
    $stmt = $pdo->prepare("DELETE FROM integration_sessions WHERE expires_at < ?");
    $stmt->execute([$now]);
    
    return $stmt->rowCount();
}

/**
 * Revoke all integration sessions for a specific OP-Target user
 * 
 * @param string $opTargetUserId The OP-Target user ID
 * @return int Number of sessions revoked
 */
function revoke_integration_sessions(string $opTargetUserId): int {
    $pdo = db();
    
    $stmt = $pdo->prepare("DELETE FROM integration_sessions WHERE op_target_user_id = ?");
    $stmt->execute([$opTargetUserId]);
    
    return $stmt->rowCount();
}

/**
 * Validate integration token and return result
 * Phase 6: Used by worker integration endpoints
 * 
 * @return array ['valid' => bool, 'user_id' => string|null, 'error' => string|null]
 */
function validate_integration_token(): array {
    $integration = verify_integration_token();
    
    if (!$integration) {
        return [
            'valid' => false,
            'error' => 'Invalid or expired token',
            'user_id' => null,
        ];
    }
    
    return [
        'valid' => true,
        'user_id' => $integration['op_target_user_id'],
        'role' => $integration['forge_role'],
        'error' => null,
    ];
}
