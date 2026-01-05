<?php
require_once __DIR__ . '/config/db.php';

$pdo = db();

echo "\n=========================================\n";
echo "  Job #142 Final Results\n";
echo "=========================================\n\n";

// Check job status
$job = $pdo->query("SELECT * FROM internal_jobs WHERE id=142")->fetch(PDO::FETCH_ASSOC);

echo "Job Status:\n";
echo "-----------\n";
echo "ID: {$job['id']}\n";
echo "Query: {$job['query']}\n";
echo "Status: {$job['status']}\n";
echo "Result Count: {$job['result_count']}\n";
echo "Started: {$job['claimed_at']}\n";
echo "Finished: {$job['finished_at']}\n";

// Get leads from this job
echo "\n\nLeads Found from Job #142:\n";
echo "===========================\n\n";

$leads = $pdo->query("
    SELECT id, name, phone, city, rating, created_at 
    FROM leads 
    WHERE source LIKE '%job%' OR created_at >= '{$job['claimed_at']}'
    ORDER BY created_at DESC 
    LIMIT 15
")->fetchAll(PDO::FETCH_ASSOC);

if (count($leads) > 0) {
    echo sprintf(
        "%-5s %-40s %-15s %-15s %-8s %s\n",
        "ID",
        "Name",
        "Phone",
        "City",
        "Rating",
        "Created"
    );
    echo str_repeat("-", 100) . "\n";

    foreach ($leads as $lead) {
        echo sprintf(
            "%-5s %-40s %-15s %-15s %-8s %s\n",
            $lead['id'],
            substr($lead['name'], 0, 38),
            $lead['phone'] ?: 'N/A',
            $lead['city'] ?: 'N/A',
            $lead['rating'] ?: 'N/A',
            $lead['created_at']
        );
    }

    echo "\n✓ Total leads shown: " . count($leads) . "\n";
} else {
    echo "No leads found.\n";
}

// Summary
echo "\n\n=========================================\n";
echo "  Summary\n";
echo "=========================================\n";
echo "Job: {$job['status']}\n";
echo "Results: {$job['result_count']}\n";
echo "Duration: " . (strtotime($job['finished_at']) - strtotime($job['claimed_at'])) . " seconds\n";
echo "\n✅ Worker test completed successfully!\n\n";
