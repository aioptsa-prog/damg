<?php
/**
 * Subscription Management Library
 * Handles subscription plans, user subscriptions, and usage quotas
 */

require_once __DIR__ . '/../config/db.php';

/**
 * Get user's current subscription
 * Returns free plan if no active subscription
 * 
 * @param int $user_id
 * @return array|null
 */
function get_user_subscription($user_id)
{
    $pdo = db();

    // Get active subscription
    $stmt = $pdo->prepare("
        SELECT us.*, sp.* 
        FROM user_subscriptions us
        INNER JOIN subscription_plans sp ON us.plan_id = sp.id
        WHERE us.user_id = ? 
          AND us.status = 'active'
          AND us.current_period_end > datetime('now')
        ORDER BY us.created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    $subscription = $stmt->fetch();

    if ($subscription) {
        // Get current month's usage
        $usage = get_user_usage($user_id);
        $subscription['usage'] = $usage;
        return $subscription;
    }

    // Return free plan as default
    $stmt = $pdo->prepare("SELECT * FROM subscription_plans WHERE slug = 'free'");
    $stmt->execute();
    $free_plan = $stmt->fetch();

    if ($free_plan) {
        $usage = get_user_usage($user_id);
        $free_plan['usage'] = $usage;
        return $free_plan;
    }

    return null;
}

/**
 * Get user's usage for current month
 * 
 * @param int $user_id
 * @return array
 */
function get_user_usage($user_id)
{
    $pdo = db();
    $current_month = date('Y-m');

    $stmt = $pdo->prepare("
        SELECT * FROM usage_tracking 
        WHERE user_id = ? AND month = ?
    ");
    $stmt->execute([$user_id, $current_month]);
    $usage = $stmt->fetch();

    if (!$usage) {
        // Create new usage record for this month
        $stmt = $pdo->prepare("
            INSERT INTO usage_tracking (user_id, month)
            VALUES (?, ?)
        ");
        $stmt->execute([$user_id, $current_month]);

        return [
            'phone_reveals' => 0,
            'email_reveals' => 0,
            'exports_count' => 0,
            'searches_count' => 0,
            'api_calls' => 0
        ];
    }

    return $usage;
}

/**
 * Check if user has quota available
 * 
 * @param int $user_id
 * @param string $type One of: 'phone', 'email', 'export'
 * @return array ['allowed' => bool, 'remaining' => int, 'limit' => int]
 */
function check_quota($user_id, $type)
{
    $subscription = get_user_subscription($user_id);

    if (!$subscription) {
        return ['allowed' => false, 'remaining' => 0, 'limit' => 0, 'message' => 'لا يوجد اشتراك نشط'];
    }

    $usage = $subscription['usage'];

    switch ($type) {
        case 'phone':
            $limit = (int) $subscription['credits_phone'];
            $used = (int) $usage['phone_reveals'];
            break;
        case 'email':
            $limit = (int) $subscription['credits_email'];
            $used = (int) $usage['email_reveals'];
            break;
        case 'export':
            $limit = (int) $subscription['credits_export'];
            $used = (int) $usage['exports_count'];
            break;
        default:
            return ['allowed' => false, 'remaining' => 0, 'limit' => 0, 'message' => 'نوع غير صالح'];
    }

    // 0 means unlimited
    if ($limit === 0) {
        return ['allowed' => true, 'remaining' => -1, 'limit' => 0, 'message' => 'غير محدود'];
    }

    $remaining = $limit - $used;
    $allowed = $remaining > 0;

    return [
        'allowed' => $allowed,
        'remaining' => max(0, $remaining),
        'limit' => $limit,
        'used' => $used,
        'message' => $allowed ? "متبقي $remaining من $limit" : 'تم استنفاد الحصة'
    ];
}

/**
 * Deduct credit from user's quota
 * 
 * @param int $user_id
 * @param string $type One of: 'phone', 'email', 'export', 'search', 'api'
 * @return bool Success
 */
function deduct_credit($user_id, $type)
{
    // Check quota first (except for search and api which are always tracked)
    if (in_array($type, ['phone', 'email', 'export'])) {
        $quota = check_quota($user_id, $type);
        if (!$quota['allowed']) {
            return false;
        }
    }

    $pdo = db();
    $current_month = date('Y-m');

    // Ensure usage record exists
    $stmt = $pdo->prepare("
        INSERT OR IGNORE INTO usage_tracking (user_id, month)
        VALUES (?, ?)
    ");
    $stmt->execute([$user_id, $current_month]);

    // Increment the appropriate counter
    $column_map = [
        'phone' => 'phone_reveals',
        'email' => 'email_reveals',
        'export' => 'exports_count',
        'search' => 'searches_count',
        'api' => 'api_calls'
    ];

    if (!isset($column_map[$type])) {
        return false;
    }

    $column = $column_map[$type];

    $stmt = $pdo->prepare("
        UPDATE usage_tracking 
        SET $column = $column + 1,
            updated_at = datetime('now')
        WHERE user_id = ? AND month = ?
    ");
    $stmt->execute([$user_id, $current_month]);

    return true;
}

/**
 * Get all available subscription plans
 * 
 * @return array
 */
function get_subscription_plans()
{
    $pdo = db();

    $stmt = $pdo->query("
        SELECT * FROM subscription_plans 
        WHERE is_active = 1 
        ORDER BY sort_order ASC
    ");

    return $stmt->fetchAll();
}

/**
 * Create or update user subscription
 * 
 * @param int $user_id
 * @param int $plan_id
 * @param string $billing_cycle 'monthly' or 'yearly'
 * @param string|null $payment_subscription_id
 * @return int|null Subscription ID
 */
function create_subscription($user_id, $plan_id, $billing_cycle = 'monthly', $payment_subscription_id = null)
{
    $pdo = db();

    // Cancel existing active subscriptions
    $stmt = $pdo->prepare("
        UPDATE user_subscriptions 
        SET status = 'cancelled',
            cancelled_at = datetime('now'),
            updated_at = datetime('now')
        WHERE user_id = ? AND status = 'active'
    ");
    $stmt->execute([$user_id]);

    // Calculate period dates
    $start = date('Y-m-d H:i:s');
    if ($billing_cycle === 'yearly') {
        $end = date('Y-m-d H:i:s', strtotime('+1 year'));
    } else {
        $end = date('Y-m-d H:i:s', strtotime('+1 month'));
    }

    // Create new subscription
    $stmt = $pdo->prepare("
        INSERT INTO user_subscriptions (
            user_id, plan_id, status, billing_cycle,
            current_period_start, current_period_end,
            payment_subscription_id
        ) VALUES (?, ?, 'active', ?, ?, ?, ?)
    ");
    $stmt->execute([$user_id, $plan_id, $billing_cycle, $start, $end, $payment_subscription_id]);

    return $pdo->lastInsertId();
}

/**
 * Cancel user subscription
 * Can be immediate or at period end
 * 
 * @param int $user_id
 * @param bool $immediate
 * @return bool
 */
function cancel_subscription($user_id, $immediate = false)
{
    $pdo = db();

    if ($immediate) {
        $stmt = $pdo->prepare("
            UPDATE user_subscriptions 
            SET status = 'cancelled',
                cancelled_at = datetime('now'),
                updated_at = datetime('now')
            WHERE user_id = ? AND status = 'active'
        ");
        $stmt->execute([$user_id]);
    } else {
        $stmt = $pdo->prepare("
            UPDATE user_subscriptions 
            SET cancel_at_period_end = 1,
                updated_at = datetime('now')
            WHERE user_id = ? AND status = 'active'
        ");
        $stmt->execute([$user_id]);
    }

    return $stmt->rowCount() > 0;
}

/**
 * Check if user has already revealed a specific contact
 * 
 * @param int $user_id
 * @param int $lead_id
 * @param string $reveal_type 'phone' or 'email'
 * @return bool
 */
function has_revealed_contact($user_id, $lead_id, $reveal_type)
{
    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT id FROM revealed_contacts 
        WHERE user_id = ? AND lead_id = ? AND reveal_type = ?
    ");
    $stmt->execute([$user_id, $lead_id, $reveal_type]);

    return (bool) $stmt->fetch();
}

/**
 * Record a contact reveal
 * 
 * @param int $user_id
 * @param int $lead_id
 * @param string $reveal_type 'phone' or 'email'
 * @return bool
 */
function record_reveal($user_id, $lead_id, $reveal_type)
{
    $pdo = db();

    try {
        $stmt = $pdo->prepare("
            INSERT INTO revealed_contacts (user_id, lead_id, reveal_type)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$user_id, $lead_id, $reveal_type]);
        return true;
    } catch (Exception $e) {
        // Already revealed (UNIQUE constraint)
        return false;
    }
}

/**
 * Get user's revealed contacts
 * 
 * @param int $user_id
 * @param string|null $reveal_type
 * @return array
 */
function get_revealed_contacts($user_id, $reveal_type = null)
{
    $pdo = db();

    $sql = "
        SELECT rc.*, l.name as lead_name, l.phone, l.email
        FROM revealed_contacts rc
        INNER JOIN leads l ON rc.lead_id = l.id
        WHERE rc.user_id = ?
    ";

    $params = [$user_id];

    if ($reveal_type) {
        $sql .= " AND rc.reveal_type = ?";
        $params[] = $reveal_type;
    }

    $sql .= " ORDER BY rc.revealed_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

/**
 * Require minimum subscription plan
 * Sends 403 error if user doesn't have required plan
 * 
 * @param int $user_id
 * @param string $min_plan_slug Minimum required plan slug
 * @return bool
 */
function require_subscription($user_id, $min_plan_slug)
{
    $subscription = get_user_subscription($user_id);

    if (!$subscription) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => false,
            'error' => 'SUBSCRIPTION_REQUIRED',
            'message' => 'يتطلب هذا الإجراء اشتراكاً نشطاً'
        ]);
        exit;
    }

    // Define plan hierarchy
    $plan_levels = ['free' => 0, 'basic' => 1, 'professional' => 2, 'enterprise' => 3];

    $user_level = $plan_levels[$subscription['slug']] ?? 0;
    $required_level = $plan_levels[$min_plan_slug] ?? 0;

    if ($user_level < $required_level) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => false,
            'error' => 'UPGRADE_REQUIRED',
            'message' => 'يتطلب هذا الإجراء ترقية الاشتراك',
            'current_plan' => $subscription['name'],
            'required_plan' => $min_plan_slug
        ]);
        exit;
    }

    return true;
}
