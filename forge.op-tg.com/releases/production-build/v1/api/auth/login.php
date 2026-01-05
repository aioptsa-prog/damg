<?php
/**
 * REST API v1 - Authentication Endpoint: Login
 * 
 * POST /v1/api/auth/login.php
 */

// Load API bootstrap FIRST (handles errors, headers, and environment)
require_once __DIR__ . '/../bootstrap_api.php';
require_once __DIR__ . '/../../../lib/auth.php';

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

try {
    // Get and validate JSON input
    $input = get_json_input();
    validate_required_fields($input, ['mobile', 'password']);

    $mobile = trim($input['mobile']);
    $password = $input['password'];
    $remember = (bool) ($input['remember'] ?? false);

    // Attempt login using existing auth library
    $loginSuccess = login($mobile, $password, $remember);

    if ($loginSuccess) {
        $user = current_user();

        if (!$user) {
            send_error('User load failed', 'USER_LOAD_FAILED', 500);
        }

        // Success response
        send_success([
            'user' => [
                'id' => (int) $user['id'],
                'name' => $user['name'] ?? $user['username'] ?? 'User',
                'mobile' => $user['mobile'],
                'role' => $user['role'],
                'is_superadmin' => (bool) ($user['is_superadmin'] ?? false)
            ],
            'token' => $GLOBALS['last_login_token'] ?? null
        ], 'Login successful');
    } else {
        // Failed login
        send_error('رقم الهاتف أو كلمة المرور غير صحيحة', 'INVALID_CREDENTIALS', 401);
    }

} catch (Throwable $e) {
    error_log('Login API Error: ' . $e->getMessage());
    send_error('حدث خطأ في الخادم', 'SERVER_ERROR', 500);
}
