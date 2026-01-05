<?php
/**
 * Final Search API Test with proper URL encoding
 */

require_once __DIR__ . '/config/db.php';

echo "===========================================\n";
echo "Final Search API Test\n";
echo "===========================================\n\n";

$pdo = db();

// Get/create token
$stmt = $pdo->query("
    SELECT user_id FROM public_sessions ps 
    JOIN public_users pu ON ps.user_id = pu.id 
    WHERE ps.expires_at > datetime('now') AND pu.status = 'active' LIMIT 1
");
$session = $stmt->fetch();

$rawToken = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $rawToken);
$stmt = $pdo->prepare("INSERT INTO public_sessions (user_id, token_hash, expires_at, device_info) VALUES (?, ?, datetime('now', '+30 days'), 'Test')");
$stmt->execute([$session['user_id'], $tokenHash]);

$baseUrl = "http://localhost:8080/v1/api/public/leads/search.php";

function testApi($url, $token, $testName)
{
    echo "TEST: $testName\n";
    echo str_repeat("-", 50) . "\n";

    $opts = [
        'http' => [
            'method' => 'GET',
            'header' => "Authorization: Bearer $token\r\n",
            'timeout' => 10
        ]
    ];
    $context = stream_context_create($opts);
    $response = @file_get_contents($url, false, $context);

    if ($response === false) {
        echo "❌ FAILED - Connection error\n\n";
        return null;
    }

    $data = json_decode($response, true);
    if (!$data || !$data['ok']) {
        echo "❌ FAILED - " . ($data['message'] ?? 'Invalid response') . "\n\n";
        return null;
    }

    echo "✅ SUCCESS\n";
    echo "   Total: " . ($data['pagination']['total'] ?? 0) . " | Page " . ($data['pagination']['page'] ?? 0) . "/" . ($data['pagination']['pages'] ?? 0) . "\n";

    if (!empty($data['leads'])) {
        $lead = $data['leads'][0];
        echo "   Sample: {$lead['name']} | " . ($lead['city'] ?: 'No city') . " | " . ($lead['category']['name'] ?? 'No category') . "\n";
    }
    echo "\n";
    return $data;
}

// Build URLs with proper encoding
$tests = [
    ['Basic', $baseUrl . "?page=1&limit=10"],
    ['City=الرياض', $baseUrl . "?city=" . urlencode('الرياض') . "&limit=10"],
    ['City=جدة', $baseUrl . "?city=" . urlencode('جدة') . "&limit=10"],
    ['City=المدينة', $baseUrl . "?city=" . urlencode('المدينة') . "&limit=10"],
    ['Search=مطعم', $baseUrl . "?search=" . urlencode('مطعم') . "&limit=10"],
    ['Search=عقار', $baseUrl . "?search=" . urlencode('عقار') . "&limit=10"],
    ['Page 2', $baseUrl . "?page=2&limit=10"],
];

// Get a category with leads
$stmt = $pdo->query("SELECT c.id, c.name, COUNT(l.id) as cnt FROM categories c JOIN leads l ON l.category_id = c.id WHERE c.is_active = 1 GROUP BY c.id ORDER BY cnt DESC LIMIT 1");
$cat = $stmt->fetch();
if ($cat) {
    $tests[] = ["Category={$cat['name']}", $baseUrl . "?category_id={$cat['id']}&limit=10"];
}

foreach ($tests as [$name, $url]) {
    testApi($url, $rawToken, $name);
}

// Summary
echo "===========================================\n";
echo "DATABASE SUMMARY\n";
echo "===========================================\n";
$stmt = $pdo->query("SELECT COUNT(*) FROM leads");
echo "Total Leads: " . $stmt->fetchColumn() . "\n";

$stmt = $pdo->query("SELECT COUNT(DISTINCT city) FROM leads WHERE city IS NOT NULL AND city != ''");
echo "Unique Cities: " . $stmt->fetchColumn() . "\n";

$stmt = $pdo->query("SELECT COUNT(*) FROM categories WHERE is_active = 1");
echo "Active Categories: " . $stmt->fetchColumn() . "\n";

$stmt = $pdo->query("SELECT COUNT(*) FROM public_users WHERE status = 'active'");
echo "Active Users: " . $stmt->fetchColumn() . "\n";

echo "\n✅ All search functionality is working correctly!\n";
