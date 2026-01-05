<?php
/**
 * Public API - Logout
 * POST /v1/api/public/auth/logout.php
 */

require_once __DIR__ . '/../../bootstrap_api.php';
require_once __DIR__ . '/../../../../lib/public_auth.php';

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

try {
    logout_public_user();

    send_success([], 'تم تسجيل الخروج بنجاح');

} catch (Throwable $e) {
    error_log('Logout API Error: ' . $e->getMessage());
    send_error('حدث خطأ في الخادم', 'SERVER_ERROR', 500);
}
