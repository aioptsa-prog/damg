<?php
require_once __DIR__ . '/config/db.php';

echo "=== Check Campaigns ===\n\n";

$pdo = db();

// Get all campaigns
$campaigns = $pdo->query("SELECT * FROM whatsapp_bulk_campaigns ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
echo "Campaigns count: " . count($campaigns) . "\n\n";

foreach ($campaigns as $c) {
    echo "Campaign #{$c['id']}: {$c['name']}\n";
    echo "  Status: {$c['status']}\n";
    echo "  Total: {$c['total_count']}, Sent: {$c['sent_count']}, Failed: {$c['failed_count']}\n";
    echo "  Created: {$c['created_at']}\n";

    // Get queue items for this campaign
    $stmt = $pdo->prepare("SELECT status, COUNT(*) as cnt FROM whatsapp_queue WHERE campaign_id = ? GROUP BY status");
    $stmt->execute([$c['id']]);
    $queue = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "  Queue: ";
    foreach ($queue as $q) {
        echo "{$q['status']}: {$q['cnt']} ";
    }
    echo "\n\n";
}

echo "=== Done ===\n";
