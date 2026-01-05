<?php
require 'config/db.php';

try {
    $st = db()->query('SELECT COUNT(*) as cnt FROM leads');
    $r = $st->fetch();
    echo 'Total leads: ' . $r['cnt'] . PHP_EOL;

    // Show first 3 leads
    $st2 = db()->query('SELECT id, name, phone FROM leads LIMIT 3');
    echo "\nFirst 3 leads:\n";
    while ($lead = $st2->fetch()) {
        echo "- ID: {$lead['id']}, Name: {$lead['name']}, Phone: {$lead['phone']}\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . PHP_EOL;
}
