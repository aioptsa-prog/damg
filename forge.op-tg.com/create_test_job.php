<?php
/**
 * Create a Real Search Job
 */

require_once __DIR__ . '/config/db.php';

// Job configuration
$query = 'مطاعم'; // Search for restaurants
$location = 'الرياض'; // Riyadh
$radius_km = 5;
$target_count = 20; // Target 20 results

try {
    $pdo = db();

    echo "Creating a real search job...\n";
    echo "=============================\n\n";
    echo "Query: $query\n";
    echo "Location: $location\n";
    echo "Radius: {$radius_km}km\n";
    echo "Target: $target_count results\n\n";

    // Create job
    $stmt = $pdo->prepare("
        INSERT INTO jobs (
            user_id, query, location, radius_km, target_count,
            status, created_at, updated_at
        ) VALUES (
            1, ?, ?, ?, ?,
            'pending', datetime('now'), datetime('now')
        )
    ");

    $stmt->execute([$query, $location, $radius_km, $target_count]);
    $job_id = $pdo->lastInsertId();

    echo "✓ Job created successfully with ID: $job_id\n\n";

    // Display job details
    $stmt = $pdo->prepare("SELECT * FROM jobs WHERE id = ?");
    $stmt->execute([$job_id]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

    echo "Job Details:\n";
    echo "------------\n";
    foreach ($job as $key => $value) {
        echo sprintf("%-20s: %s\n", $key, $value ?? 'NULL');
    }

    echo "\n✓ Job is ready to be processed by worker!\n";
    echo "\nNext steps:\n";
    echo "1. Run PHP worker to process this job\n";
    echo "2. Worker will search Google Maps\n";
    echo "3. Results will be saved to 'leads' table\n";
    echo "4. Job status will update to 'completed'\n";

} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
