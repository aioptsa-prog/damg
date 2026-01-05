<?php
/**
 * Create and monitor enrichment job
 */
require_once __DIR__ . '/bootstrap.php';

$pdo = db();

// Get first lead
$lead = $pdo->query("SELECT id, name, phone, city FROM leads WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
if (!$lead) {
    die("No lead found\n");
}

echo "=== Creating Enrichment Job ===\n";
echo "Lead: {$lead['name']}\n\n";

// Create job
$jobId = bin2hex(random_bytes(16));
$modules = ['maps', 'website', 'google_web'];

$stmt = $pdo->prepare("
    INSERT INTO integration_jobs (id, forge_lead_id, op_lead_id, requested_by, modules_json, status)
    VALUES (?, ?, 'test-op-lead', 'test-user', ?, 'queued')
");
$stmt->execute([$jobId, $lead['id'], json_encode($modules)]);

// Create module runs
foreach ($modules as $mod) {
    $runId = bin2hex(random_bytes(16));
    $stmt = $pdo->prepare("
        INSERT INTO integration_job_runs (id, job_id, module, status)
        VALUES (?, ?, ?, 'pending')
    ");
    $stmt->execute([$runId, $jobId, $mod]);
}

echo "Job ID: $jobId\n";
echo "Modules: " . implode(', ', $modules) . "\n";
echo "Status: queued\n\n";

echo "Waiting for Worker to process...\n";
