<?php
/**
 * Create Internal Job for Real Worker
 */

require_once __DIR__ . '/config/db.php';

$pdo = db();

echo "\n========================================\n";
echo "  Creating Internal Job for Worker\n";
echo "========================================\n\n";

// Job parameters
$query = 'مطعم';
$ll = '24.7136,46.6753'; // Riyadh coordinates
$radius_km = 10;
$lang = 'ar';
$region = 'sa';
$requested_by_user_id = 1;

//Create internal job
$stmt = $pdo->prepare("
    INSERT INTO internal_jobs (
        requested_by_user_id,
        role,
        query,
        ll,
        radius_km,
        lang,
        region,
        status,
        created_at,
        updated_at,
        target_count,
        queued_at
    ) VALUES (
        ?, 'agent', ?, ?, ?, ?, ?, 'queued', 
        datetime('now'), datetime('now'), 10, datetime('now')
    )
");

$stmt->execute([$requested_by_user_id, $query, $ll, $radius_km, $lang, $region]);
$job_id = $pdo->lastInsertId();

echo "✓ Internal Job Created Successfully!\n\n";
echo "Job Details:\n";
echo "============\n";
echo "ID: $job_id\n";
echo "Query: $query\n";
echo "Location: $ll (Riyadh)\n";
echo "Radius: {$radius_km}km\n";
echo "Target: 10 results\n";
echo "Status: queued\n\n";

// Show job in database
$job = $pdo->query("SELECT * FROM internal_jobs WHERE id = $job_id")->fetch(PDO::FETCH_ASSOC);

echo "Full Job Record:\n";
echo "================\n";
foreach ($job as $key => $value) {
    printf("%-25s: %s\n", $key, $value ?? 'NULL');
}

echo "\n========================================\n";
echo "  Job Ready for Worker!\n";
echo "========================================\n\n";

echo "Next Steps:\n";
echo "-----------\n";
echo "1. Start Worker with: npm start (in worker directory)\n";
echo "2. Or run: node index.js\n";
echo "3. Worker will pull this job automatically\n";
echo "4. Results will be saved to 'leads' table\n\n";
