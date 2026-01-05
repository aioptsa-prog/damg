<?php
/**
 * Minimal Bootstrap for WhatsApp APIs
 * Supports both admin and public user authentication
 */

// Load database
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/public_auth.php';

// Simple error handler
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    error_log("PHP Error [$errno]: $errstr in $errfile on line $errline");
    return true;
});

// Simple exception handler
set_exception_handler(function ($exception) {
    error_log("Uncaught Exception: " . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Internal server error']);
    exit;
});

/**
 * Get authenticated user - supports both admin and public users
 */
function get_whatsapp_user()
{
    // First try public user (public_sessions / public_users)
    $public_user = current_public_user();
    if ($public_user) {
        return $public_user;
    }

    // Then try admin user (sessions / users table)
    $admin_user = current_user();
    if ($admin_user) {
        return $admin_user;
    }

    return null;
}

/**
 * Require authentication for WhatsApp APIs
 */
function require_whatsapp_auth()
{
    $user = get_whatsapp_user();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'UNAUTHORIZED', 'message' => 'Authentication required']);
        exit;
    }
    return $user;
}

// Ensure JSON response
header('Content-Type: application/json; charset=utf-8');

/**
 * تنسيق رقم الهاتف السعودي - إضافة 966 تلقائياً
 * @param string $phone رقم الهاتف
 * @return string الرقم بتنسيق دولي
 */
function formatSaudiPhone($phone)
{
    if (empty($phone))
        return '';

    // إزالة المسافات والرموز غير الرقمية عدا +
    $cleaned = preg_replace('/[^\d+]/', '', $phone);

    // إذا كان يبدأ بـ + فهو بالفعل بتنسيق دولي
    if (strpos($cleaned, '+') === 0) {
        return $cleaned;
    }

    // إذا كان يبدأ بـ 00 (تنسيق دولي بديل)
    if (strpos($cleaned, '00') === 0) {
        return '+' . substr($cleaned, 2);
    }

    // إذا كان يبدأ بـ 966 (كود السعودية بدون +)
    if (strpos($cleaned, '966') === 0) {
        return '+' . $cleaned;
    }

    // إذا كان يبدأ بـ 05 (رقم سعودي محلي)
    if (strpos($cleaned, '05') === 0) {
        return '+966' . substr($cleaned, 1); // حذف الـ 0 وإضافة +966
    }

    // إذا كان يبدأ بـ 5 (رقم سعودي بدون 0)
    if (strpos($cleaned, '5') === 0 && strlen($cleaned) >= 9) {
        return '+966' . $cleaned;
    }

    // أي رقم آخر - افترض أنه سعودي وأضف 966
    return '+966' . $cleaned;
}
