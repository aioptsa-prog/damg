<?php
/**
 * Simple Job Worker - Processes pending jobs
 */

require_once __DIR__ . '/config/db.php';

echo "Starting Job Worker...\n";
echo "=====================\n\n";

function process_job($pdo, $job)
{
    echo "Processing Job #{$job['id']}: {$job['query']} in {$job['location']}\n";

    // Update job status to 'running'
    $pdo->prepare("
        UPDATE jobs 
        SET status = 'running', 
            started_at = datetime('now'),
            updated_at = datetime('now')
        WHERE id = ?
    ")->execute([$job['id']]);

    echo "  Status: Running\n";

    // Simulate Google Maps search
    echo "  Searching Google Maps...\n";

    // Mock data - في التطبيق الحقيقي، هنا يتم البحث في Google Maps
    $mock_results = [
        [
            'name' => 'مطعم البيك',
            'company' => 'البيك',
            'phone' => '0112345678',
            'city' => 'الرياض',
            'district' => 'العليا',
            'rating' => 4.5,
            'lat' => 24.7136,
            'lng' => 46.6753
        ],
        [
            'name' => 'مطعم الرومانسية',
            'company' => 'الرومانسية',
            'phone' => '0112345679',
            'city' => 'الرياض',
            'district' => 'الملز',
            'rating' => 4.2,
            'lat' => 24.6748,
            'lng' => 46.7229
        ],
        [
            'name' => 'مطعم هرفي',
            'company' => 'هرفي',
            'phone' => '0112345680',
            'city' => 'الرياض',
            'district' => 'السليمانية',
            'rating' => 4.0,
            'lat' => 24.6954,
            'lng' => 46.6852
        ]
    ];

    $saved_count = 0;
    $found_count = count($mock_results);

    echo "  Found: $found_count results\n";
    echo "  Saving to database...\n";

    foreach ($mock_results as $result) {
        try {
            // Insert lead
            $stmt = $pdo->prepare("
                INSERT INTO leads (
                    name, company, phone, city, district, 
                    rating, lat, lon, source, job_id,
                    created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?, 
                    ?, ?, ?, 'google_maps_worker', ?,
                    datetime('now'), datetime('now')
                )
            ");

            $stmt->execute([
                $result['name'],
                $result['company'],
                $result['phone'],
                $result['city'],
                $result['district'],
                $result['rating'],
                $result['lat'],
                $result['lng'],
                $job['id']
            ]);

            $saved_count++;
            echo "    ✓ Saved: {$result['name']}\n";

        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'UNIQUE constraint failed') !== false) {
                echo "    - Skipped (duplicate): {$result['name']}\n";
            } else {
                throw $e;
            }
        }
    }

    // Update job to completed
    $progress = 100;
    $pdo->prepare("
        UPDATE jobs 
        SET status = 'completed',
            progress = ?,
            found_count = ?,
            saved_count = ?,
            completed_at = datetime('now'),
            updated_at = datetime('now')
        WHERE id = ?
    ")->execute([$progress, $found_count, $saved_count, $job['id']]);

    echo "\n✓ Job completed successfully!\n";
    echo "  Found: $found_count\n";
    echo "  Saved: $saved_count\n";
}

try {
    $pdo = db();

    // Find pending job
    $stmt = $pdo->query("
        SELECT * FROM jobs 
        WHERE status = 'pending'
        ORDER BY created_at ASC
        LIMIT 1
    ");

    $job = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$job) {
        echo "No pending jobs found.\n";
        exit(0);
    }

    process_job($pdo, $job);

    echo "\n=============================\n";
    echo "Worker finished successfully!\n";

} catch (Exception $e) {
    echo "\n✗ Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
