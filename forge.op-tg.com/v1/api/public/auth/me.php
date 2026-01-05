<?php
/**
 * Public API - Get Current User
 * GET /v1/api/public/auth/me.php
 */

require_once __DIR__ . '/../../bootstrap_api.php';
require_once __DIR__ . '/../../../../lib/public_auth.php';
require_once __DIR__ . '/../../../../lib/subscriptions.php';

// Only allow GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

try {
    // Require authentication
    $user = require_public_auth();

    // Get subscription
    $subscription = get_user_subscription($user['id']);

    send_success([
        'user' => [
            'id' => (int) $user['id'],
            'email' => $user['email'],
            'name' => $user['name'],
            'company' => $user['company'],
            'phone' => $user['phone'],
            'email_verified' => (bool) $user['email_verified']
        ],
        'subscription' => $subscription ? [
            'plan' => [
                'id' => (int) $subscription['plan_id'],
                'name' => $subscription['name'],
                'slug' => $subscription['slug']
            ],
            'status' => $subscription['status'],
            'period_end' => $subscription['current_period_end'],
            'quotas' => [
                'phone' => (int) $subscription['credits_phone'],
                'email' => (int) $subscription['credits_email'],
                'export' => (int) $subscription['credits_export']
            ],
            'usage' => $subscription['usage']
        ] : null
    ]);

} catch (Throwable $e) {
    error_log('Me API Error: ' . $e->getMessage());
    send_error('حدث خطأ في الخادم', 'SERVER_ERROR', 500);
}
