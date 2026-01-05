<?php
/**
 * Google Web Usage API
 * Phase 7: Track daily usage for rate limiting
 * 
 * GET: Get current usage counts
 * POST: Increment usage for a provider
 */

require_once __DIR__ . '/../../../../bootstrap.php';
require_once __DIR__ . '/../../../../lib/integration_auth.php';

header('Content-Type: application/json; charset=utf-8');

// Verify internal secret (worker auth)
$secret = $_SERVER['HTTP_X_INTERNAL_SECRET'] ?? '';
$expectedSecret = get_setting('internal_secret', '');

if ($secret !== $expectedSecret || empty($secret)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];
$today = date('Y-m-d');

if ($method === 'GET') {
    // Get current usage
    $stmt = $pdo->prepare("SELECT provider, count FROM google_web_usage WHERE date = ?");
    $stmt->execute([$today]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $usage = [
        'serpapi' => 0,
        'chromium' => 0,
        'serpapi_limit' => (int) get_setting('google_web_max_per_day', '100'),
        'chromium_limit' => (int) get_setting('google_web_fallback_max_per_day', '10'),
        'date' => $today,
    ];
    
    foreach ($rows as $row) {
        $usage[$row['provider']] = (int) $row['count'];
    }
    
    echo json_encode($usage);
    exit;
}

if ($method === 'POST') {
    // Increment usage
    $input = json_decode(file_get_contents('php://input'), true);
    $provider = $input['provider'] ?? 'serpapi';
    
    if (!in_array($provider, ['serpapi', 'chromium'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid provider']);
        exit;
    }
    
    // Upsert usage count
    $stmt = $pdo->prepare("
        INSERT INTO google_web_usage (date, provider, count) 
        VALUES (?, ?, 1)
        ON CONFLICT(date, provider) DO UPDATE SET count = count + 1
    ");
    $stmt->execute([$today, $provider]);
    
    // Get new count
    $stmt = $pdo->prepare("SELECT count FROM google_web_usage WHERE date = ? AND provider = ?");
    $stmt->execute([$today, $provider]);
    $count = (int) $stmt->fetchColumn();
    
    echo json_encode(['ok' => true, 'provider' => $provider, 'count' => $count, 'date' => $today]);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
