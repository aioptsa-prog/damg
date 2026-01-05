<?php
/**
 * Comprehensive Search API Test
 * Tests all search filters and validates data integrity
 */

require_once __DIR__ . '/config/db.php';

echo "===========================================\n";
echo "Comprehensive Search API Test\n";
echo "===========================================\n\n";

$pdo = db();

// Get a valid token
$stmt = $pdo->query("
    SELECT ps.token_hash, ps.user_id, pu.email, pu.name
    FROM public_sessions ps 
    JOIN public_users pu ON ps.user_id = pu.id 
    WHERE ps.expires_at > datetime('now')
    AND pu.status = 'active'
    LIMIT 1
");
$session = $stmt->fetch();

// Create fresh token
$rawToken = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $rawToken);
$expiresAt = date('Y-m-d H:i:s', time() + 86400 * 30);
$stmt = $pdo->prepare("INSERT INTO public_sessions (user_id, token_hash, expires_at, device_info) VALUES (?, ?, ?, 'Test')");
$stmt->execute([$session['user_id'], $tokenHash, $expiresAt]);

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
        echo "❌ FAILED - Could not connect to API\n\n";
        return null;
    }

    $data = json_decode($response, true);

    if (!$data) {
        echo "❌ FAILED - Invalid JSON response\n\n";
        return null;
    }

    if (!$data['ok']) {
        echo "❌ FAILED - API Error: " . ($data['message'] ?? 'Unknown') . "\n\n";
        return null;
    }

    echo "✅ SUCCESS\n";
    echo "   Total Results: " . ($data['pagination']['total'] ?? 'N/A') . "\n";
    echo "   Page: " . ($data['pagination']['page'] ?? 'N/A') . " of " . ($data['pagination']['pages'] ?? 'N/A') . "\n";
    echo "   Leads returned: " . count($data['leads'] ?? []) . "\n";

    if (!empty($data['leads'])) {
        $lead = $data['leads'][0];
        echo "   First Lead:\n";
        echo "     - Name: " . ($lead['name'] ?? 'N/A') . "\n";
        echo "     - City: " . ($lead['city'] ?? 'N/A') . "\n";
        echo "     - Category: " . ($lead['category']['name'] ?? 'N/A') . "\n";
        echo "     - Phone Available: " . ($lead['phone_available'] ? 'Yes' : 'No') . "\n";
    }

    echo "\n";
    return $data;
}

// Test 1: Basic search (no filters)
$test1 = testApi($baseUrl . "?page=1&limit=10", $rawToken, "Basic Search (No Filters)");

// Test 2: Search with city filter
$test2 = testApi($baseUrl . "?city=الرياض&limit=10", $rawToken, "City Filter (الرياض)");

// Test 3: Search with city filter - Jeddah
$test3 = testApi($baseUrl . "?city=جدة&limit=10", $rawToken, "City Filter (جدة)");

// Test 4: Search with text search
$test4 = testApi($baseUrl . "?search=مطعم&limit=10", $rawToken, "Text Search (مطعم)");

// Test 5: Get categories and test category filter
$stmt = $pdo->query("SELECT id, name FROM categories WHERE is_active = 1 LIMIT 1");
$cat = $stmt->fetch();
if ($cat) {
    $test5 = testApi($baseUrl . "?category_id=" . $cat['id'] . "&limit=10", $rawToken, "Category Filter ({$cat['name']})");
}

// Test 6: Combined filters
$test6 = testApi($baseUrl . "?city=الرياض&search=مطعم&limit=10", $rawToken, "Combined Filters (City + Search)");

// Test 7: Pagination test
$test7 = testApi($baseUrl . "?page=2&limit=10", $rawToken, "Pagination (Page 2)");

// Test 8: Limit test  
$test8 = testApi($baseUrl . "?limit=50", $rawToken, "Large Limit (50)");

echo "===========================================\n";
echo "Data Integrity Checks\n";
echo "===========================================\n\n";

// Check for duplicates 
$stmt = $pdo->query("SELECT phone, COUNT(*) as cnt FROM leads WHERE phone IS NOT NULL GROUP BY phone HAVING cnt > 1 LIMIT 5");
$duplicates = $stmt->fetchAll();
if (!empty($duplicates)) {
    echo "⚠️ Found duplicate phone numbers:\n";
    foreach ($duplicates as $dup) {
        echo "   - {$dup['phone']}: {$dup['cnt']} occurrences\n";
    }
} else {
    echo "✅ No duplicate phone numbers found\n";
}
echo "\n";

// Check for leads without required fields
$stmt = $pdo->query("SELECT COUNT(*) FROM leads WHERE name IS NULL OR name = ''");
$nullNames = $stmt->fetchColumn();
echo "Leads without names: $nullNames\n";

$stmt = $pdo->query("SELECT COUNT(*) FROM leads WHERE city IS NULL OR city = ''");
$nullCities = $stmt->fetchColumn();
echo "Leads without cities: $nullCities\n";

$stmt = $pdo->query("SELECT COUNT(*) FROM leads WHERE phone IS NULL OR phone = ''");
$nullPhones = $stmt->fetchColumn();
echo "Leads without phones: $nullPhones\n";

$stmt = $pdo->query("SELECT COUNT(*) FROM leads WHERE category_id IS NULL");
$nullCategories = $stmt->fetchColumn();
echo "Leads without categories: $nullCategories\n";

echo "\n===========================================\n";
echo "All tests completed!\n";
echo "===========================================\n";
