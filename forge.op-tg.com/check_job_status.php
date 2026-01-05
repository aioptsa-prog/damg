<?php
require_once __DIR__ . '/bootstrap.php';

$pdo = db();

// Check latest job
$job = $pdo->query("SELECT * FROM integration_jobs ORDER BY created_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
echo "Latest Job:\n";
echo "  ID: {$job['id']}\n";
echo "  Status: {$job['status']}\n";
echo "  Modules: {$job['modules_json']}\n";
echo "  Created: {$job['created_at']}\n";

// Check runs
echo "\nModule Runs:\n";
$runs = $pdo->prepare("SELECT * FROM integration_job_runs WHERE job_id = ?");
$runs->execute([$job['id']]);
foreach ($runs->fetchAll(PDO::FETCH_ASSOC) as $run) {
    echo "  - {$run['module']}: {$run['status']}";
    if ($run['error_code']) echo " (error: {$run['error_code']})";
    echo "\n";
}

// Check latest snapshot
echo "\nLatest Snapshot:\n";
$snap = $pdo->query("SELECT * FROM lead_snapshots ORDER BY created_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($snap) {
    echo "  ID: {$snap['id']}\n";
    echo "  Lead: {$snap['forge_lead_id']}\n";
    echo "  Created: {$snap['created_at']}\n";
    $data = json_decode($snap['snapshot_json'], true);
    echo "  Sources: " . implode(', ', $data['sources'] ?? []) . "\n";
    echo "  Has AI Pack: " . (isset($data['ai_pack']) ? 'YES' : 'NO') . "\n";
    if (isset($data['ai_pack'])) {
        echo "  Evidence count: " . count($data['ai_pack']['evidence'] ?? []) . "\n";
    }
}
