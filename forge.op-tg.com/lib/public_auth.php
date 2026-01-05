<?php
/**
 * Public User Authentication Library
 * Handles authentication for public-facing users (separate from admin/agent)
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/subscriptions.php';

/**
 * Get current authenticated public user
 * Checks for Bearer token in Authorization header
 */
function current_public_user()
{
    // Check for Bearer token in Authorization header
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth = $_SERVER['HTTP_AUTHORIZATION'];
        if (preg_match('/Bearer\s+(.+)$/i', $auth, $matches)) {
            $token = $matches[1];
            $token_hash = hash('sha256', $token);

            $pdo = db();
            $stmt = $pdo->prepare("
                SELECT pu.* 
                FROM public_users pu
                INNER JOIN public_sessions ps ON pu.id = ps.user_id
                WHERE ps.token_hash = ? 
                  AND ps.expires_at > datetime('now')
                  AND pu.status = 'active'
            ");
            $stmt->execute([$token_hash]);
            $user = $stmt->fetch();

            if ($user) {
                return $user;
            }
        }
    }

    return null;
}

/**
 * Require public user authentication
 * Sends 401 error if not authenticated
 */
function require_public_auth()
{
    $user = current_public_user();
    if (!$user) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => false,
            'error' => 'UNAUTHORIZED',
            'message' => 'Authentication required'
        ]);
        exit;
    }
    return $user;
}

/**
 * Register a new public user
 * 
 * @param string $email
 * @param string $password
 * @param string $name
 * @param string|null $company
 * @param string|null $phone
 * @return array ['success' => bool, 'user' => array|null, 'token' => string|null, 'error' => string|null]
 */
function register_public_user($email, $password, $name, $company = null, $phone = null)
{
    $pdo = db();

    // Validate email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'INVALID_EMAIL', 'message' => 'البريد الإلكتروني غير صالح'];
    }

    // Check if email already exists
    $stmt = $pdo->prepare("SELECT id FROM public_users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        return ['success' => false, 'error' => 'EMAIL_EXISTS', 'message' => 'البريد الإلكتروني مستخدم بالفعل'];
    }

    // Hash password
    $password_hash = password_hash($password, PASSWORD_DEFAULT);

    // Generate verification token
    $verification_token = bin2hex(random_bytes(32));
    $verification_expires = date('Y-m-d H:i:s', time() + 86400); // 24 hours

    try {
        // Insert user
        $stmt = $pdo->prepare("
            INSERT INTO public_users (email, password_hash, name, company, phone, verification_token, verification_token_expires)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$email, $password_hash, $name, $company, $phone, $verification_token, $verification_expires]);
        $user_id = $pdo->lastInsertId();

        // Create session token
        $token = bin2hex(random_bytes(32));
        $token_hash = hash('sha256', $token);
        $expires_at = date('Y-m-d H:i:s', time() + 86400 * 30); // 30 days

        $stmt = $pdo->prepare("
            INSERT INTO public_sessions (user_id, token_hash, expires_at, device_info)
            VALUES (?, ?, ?, ?)
        ");
        $device_info = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $stmt->execute([$user_id, $token_hash, $expires_at, $device_info]);

        // Get user data
        $stmt = $pdo->prepare("SELECT * FROM public_users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();

        return [
            'success' => true,
            'user' => $user,
            'token' => $token,
            'verification_token' => $verification_token // For sending verification email
        ];

    } catch (Exception $e) {
        error_log('Public user registration error: ' . $e->getMessage());
        return ['success' => false, 'error' => 'SERVER_ERROR', 'message' => 'حدث خطأ في الخادم'];
    }
}

/**
 * Login public user
 * Accepts either email OR phone number
 * 
 * @param string $emailOrPhone Email or phone number
 * @param string $password
 * @return array ['success' => bool, 'user' => array|null, 'token' => string|null, 'subscription' => array|null]
 */
