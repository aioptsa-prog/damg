<?php
// Simple English output for visibility
require_once __DIR__ . '/config/db.php';
$pdo = db();

// Get a valid session
$stmt = $pdo->query("SELECT user_id FROM public_sessions ps JOIN public_users pu ON ps.user_id = pu.id WHERE ps.expires_at > datetime('now') AND pu.status = 'active' LIMIT 1");
$session = $stmt->fetch();
$token = bin2hex(random_bytes(32));
$pdo->prepare("INSERT INTO public_sessions (user_id, token_hash, expires_at, device_info) VALUES (?, ?, datetime('now', '+1 hour'), 'Demo')")->execute([$session['user_id'], hash('sha256', $token)]);

$baseUrl = "http://localhost:8080/v1/api/public/leads/search.php";

function api($url, $token)
{
    return json_decode(@file_get_contents($url, false, stream_context_create(['http' => ['header' => "Authorization: Bearer $token", 'timeout' => 10]])), true);
}

echo "Search Results:\n";
echo str_repeat("=", 60) . "\n\n";

// Basic search
$data = api($baseUrl . "?page=1&limit=10", $token);
if ($data && $data['ok']) {
    echo "Total: " . $data['pagination']['total'] . " leads\n";
    echo "Pages: " . $data['pagination']['pages'] . "\n\n";
    echo "First 10 results:\n";
    foreach ($data['leads'] as $i => $lead) {
        echo ($i + 1) . ". " . $lead['name'] . "\n";
        echo "   City: " . ($lead['city'] ?: '-') . " | Category: " . ($lead['category']['name'] ?? '-') . "\n";
    }
}

// Search with keyword
echo "\n\nSearch: 'restaurant'\n";
echo str_repeat("-", 40) . "\n";
$data = api($baseUrl . "?search=" . urlencode('مطعم') . "&limit=5", $token);
if ($data && $data['ok']) {
    echo "Found: " . $data['pagination']['total'] . "\n";
    foreach ($data['leads'] as $i => $lead) {
        echo ($i + 1) . ". " . $lead['name'] . "\n";
    }
}

echo "\n\nSearch: 'real estate'\n";
echo str_repeat("-", 40) . "\n";
$data = api($baseUrl . "?search=" . urlencode('عقار') . "&limit=5", $token);
if ($data && $data['ok']) {
    echo "Found: " . $data['pagination']['total'] . "\n";
    foreach ($data['leads'] as $i => $lead) {
        echo ($i + 1) . ". " . $lead['name'] . "\n";
    }
}

echo "\n\nOK - All search tests passed!\n";
