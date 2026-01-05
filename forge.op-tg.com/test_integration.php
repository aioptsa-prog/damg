<?php
/**
 * Integration Test Script
 * Tests the worker enrichment flow locally
 */

require_once __DIR__ . '/bootstrap.php';

echo "=== Integration Test ===\n\n";

$pdo = db();

// 1. Check tables exist
echo "1. Checking tables...\n";
$tables = ['integration_jobs', 'integration_job_runs', 'lead_snapshots'];
foreach ($tables as $t) {
    $r = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='$t'")->fetch();
    echo "   $t: " . ($r ? "✓" : "✗") . "\n";
}

// 2. Check settings
echo "\n2. Checking settings...\n";
$workerEnabled = get_setting('integration_worker_enabled', '0');
echo "   integration_worker_enabled: $workerEnabled\n";

// 3. Create a test job directly (bypassing auth for testing)
echo "\n3. Creating test job...\n";
$jobId = bin2hex(random_bytes(16));
$stmt = $pdo->prepare("
    INSERT INTO integration_jobs 
    (id, forge_lead_id, op_lead_id, requested_by, modules_json, status, progress, correlation_id)
    VALUES (?, ?, ?, ?, ?, 'queued', 0, ?)
");
$stmt->execute([$jobId, 1, 'test-op-lead', 'test-user', '["maps","website"]', 'test-' . time()]);
echo "   Job ID: $jobId\n";

// 4. Create module runs
echo "\n4. Creating module runs...\n";
foreach (['maps', 'website'] as $module) {
    $runId = bin2hex(random_bytes(16));
    $stmt = $pdo->prepare("
        INSERT INTO integration_job_runs (id, job_id, module, status, attempt)
        VALUES (?, ?, ?, 'pending', 0)
    ");
    $stmt->execute([$runId, $jobId, $module]);
    echo "   $module run created\n";
}

// 5. Simulate job completion
echo "\n5. Simulating job completion...\n";
$pdo->exec("UPDATE integration_jobs SET status = 'success', progress = 100, finished_at = datetime('now') WHERE id = '$jobId'");
$pdo->exec("UPDATE integration_job_runs SET status = 'success', finished_at = datetime('now') WHERE job_id = '$jobId'");
echo "   Job marked as success\n";

// 6. Create test snapshot
echo "\n6. Creating test snapshot...\n";
$snapshotId = bin2hex(random_bytes(16));
$snapshotData = json_encode([
    'lead_id' => 1,
    'collected_at' => date('c'),
    'sources' => ['maps', 'website'],
    'maps' => [
        'name' => 'مطعم الاختبار',
        'category' => 'مطعم',
        'address' => 'الرياض، السعودية',
        'rating' => 4.5,
        'reviews_count' => 120,
        'phones' => ['0501234567'],
        'website' => 'https://example.com'
    ],
    'website_data' => [
        'title' => 'مطعم الاختبار - الموقع الرسمي',
        'emails' => ['info@example.com'],
        'social_links' => ['instagram' => 'https://instagram.com/test']
    ]
]);

$stmt = $pdo->prepare("
    INSERT INTO lead_snapshots (id, forge_lead_id, job_id, source, snapshot_json)
    VALUES (?, ?, ?, 'worker', ?)
");
$stmt->execute([$snapshotId, 1, $jobId, $snapshotData]);
echo "   Snapshot ID: $snapshotId\n";

// 7. Verify data
echo "\n7. Verifying data...\n";
$job = $pdo->query("SELECT * FROM integration_jobs WHERE id = '$jobId'")->fetch(PDO::FETCH_ASSOC);
echo "   Job status: {$job['status']}, progress: {$job['progress']}%\n";

$runs = $pdo->query("SELECT module, status FROM integration_job_runs WHERE job_id = '$jobId'")->fetchAll(PDO::FETCH_ASSOC);
foreach ($runs as $run) {
    echo "   Module {$run['module']}: {$run['status']}\n";
}

$snapshot = $pdo->query("SELECT * FROM lead_snapshots WHERE forge_lead_id = 1 ORDER BY created_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$snapData = json_decode($snapshot['snapshot_json'], true);
echo "   Snapshot sources: " . implode(', ', $snapData['sources'] ?? []) . "\n";
echo "   Maps name: " . ($snapData['maps']['name'] ?? 'N/A') . "\n";

echo "\n=== Test Complete ===\n";
echo "\nYou can now test the API endpoints:\n";
echo "- GET http://localhost:8081/v1/api/integration/jobs/status.php?jobId=$jobId\n";
echo "- GET http://localhost:8081/v1/api/integration/leads/snapshot.php?forgeLeadId=1\n";
echo "\n(Note: These require a valid integration token)\n";
