<?php
/**
 * Campaigns API - Create Campaign
 * POST /v1/api/campaigns/create.php
 * 
 * Sprint 1 Security:
 * - CORS Allowlist
 * - Unified Auth (RBAC)
 * - Input Validation
 * - Rate Limiting
 * - Security Headers
 */

// Security: CORS + Headers
require_once __DIR__ . '/../../../lib/cors.php';
require_once __DIR__ . '/../../../lib/security_headers.php';
handle_cors(['POST', 'OPTIONS']);
apply_api_security_headers();

// Core dependencies
require_once __DIR__ . '/../bootstrap_api.php';
require_once __DIR__ . '/../../../lib/validation.php';
require_once __DIR__ . '/../../lib/rate_limit.php';

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED']);
    exit;
}

// Auth: Require authenticated user (any role can create campaigns)
$user = require_api_user();
$pdo = db();

// Rate Limit: 10 jobs per minute
rate_limit_jobs($pdo, $user['id']);

// Input Validation: Parse and validate JSON
$input = parse_json_input();

$validated = validate_schema($input, [
    'name' => ['type' => 'string', 'required' => true, 'min' => 2, 'max' => 100],
    'city' => ['type' => 'string', 'required' => true, 'min' => 2, 'max' => 50],
    'query' => ['type' => 'string', 'required' => false, 'max' => 200],
    'category' => ['type' => 'string', 'required' => false, 'max' => 200],
    'target' => ['type' => 'int', 'required' => false, 'min' => 1, 'max' => 1000, 'default' => 100],
    'description' => ['type' => 'string', 'required' => false, 'max' => 500],
    'category_id' => ['type' => 'int', 'required' => false],
]);

$name = $validated['name'];
$city = $validated['city'];
$query = $validated['query'] ?? $validated['category'] ?? '';
$target = $validated['target'] ?? 100;
$description = $validated['description'] ?? '';
$category_id = $validated['category_id'] ?? null;

// Get city coordinates (all Saudi cities)
$cityCoords = [
    'الرياض' => '24.7136,46.6753',
    'جدة' => '21.4858,39.1925',
    'مكة المكرمة' => '21.4225,39.8262',
    'مكة' => '21.4225,39.8262',
    'المدينة المنورة' => '24.5247,39.5692',
    'المدينة' => '24.5247,39.5692',
    'الدمام' => '26.4207,50.0888',
    'الخبر' => '26.2172,50.1971',
    'الظهران' => '26.2873,50.1149',
    'الأحساء' => '25.3823,49.5853',
    'القطيف' => '26.5556,49.9987',
    'الجبيل' => '27.0046,49.6592',
    'الطائف' => '21.2854,40.4150',
    'تبوك' => '28.3838,36.5657',
    'بريدة' => '26.3267,43.9750',
    'حائل' => '27.5219,41.6837',
    'خميس مشيط' => '18.3091,42.7258',
    'أبها' => '18.2164,42.5053',
    'نجران' => '17.4917,44.1277',
    'جازان' => '16.8806,42.5611',
    'ينبع' => '24.0895,38.0618',
    'الباحة' => '20.0152,41.4676',
    'عنيزة' => '26.0884,43.9909',
    'الرس' => '25.8686,43.4973',
    'سكاكا' => '29.9697,40.1999',
    'عرعر' => '30.9755,41.0381',
    'القريات' => '31.3317,37.3426',
    'حفر الباطن' => '28.4328,45.9635',
    'الخرج' => '24.1556,47.3126',
    'الدوادمي' => '24.5007,44.3950',
    'المجمعة' => '25.9037,45.3431',
    'شقراء' => '25.2419,45.2542',
    'الزلفي' => '26.2876,44.8143',
    'وادي الدواسر' => '20.4884,44.7246',
    'بيشة' => '19.9873,42.5994',
    'صبيا' => '17.1504,42.6257',
    'رابغ' => '22.8016,39.0342',
    'القنفذة' => '19.1297,41.0786',
    'محايل عسير' => '18.5491,42.0478',
];
$ll = $cityCoords[$city] ?? '24.7136,46.6753'; // Default to Riyadh if not found

// If no query, use category as query
if (empty($query)) {
    $query = $city; // Default to city name
}

try {
    $pdo = db();

    // Create user campaign
    $stmt = $pdo->prepare("
        INSERT INTO user_campaigns (user_id, name, description, query, city, ll, target_count, category_id, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', datetime('now'))
    ");
    $stmt->execute([$user['id'], $name, $description, $query, $city, $ll, $target, $category_id]);
    $campaignId = $pdo->lastInsertId();

    // Create internal job for worker
    $stmt = $pdo->prepare("
        INSERT INTO internal_jobs (requested_by_user_id, role, query, ll, radius_km, lang, region, status, target_count, queued_at, created_at, updated_at)
        VALUES (?, 'public_user', ?, ?, 15, 'ar', 'sa', 'queued', ?, datetime('now'), datetime('now'), datetime('now'))
    ");
    $stmt->execute([$user['id'], $query, $ll, $target]);
    $jobId = $pdo->lastInsertId();

    // Link campaign to job
    $pdo->prepare("UPDATE user_campaigns SET internal_job_id = ?, status = 'processing', started_at = datetime('now') WHERE id = ?")->execute([$jobId, $campaignId]);

    echo json_encode([
        'ok' => true,
        'campaign' => [
            'id' => (int) $campaignId,
            'name' => $name,
            'city' => $city,
            'query' => $query,
            'target_count' => $target,
            'status' => 'processing',
            'job_id' => (int) $jobId
        ],
        'message' => 'تم إنشاء الحملة بنجاح'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'SERVER_ERROR', 'message' => $e->getMessage()]);
}
