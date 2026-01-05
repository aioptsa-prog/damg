<?php
/**
 * Public API - User Registration
 * POST /v1/api/public/auth/register.php
 */

require_once __DIR__ . '/../../bootstrap_api.php';
require_once __DIR__ . '/../../../../lib/public_auth.php';

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

try {
    $input = get_json_input();

    // Validate required fields
    validate_required_fields($input, ['email', 'password', 'name']);

    $email = trim($input['email']);
    $password = $input['password'];
    $name = trim($input['name']);
    $company = trim($input['company'] ?? '');
    $phone = trim($input['phone'] ?? '');

    // Validate password strength
    if (strlen($password) < 8) {
        send_error('كلمة المرور يجب أن تكون 8 أحرف على الأقل', 'WEAK_PASSWORD', 400);
    }

    // Register user
    $result = register_public_user($email, $password, $name, $company ?: null, $phone ?: null);

    if (!$result['success']) {
        send_error($result['message'], $result['error'], 400);
    }

    // TODO: Send verification email
    // send_verification_email($result['user']['email'], $result['verification_token']);

    // Return user and token
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
        'message' => 'تم إنشاء الحساب بنجاح. يرجى التحقق من بريدك الإلكتروني.'
    ]);

} catch (Throwable $e) {
    error_log('Registration API Error: ' . $e->getMessage());
    send_error('حدث خطأ في الخادم', 'SERVER_ERROR', 500);
}
