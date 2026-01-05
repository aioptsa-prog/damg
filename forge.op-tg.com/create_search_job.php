<?php
/**
 * Create a NEW search job for testing
 */
require_once __DIR__ . '/config/db.php';

$pdo = db();

echo "\n========================================\n";
echo "  Creating NEW Search Job\n";
echo "========================================\n\n";

// Job parameters - search for "كافيه" in Riyadh
$query = 'كافيه';
$ll = '24.7136,46.6753'; // Riyadh
$radius_km = 15;
$lang = 'ar';
$region = 'sa';
$target_count = 5; // Get 5 results for quick test

// Create job
$stmt = $pdo->prepare("
    INSERT INTO internal_jobs (
        requested_by_user_id, role, query, ll, radius_km, 
        lang, region, status, target_count, queued_at,
        created_at, updated_at
    ) VALUES (
        1, 'agent', ?, ?, ?, ?, ?, 'queued', ?, datetime('now'),
        datetime('now'), datetime('now')
    )
");

$stmt->execute([$query, $ll, $radius_km, $lang, $region, $target_count]);
$job_id = $pdo->lastInsertId();

echo "✓ Job Created Successfully!\n\n";
echo "Job ID: $job_id\n";
echo "Query: $query\n";
echo "Location: Riyadh ($ll)\n";
echo "Target: $target_count results\n";
echo "Status: queued\n\n";

// Count current leads
$leadCount = $pdo->query("SELECT COUNT(*) FROM leads")->fetchColumn();
echo "Current total leads in DB: $leadCount\n\n";

echo "========================================\n";
echo "  Worker will pick up this job!\n";
echo "========================================\n";
echo "\nMonitor at: http://127.0.0.1:4499/status\n";
echo "Results at: http://localhost:8080/admin/leads.php\n\n";
