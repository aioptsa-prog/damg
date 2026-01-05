<?php
/**
 * Direct Job Processing - Updated with correct schema
 */

require_once __DIR__ . '/config/db.php';

try {
    $pdo = db();

    echo "\n=================================\n";
    echo "  Job Processing Demo\n";
    echo "=================================\n\n";

    // Reset job to pending (if needed)
    $pdo->exec("UPDATE jobs SET status='pending', started_at=NULL WHERE id=1");

    // Get job
    $job = $pdo->query("SELECT * FROM jobs WHERE id=1")->fetch(PDO::FETCH_ASSOC);

    echo "Job #{$job['id']}: {$job['query']} in {$job['location']}\n\n";

    // Start processing
    echo "[1] Starting job...\n";
    $pdo->exec("UPDATE jobs SET status='running', started_at=datetime('now') WHERE id=1");

    // Mock search results (matching actual schema)
    echo "[2] Searching Google Maps...\n";
    $results = [
        [
            'name' => 'مطعم البيك - فرع العليا',
            'phone' => '0112345678',
            'city' => 'الرياض',
            'country' => 'SA',
            'rating' => 4.5,
            'lat' => 24.7136,
            'lon' => 46.6753,
            'website' => 'https://albaik.com'
        ],
        [
            'name' => 'مطعم الرومانسية',
            'phone' => '0112345679',
            'city' => 'الرياض',
            'country' => 'SA',
            'rating' => 4.2,
            'lat' => 24.6748,
            'lon' => 46.7229,
            'website' => null
        ],
        [
            'name' => 'مطعم هرفي - فرع السليمانية',
            'phone' => '0112345680',
            'city' => 'الرياض',
            'country' => 'SA',
            'rating' => 4.0,
            'lat' => 24.6954,
            'lon' => 46.6852,
            'website' => 'https://herfy.com'
        ],
    ];

    echo "    ✓ Found " . count($results) . " results\n\n";

    // Save results
    echo "[3] Saving to database...\n";
    $saved = 0;
    foreach ($results as $r) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO leads (
                    name, phone, city, country, rating, lat, lon, 
                    website, source, job_id, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'google_maps_test', ?, datetime('now'))
            ");

            $stmt->execute([
                $r['name'],
                $r['phone'],
                $r['city'],
                $r['country'],
                $r['rating'],
                $r['lat'],
                $r['lon'],
                $r['website'],
                $job['id']
            ]);

            echo "    ✓ Saved: {$r['name']}\n";
            $saved++;
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'UNIQUE') !== false) {
                echo "    - Skipped (duplicate phone): {$r['name']}\n";
            } else {
                throw $e;
            }
        }
    }

    // Complete job
    echo "\n[4] Completing job...\n";
    $pdo->exec("
        UPDATE jobs 
        SET status='completed', 
            progress=100, 
            found_count=" . count($results) . ", 
            saved_count=$saved, 
            completed_at=datetime('now')
        WHERE id=1
    ");

    echo "    ✓ Job completed!\n\n";

    // Show results
    echo "=================================\n";
    echo "  Results Summary\n";
    echo "=================================\n\n";
    echo "Found: " . count($results) . " leads\n";
    echo "Saved: $saved new leads\n\n";

    // Show all leads from this job
    echo "Leads from Job #1:\n";
    echo "------------------\n";
    $leads = $pdo->query("
        SELECT id, name, phone, city, rating, created_at 
        FROM leads 
        WHERE job_id=1 
        ORDER BY id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (count($leads) > 0) {
        foreach ($leads as $lead) {
            echo sprintf(
                "#%-4d %-35s %s (Rating: %.1f) - %s\n",
                $lead['id'],
                $lead['name'],
                $lead['phone'],
                $lead['rating'],
                $lead['city']
            );
        }
    } else {
        echo "No leads found.\n";
    }

    echo "\n✅ Success! Job completed and data saved to database.\n\n";

} catch (Exception $e) {
    echo "\n✗ Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
