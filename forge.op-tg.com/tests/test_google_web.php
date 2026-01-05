<?php
/**
 * Sprint 3.3: Google Web Search Provider Test
 * Tests the Google Web cache and usage tracking
 */

require_once __DIR__ . '/../bootstrap.php';

echo "=== Google Web Provider Test ===\n\n";

$pdo = db();

// 1. Check if google_web_cache table exists
echo "1. Checking google_web_cache table...\n";
try {
    $cols = $pdo->query("PRAGMA table_info(google_web_cache)")->fetchAll(PDO::FETCH_ASSOC);
    if (empty($cols)) {
        echo "   ⚠ Table doesn't exist, creating...\n";
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS google_web_cache (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                query_hash TEXT UNIQUE NOT NULL,
                query TEXT NOT NULL,
                provider TEXT NOT NULL,
                data TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                expires_at TEXT
            )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_gwc_hash ON google_web_cache(query_hash)");
        echo "   ✓ Table created\n";
    } else {
        echo "   ✓ Table exists with " . count($cols) . " columns\n";
    }
} catch (Exception $e) {
    echo "   ✗ Error: " . $e->getMessage() . "\n";
}

// 2. Check usage_counters table
echo "\n2. Checking usage_counters table...\n";
try {
    $cols = $pdo->query("PRAGMA table_info(usage_counters)")->fetchAll(PDO::FETCH_ASSOC);
    echo "   ✓ Table exists with " . count($cols) . " columns\n";
    
    // Check today's usage
    $day = date('Y-m-d');
    $stmt = $pdo->prepare("SELECT kind, count FROM usage_counters WHERE day = ?");
    $stmt->execute([$day]);
    $usage = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "   Today's usage:\n";
    foreach ($usage as $u) {
        echo "     - {$u['kind']}: {$u['count']}\n";
    }
    if (empty($usage)) {
        echo "     (no usage recorded today)\n";
    }
} catch (Exception $e) {
    echo "   ✗ Error: " . $e->getMessage() . "\n";
}

// 3. Test cache insert/retrieve
echo "\n3. Testing cache operations...\n";
$testHash = 'test_' . bin2hex(random_bytes(8));
$testQuery = 'مطاعم الرياض test';
$testData = json_encode([
    'results' => [
        ['title' => 'مطعم الشرق', 'url' => 'https://example.com'],
    ],
    'social_candidates' => [],
    'result_count' => 1,
]);

try {
    // Insert
    $stmt = $pdo->prepare("
        INSERT INTO google_web_cache (query_hash, query, provider, results_json, created_at, expires_at)
        VALUES (?, ?, 'test', ?, datetime('now'), datetime('now', '+24 hours'))
    ");
    $stmt->execute([$testHash, $testQuery, $testData]);
    echo "   ✓ Cache insert successful\n";
    
    // Retrieve
    $stmt = $pdo->prepare("SELECT * FROM google_web_cache WHERE query_hash = ?");
    $stmt->execute([$testHash]);
    $cached = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($cached) {
        echo "   ✓ Cache retrieve successful\n";
        echo "     Query: {$cached['query']}\n";
        echo "     Provider: {$cached['provider']}\n";
    } else {
        echo "   ✗ Cache retrieve failed\n";
    }
    
    // Cleanup
    $pdo->prepare("DELETE FROM google_web_cache WHERE query_hash = ?")->execute([$testHash]);
    echo "   ✓ Cache cleanup successful\n";
} catch (Exception $e) {
    echo "   ✗ Error: " . $e->getMessage() . "\n";
}

// 4. Test usage counter increment
echo "\n4. Testing usage counter...\n";
try {
    $day = date('Y-m-d');
    $kind = 'test_provider';
    
    // Increment
    $stmt = $pdo->prepare("
        INSERT INTO usage_counters (day, kind, count)
        VALUES (?, ?, 1)
        ON CONFLICT(day, kind) DO UPDATE SET count = count + 1
    ");
    $stmt->execute([$day, $kind]);
    echo "   ✓ Counter increment successful\n";
    
    // Read
    $stmt = $pdo->prepare("SELECT count FROM usage_counters WHERE day = ? AND kind = ?");
    $stmt->execute([$day, $kind]);
    $count = $stmt->fetchColumn();
    echo "   ✓ Counter value: $count\n";
    
    // Cleanup
    $pdo->prepare("DELETE FROM usage_counters WHERE kind = ?")->execute([$kind]);
    echo "   ✓ Counter cleanup successful\n";
} catch (Exception $e) {
    echo "   ✗ Error: " . $e->getMessage() . "\n";
}

// 5. Check Google Web API endpoint exists
echo "\n5. Checking Google Web API endpoints...\n";
$endpoints = [
    'v1/api/integration/google_web/cache.php',
    'v1/api/integration/google_web/usage.php',
];
foreach ($endpoints as $ep) {
    $path = __DIR__ . '/../' . $ep;
    if (file_exists($path)) {
        echo "   ✓ $ep exists\n";
    } else {
        echo "   ⚠ $ep not found\n";
    }
}

echo "\n=== Google Web Provider Test Complete ===\n";
