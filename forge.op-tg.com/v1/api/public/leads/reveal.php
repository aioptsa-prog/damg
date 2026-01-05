<?php
/**
 * Public API - Reveal Contact Info
 * POST /v1/api/public/leads/reveal.php
 * 
 * Reveals phone or email for a lead (consumes credits)
 */

require_once __DIR__ . '/../../bootstrap_api.php';
require_once __DIR__ . '/../../../../lib/public_auth.php';
require_once __DIR__ . '/../../../../lib/subscriptions.php';

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

try {
    $user = require_public_auth();
    $input = get_json_input();

    validate_required_fields($input, ['lead_id', 'reveal_type']);

    $lead_id = (int) $input['lead_id'];
    $reveal_type = strtolower(trim($input['reveal_type']));

    // Validate reveal type
    if (!in_array($reveal_type, ['phone', 'email'])) {
        send_error('نوع الكشف غير صالح', 'INVALID_TYPE', 400);
    }

    // Check if already revealed
    if (has_revealed_contact($user['id'], $lead_id, $reveal_type)) {
        // Already revealed, just return the data
        $pdo = db();
        $stmt = $pdo->prepare("SELECT phone, email FROM leads WHERE id = ?");
        $stmt->execute([$lead_id]);
        $lead = $stmt->fetch();

        if (!$lead) {
            send_error('العميل غير موجود', 'NOT_FOUND', 404);
        }

        send_success([
            'revealed' => true,
            'already_revealed' => true,
            'data' => [
                $reveal_type => $lead[$reveal_type]
            ]
        ]);
    }

    // Check quota
    $quota = check_quota($user['id'], $reveal_type);

    if (!$quota['allowed']) {
        send_error(
            $quota['message'],
            'QUOTA_EXCEEDED',
            403,
            [
                'quota' => $quota,
                'upgrade_required' => true
            ]
        );
    }

    // Get lead data
    $pdo = db();
    $stmt = $pdo->prepare("SELECT phone, email FROM leads WHERE id = ?");
    $stmt->execute([$lead_id]);
    $lead = $stmt->fetch();

    if (!$lead) {
        send_error('العميل غير موجود', 'NOT_FOUND', 404);
    }

    if (empty($lead[$reveal_type])) {
        send_error('البيانات المطلوبة غير متوفرة', 'DATA_NOT_AVAILABLE', 404);
    }

    // Deduct credit
    if (!deduct_credit($user['id'], $reveal_type)) {
        send_error('فشل خصم الرصيد', 'CREDIT_DEDUCTION_FAILED', 500);
    }

    // Record reveal
    record_reveal($user['id'], $lead_id, $reveal_type);

    // Get updated quota
    $updated_quota = check_quota($user['id'], $reveal_type);

    send_success([
        'revealed' => true,
        'already_revealed' => false,
        'data' => [
            $reveal_type => $lead[$reveal_type]
        ],
        'quota' => $updated_quota
    ], 'تم كشف البيانات بنجاح');

} catch (Throwable $e) {
    error_log('Reveal API Error: ' . $e->getMessage());
    send_error('حدث خطأ في الخادم', 'SERVER_ERROR', 500);
}
