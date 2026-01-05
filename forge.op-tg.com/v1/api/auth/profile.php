<?php
/**
 * REST API v1 - User Profile Endpoint
 * 
 * GET /v1/api/auth/profile.php - Get current user profile
 */

require_once __DIR__ . '/../bootstrap_api.php';
require_once __DIR__ . '/../../../lib/auth.php';

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    http_response_code(200);
    exit;
}

// Only allow GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

try {
    // Get current user using same method as me.php
    $user = current_user();

    if (!$user) {
        send_error('يرجى تسجيل الدخول أولاً', 'UNAUTHORIZED', 401);
    }

    $pdo = db();

    // Get additional profile data from users table
    $stmt = $pdo->prepare("SELECT id, name, mobile, role, username, created_at FROM users WHERE id = ?");
    $stmt->execute([$user['id']]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$profile) {
        send_error('المستخدم غير موجود', 'USER_NOT_FOUND', 404);
    }

    send_success([
        'profile' => [
            'id' => (int) $profile['id'],
            'name' => $profile['name'] ?? $user['name'] ?? 'User',
            'email' => $profile['email'] ?? null,
            'mobile' => $profile['mobile'] ?? null,
            'phone' => $profile['mobile'] ?? null, // alias
            'role' => $profile['role'] ?? 'user',
            'company_name' => null, // Not applicable for admin users
            'created_at' => $profile['created_at']
        ]
    ]);

} catch (Throwable $e) {
    error_log('Profile API Error: ' . $e->getMessage());
    send_error('حدث خطأ في الخادم', 'SERVER_ERROR', 500);
}
