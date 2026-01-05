<?php
require_once __DIR__ . '/bootstrap.php';

$pdo = db();

$snap = $pdo->query("SELECT * FROM lead_snapshots ORDER BY created_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($snap) {
    echo "Snapshot ID: {$snap['id']}\n";
    echo "Lead ID: {$snap['forge_lead_id']}\n";
    echo "Created: {$snap['created_at']}\n\n";
    
    $data = json_decode($snap['snapshot_json'], true);
    echo "Full Snapshot Data:\n";
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
