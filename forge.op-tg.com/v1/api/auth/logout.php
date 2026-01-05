<?php
/**
 * REST API v1 - Authentication Endpoint: Logout
 * 
 * POST /v1/api/auth/logout.php
 */

require_once __DIR__ . '/../bootstrap_api.php';
require_once __DIR__ . '/../../../lib/auth.php';

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

try {
    // Logout user
    logout();

    send_success([], 'Logout successful');

} catch (Throwable $e) {
    error_log('Logout API Error: ' . $e->getMessage());
    send_error('حدث خطأ في الخادم', 'SERVER_ERROR', 500);
}
