<?php
require_once __DIR__ . '/config/db.php';

echo "=== Check Internal Jobs ===\n\n";

$pdo = db();

// Get all jobs
$jobs = $pdo->query("SELECT * FROM internal_jobs ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
echo "Jobs count: " . count($jobs) . "\n\n";

foreach ($jobs as $j) {
    echo "Job #{$j['id']}: {$j['query']} @ {$j['ll']}\n";
    echo "  Status: {$j['status']}\n";
    echo "  Target: {$j['target_count']}, Found: " . ($j['found_count'] ?? 0) . "\n";
    echo "  Queued: {$j['queued_at']}\n";
    echo "\n";
}

// Get campaigns
echo "\n=== User Campaigns ===\n";
$campaigns = $pdo->query("SELECT * FROM user_campaigns ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
foreach ($campaigns as $c) {
    echo "Campaign #{$c['id']}: {$c['name']} - Status: {$c['status']} - Job: " . ($c['internal_job_id'] ?? 'None') . "\n";
}

echo "\n=== Done ===\n";
