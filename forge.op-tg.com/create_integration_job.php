<?php
/**
 * Create Integration Job for Worker Testing
 */

require_once __DIR__ . '/bootstrap.php';

$pdo = db();

echo "Creating integration job for Worker...\n";
echo "======================================\n\n";

// Create a queued job
$jobId = bin2hex(random_bytes(16));
$forgeLeadId = 1;
$opLeadId = 'test-op-lead-' . time();
$modules = json_encode(['maps']);

// First, make sure we have a lead to work with
$lead = $pdo->query("SELECT * FROM leads WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
if (!$lead) {
    echo "Creating test lead...\n";
    $pdo->exec("INSERT INTO leads (id, name, phone, city, created_at) VALUES (1, 'مطعم الاختبار', '0501234567', 'الرياض', datetime('now'))");
    $lead = ['id' => 1, 'name' => 'مطعم الاختبار', 'city' => 'الرياض'];
}

echo "Lead: {$lead['name']} ({$lead['city']})\n\n";

// Create job
$stmt = $pdo->prepare("
    INSERT INTO integration_jobs 
    (id, forge_lead_id, op_lead_id, requested_by, modules_json, status, progress, correlation_id, created_at)
    VALUES (?, ?, ?, 'test-user', ?, 'queued', 0, ?, datetime('now'))
");
$stmt->execute([$jobId, $forgeLeadId, $opLeadId, $modules, 'test-' . time()]);

echo "Job ID: $jobId\n";
echo "Status: queued\n";
echo "Modules: maps\n\n";

// Create module run
$runId = bin2hex(random_bytes(16));
$stmt = $pdo->prepare("
    INSERT INTO integration_job_runs (id, job_id, module, status, attempt)
    VALUES (?, ?, 'maps', 'pending', 0)
");
$stmt->execute([$runId, $jobId]);

echo "Module run created.\n\n";

echo "Worker should pick this up and:\n";
echo "1. Open Chromium\n";
echo "2. Search Google Maps for: {$lead['name']} {$lead['city']}\n";
echo "3. Extract business data\n";
echo "4. Save snapshot\n\n";

echo "Check Worker logs for [INTEGRATION] messages.\n";
