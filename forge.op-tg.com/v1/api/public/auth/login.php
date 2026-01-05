<?php
/**
 * Public API - User Login
 * POST /v1/api/public/auth/login.php
 */

require_once __DIR__ . '/../../bootstrap_api.php';
require_once __DIR__ . '/../../../../lib/public_auth.php';
require_once __DIR__ . '/../../../../lib/subscriptions.php';

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

try {
    $input = get_json_input();

    // Validate required fields
    validate_required_fields($input, ['email', 'password']);

    $email = trim($input['email']);
    $password = $input['password'];

    // Login user
    $result = login_public_user($email, $password);

    if (!$result['success']) {
        send_error($result['message'], $result['error'], 401);
    }

    // Return user, token, and subscription info
    send_success([
        'user' => [
            'id' => (int) $result['user']['id'],
            'email' => $result['user']['email'],
            'name' => $result['user']['name'],
            'company' => $result['user']['company'],
            'phone' => $result['user']['phone'],
            'email_verified' => (bool) $result['user']['email_verified']
        ],
        'token' => $result['token'],
        'subscription' => $result['subscription'] ? [
            'plan' => [
                'id' => (int) $result['subscription']['plan_id'],
                'name' => $result['subscription']['name'],
                'slug' => $result['subscription']['slug']
            ],
            'status' => $result['subscription']['status'],
            'period_end' => $result['subscription']['current_period_end'],
            'quotas' => [
                'phone' => (int) $result['subscription']['credits_phone'],
                'email' => (int) $result['subscription']['credits_email'],
                'export' => (int) $result['subscription']['credits_export']
            ],
            'usage' => $result['subscription']['usage']
        ] : null
    ], 'تم تسجيل الدخول بنجاح');

} catch (Throwable $e) {
    error_log('Login API Error: ' . $e->getMessage());
    send_error('حدث خطأ في الخادم', 'SERVER_ERROR', 500);
}
