<?php
// Simple debug test - NO bootstrap to avoid header issues
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "Testing Leads API - Step by Step\n";
echo "==================================\n\n";

// Step 1: Load database
require_once __DIR__ . '/../../config/db.php';
echo "✓ Database loaded\n";

$pdo = db();
echo "✓ PDO connection established\n\n";

// Step 2: Check tables exist
echo "Checking tables...\n";
$tables = ['leads', 'categories', 'geo_cities', 'geo_districts'];
foreach ($tables as $table) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM $table");
        $count = $stmt->fetchColumn();
        echo "  ✓ $table: $count records\n";
    } catch (Exception $e) {
        echo "  ✗ $table: ERROR - " . $e->getMessage() . "\n";
    }
}

echo "\n";

// Step 3: Test the exact query from leads/index.php
echo "Testing leads query...\n";
try {
    $sql = "
        SELECT 
            l.id,
            l.phone,
            l.phone_norm,
            l.name,
            l.city,
            l.country,
            l.category_id,
            c.name as category_name,
            c.slug as category_slug,
            l.geo_city_id,
            gc.name_ar as city_name,
            l.geo_district_id,
            gd.name_ar as district_name,
            l.rating,
            l.website,
            l.email,
            l.lat,
            l.lon,
            l.source,
            l.created_at,
            l.created_by_user_id
        FROM leads l
        LEFT JOIN categories c ON l.category_id = c.id
        LEFT JOIN geo_cities gc ON l.geo_city_id = gc.id
        LEFT JOIN geo_districts gd ON l.geo_district_id = gd.id
        WHERE 1=1
        ORDER BY l.created_at DESC
        LIMIT 5
    ";

    $stmt = $pdo->query($sql);
    $leads = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "  ✓ Query executed successfully\n";
    echo "  ✓ Found " . count($leads) . " leads\n\n";

    if (count($leads) > 0) {
        echo "Sample lead:\n";
        print_r($leads[0]);
    }

} catch (Exception $e) {
    echo "  ✗ Query failed: " . $e->getMessage() . "\n";
    echo "  File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}