function login_public_user($emailOrPhone, $password)
{
    $pdo = db();

    // Get user by email OR phone
    $stmt = $pdo->prepare("SELECT * FROM public_users WHERE (email = ? OR phone = ?) AND status = 'active'");
    $stmt->execute([$emailOrPhone, $emailOrPhone]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return ['success' => false, 'error' => 'INVALID_CREDENTIALS', 'message' => 'البريد الإلكتروني/الجوال أو كلمة المرور غير صحيحة'];
    }

    // Create session token
    $token = bin2hex(random_bytes(32));
    $token_hash = hash('sha256', $token);
    $expires_at = date('Y-m-d H:i:s', time() + 86400 * 30); // 30 days

    $stmt = $pdo->prepare("
        INSERT INTO public_sessions (user_id, token_hash, expires_at, device_info, ip_address)
        VALUES (?, ?, ?, ?, ?)
    ");
    $device_info = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $stmt->execute([$user['id'], $token_hash, $expires_at, $device_info, $ip_address]);

    // Update last login
    $stmt = $pdo->prepare("UPDATE public_users SET last_login = datetime('now') WHERE id = ?");
    $stmt->execute([$user['id']]);

    // Get subscription info
    $subscription = get_user_subscription($user['id']);

    return [
        'success' => true,
        'user' => $user,
        'token' => $token,
        'subscription' => $subscription
    ];
}

/**
 * Logout public user (invalidate current token)
 */
function logout_public_user()
{
    $user = current_public_user();
    if (!$user) {
        return false;
    }

    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth = $_SERVER['HTTP_AUTHORIZATION'];
        if (preg_match('/Bearer\s+(.+)$/i', $auth, $matches)) {
            $token = $matches[1];
            $token_hash = hash('sha256', $token);

            $pdo = db();
            $stmt = $pdo->prepare("DELETE FROM public_sessions WHERE token_hash = ?");
            $stmt->execute([$token_hash]);

            return true;
        }
    }

    return false;
}

/**
 * Verify email address
 * 
 * @param string $token
 * @return bool
 */
function verify_email($token)
{
    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT id FROM public_users 
        WHERE verification_token = ? 
          AND verification_token_expires > datetime('now')
          AND email_verified = 0
    ");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if ($user) {
        $stmt = $pdo->prepare("
            UPDATE public_users 
            SET email_verified = 1, 
                verification_token = NULL, 
                verification_token_expires = NULL 
            WHERE id = ?
        ");
        $stmt->execute([$user['id']]);
        return true;
    }

    return false;
}

/**
 * Request password reset
 * 
 * @param string $email
 * @return array ['success' => bool, 'reset_token' => string|null]
 */
function request_password_reset($email)
{
    $pdo = db();

    $stmt = $pdo->prepare("SELECT id FROM public_users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        // Don't reveal if email exists
        return ['success' => true, 'reset_token' => null];
    }

    $reset_token = bin2hex(random_bytes(32));
    $reset_expires = date('Y-m-d H:i:s', time() + 3600); // 1 hour

    $stmt = $pdo->prepare("
        UPDATE public_users 
        SET reset_token = ?, reset_token_expires = ? 
        WHERE id = ?
    ");
    $stmt->execute([$reset_token, $reset_expires, $user['id']]);

    return ['success' => true, 'reset_token' => $reset_token];
}

/**
 * Reset password with token
 * 
 * @param string $token
 * @param string $new_password
 * @return bool
 */
function reset_password($token, $new_password)
{
    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT id FROM public_users 
        WHERE reset_token = ? 
          AND reset_token_expires > datetime('now')
    ");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if ($user) {
        $password_hash = password_hash($new_password, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("
            UPDATE public_users 
            SET password_hash = ?, 
                reset_token = NULL, 
                reset_token_expires = NULL 
            WHERE id = ?
        ");
        $stmt->execute([$password_hash, $user['id']]);
        return true;
    }

    return false;
}
