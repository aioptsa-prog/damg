<?php
/**
 * Run Integration Worker System Migration
 * Phase 6: Modular Worker Enrichment
 * Execute: php run_worker_migration.php
 */

require_once __DIR__ . '/bootstrap.php';

$pdo = db();

echo "Running integration worker system migration...\n";
echo "================================================\n";

// Read and execute migration file
$migrationFile = __DIR__ . '/migrations/005_integration_worker_system.sql';
if (!file_exists($migrationFile)) {
    die("Migration file not found: $migrationFile\n");
}

$sql = file_get_contents($migrationFile);

// Split by semicolon but handle comments
$statements = array_filter(
    array_map('trim', preg_split('/;(?=\s*(?:--|CREATE|INSERT|DELETE|DROP|ALTER|UPDATE|SELECT|$))/i', $sql)),
    function($s) {
        $s = trim($s);
        return $s && !preg_match('/^--/', $s);
    }
);

$success = 0;
$skipped = 0;
$errors = 0;

foreach ($statements as $stmt) {
    $stmt = trim($stmt);
    if (empty($stmt) || preg_match('/^--/', $stmt)) continue;
    
    try {
        $pdo->exec($stmt);
        $preview = substr(preg_replace('/\s+/', ' ', $stmt), 0, 60);
        echo "OK: {$preview}...\n";
        $success++;
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'already exists') !== false ||
            strpos($e->getMessage(), 'UNIQUE constraint') !== false) {
            $preview = substr(preg_replace('/\s+/', ' ', $stmt), 0, 40);
            echo "SKIP (exists): {$preview}...\n";
            $skipped++;
        } else {
            echo "ERROR: " . $e->getMessage() . "\n";
            echo "Statement: " . substr($stmt, 0, 100) . "...\n";
            $errors++;
        }
    }
}

echo "\n================================================\n";
echo "Migration complete: $success OK, $skipped skipped, $errors errors\n";
echo "================================================\n\n";

// Verify tables exist
echo "Verifying tables:\n";
$tables = ['integration_jobs', 'integration_job_runs', 'lead_snapshots'];
foreach ($tables as $table) {
    $result = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='$table'")->fetch();
    $status = $result ? "✓ EXISTS" : "✗ MISSING";
    echo "  - $table: $status\n";
}

// Verify settings
echo "\nVerifying settings:\n";
$settings = [
    'integration_worker_enabled',
    'integration_worker_concurrency', 
    'integration_worker_max_jobs_per_user_day',
    'integration_instagram_enabled'
];
foreach ($settings as $key) {
    $value = get_setting($key, 'NOT_SET');
    echo "  - $key: $value\n";
}

echo "\nDone!\n";
