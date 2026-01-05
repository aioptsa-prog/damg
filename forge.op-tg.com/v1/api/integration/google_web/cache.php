<?php
/**
 * Google Web Cache API
 * Phase 7: Cache management for Google Web search results
 * 
 * GET: Check cache by query hash
 * POST: Save cache entry
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

if ($method === 'GET') {
    // Check cache
    $hash = $_GET['hash'] ?? '';
    
    if (empty($hash)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing hash parameter']);
        exit;
    }
    
    $stmt = $pdo->prepare("
        SELECT * FROM google_web_cache 
        WHERE query_hash = ? AND expires_at > datetime('now')
    ");
    $stmt->execute([$hash]);
    $cache = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($cache) {
        echo json_encode([
            'ok' => true,
            'success' => true,
            'from_cache' => true,
            'data' => json_decode($cache['results_json'], true),
            'provider' => $cache['provider'],
            'cached_at' => $cache['created_at'],
        ]);
    } else {
        echo json_encode(['ok' => true, 'success' => false, 'data' => null]);
    }
    exit;
}

if ($method === 'POST') {
    // Save cache
    $input = json_decode(file_get_contents('php://input'), true);
    
    $hash = $input['hash'] ?? '';
    $query = $input['query'] ?? '';
    $provider = $input['provider'] ?? 'serpapi';
    $data = $input['data'] ?? [];
    
    if (empty($hash) || empty($query)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
        exit;
    }
    
    $cacheHours = (int) get_setting('google_web_cache_hours', '24');
    $expiresAt = date('Y-m-d H:i:s', strtotime("+{$cacheHours} hours"));
    $id = bin2hex(random_bytes(16));
    
    $stmt = $pdo->prepare("
        INSERT OR REPLACE INTO google_web_cache 
        (id, query_hash, query, provider, results_json, created_at, expires_at)
        VALUES (?, ?, ?, ?, ?, datetime('now'), ?)
    ");
    $stmt->execute([$id, $hash, $query, $provider, json_encode($data), $expiresAt]);
    
    echo json_encode(['ok' => true, 'cached' => true, 'expires_at' => $expiresAt]);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
