<?php
/**
 * REST API v1 - Authentication Endpoint: Current User
 * 
 * GET /v1/api/auth/me.php
 */

require_once __DIR__ . '/../bootstrap_api.php';
require_once __DIR__ . '/../../../lib/auth.php';

// Only allow GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

try {
    // Get current user (will be null if not authenticated)
    $user = current_user();

    if (!$user) {
        send_error('Not authenticated', 'UNAUTHORIZED', 401);
    }

    send_success([
        'user' => [
            'id' => (int) $user['id'],
            'name' => $user['name'] ?? $user['username'] ?? 'User',
            'mobile' => $user['mobile'],
            'role' => $user['role'],
            'is_superadmin' => (bool) ($user['is_superadmin'] ?? false),
            'active' => (bool) $user['active']
        ]
    ]);

} catch (Throwable $e) {
    error_log('Me API Error: ' . $e->getMessage());
    send_error('حدث خطأ في الخادم', 'SERVER_ERROR', 500);
}
