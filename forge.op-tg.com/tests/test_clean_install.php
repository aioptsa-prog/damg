<?php
/**
 * Clean Install Test
 * Sprint 4A: Verify system works from fresh clone
 * 
 * Run: php tests/test_clean_install.php
 */

echo "=== Clean Install Verification ===\n\n";

$checks = [];
$allPassed = true;

// 1. Check required files exist
echo "1. Checking required files...\n";
$requiredFiles = [
    'bootstrap.php' => 'Core bootstrap',
    '.env.example' => 'Environment template',
    'v1/api/health.php' => 'Health endpoint',
    'lib/logger.php' => 'Logger',
    'lib/api_auth.php' => 'Auth helper',
    'lib/cors.php' => 'CORS helper',
    'lib/validation.php' => 'Validation helper',
];

foreach ($requiredFiles as $file => $desc) {
    $path = __DIR__ . '/../' . $file;
    if (file_exists($path)) {
        echo "   ✓ $file ($desc)\n";
        $checks[$file] = true;
    } else {
        echo "   ✗ $file MISSING ($desc)\n";
        $checks[$file] = false;
        $allPassed = false;
    }
}

// 2. Check .env exists (not .env.example)
echo "\n2. Checking environment...\n";
$envPath = __DIR__ . '/../.env';
$envExamplePath = __DIR__ . '/../.env.example';

if (file_exists($envPath)) {
    echo "   ✓ .env exists\n";
    $checks['env'] = true;
} else {
    echo "   ⚠ .env not found - copy from .env.example\n";
    $checks['env'] = 'warning';
    if (file_exists($envExamplePath)) {
        echo "   → Run: cp .env.example .env\n";
    }
}

// 3. Check database
echo "\n3. Checking database...\n";
try {
    require_once __DIR__ . '/../bootstrap.php';
    $pdo = db();
    
    // Check tables exist
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
    $requiredTables = ['users', 'leads', 'internal_jobs', 'settings'];
    
    $missingTables = array_diff($requiredTables, $tables);
    if (empty($missingTables)) {
        echo "   ✓ Database connected (" . count($tables) . " tables)\n";
        echo "   ✓ Required tables present\n";
        $checks['database'] = true;
    } else {
        echo "   ✗ Missing tables: " . implode(', ', $missingTables) . "\n";
        $checks['database'] = false;
        $allPassed = false;
    }
} catch (Exception $e) {
    echo "   ✗ Database error: " . $e->getMessage() . "\n";
    $checks['database'] = false;
    $allPassed = false;
}

// 4. Check logs directory
echo "\n4. Checking logs directory...\n";
$logsDir = __DIR__ . '/../logs';
if (is_dir($logsDir) && is_writable($logsDir)) {
    echo "   ✓ logs/ directory exists and writable\n";
    $checks['logs'] = true;
} else {
    if (!is_dir($logsDir)) {
        mkdir($logsDir, 0755, true);
        echo "   ✓ logs/ directory created\n";
        $checks['logs'] = true;
    } else {
        echo "   ✗ logs/ not writable\n";
        $checks['logs'] = false;
        $allPassed = false;
    }
}

// 5. Check feature flags
echo "\n5. Checking feature flags...\n";
try {
    require_once __DIR__ . '/../lib/flags.php';
    $flags = integration_flags_all();
    $enabledCount = count(array_filter($flags));
    echo "   ✓ Feature flags loaded ($enabledCount enabled)\n";
    foreach ($flags as $flag => $enabled) {
        $status = $enabled ? '✓' : '○';
        echo "     $status $flag\n";
    }
    $checks['flags'] = true;
} catch (Exception $e) {
    echo "   ⚠ Could not load flags: " . $e->getMessage() . "\n";
    $checks['flags'] = 'warning';
}

// 6. Test logger
echo "\n6. Testing logger...\n";
try {
    require_once __DIR__ . '/../lib/logger.php';
    Logger::init();
    $correlationId = Logger::getCorrelationId();
    Logger::info('Clean install test', ['test' => true]);
    echo "   ✓ Logger working (correlation_id: $correlationId)\n";
    $checks['logger'] = true;
} catch (Exception $e) {
    echo "   ✗ Logger error: " . $e->getMessage() . "\n";
    $checks['logger'] = false;
    $allPassed = false;
}

// 7. Test secret masking
echo "\n7. Testing secret masking...\n";
$testSecrets = [
    'api_key=sk-1234567890abcdef1234567890abcdef' => 'OpenAI key',
    'token: AIzaSyABC123DEF456GHI789JKL012MNO345PQR' => 'Gemini key',
    'ghp_XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX1234' => 'GitHub PAT',
];

$maskingWorks = true;
foreach ($testSecrets as $secret => $desc) {
    $masked = Logger::maskSecrets($secret);
    if (strpos($masked, 'MASKED') !== false && strpos($masked, 'sk-1234') === false) {
        echo "   ✓ $desc masked correctly\n";
    } else {
        echo "   ✗ $desc NOT masked: $masked\n";
        $maskingWorks = false;
    }
}
$checks['masking'] = $maskingWorks;
if (!$maskingWorks) $allPassed = false;

// Summary
echo "\n=== Summary ===\n";
$passed = count(array_filter($checks, fn($v) => $v === true));
$warnings = count(array_filter($checks, fn($v) => $v === 'warning'));
$failed = count(array_filter($checks, fn($v) => $v === false));

echo "Passed: $passed | Warnings: $warnings | Failed: $failed\n";

if ($allPassed) {
    echo "\n✅ Clean install verification PASSED\n";
    exit(0);
} else {
    echo "\n❌ Clean install verification FAILED\n";
    exit(1);
}
