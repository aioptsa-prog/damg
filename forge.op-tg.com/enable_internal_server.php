<?php
require_once __DIR__ . '/config/db.php';

$pdo = db();

// Enable internal server
$pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('internal_server_enabled', '1')");

echo "✓ Internal server enabled\n";

// Show current settings
$settings = $pdo->query("
    SELECT key, value 
    FROM settings 
    WHERE key IN ('internal_server_enabled', 'internal_secret')
")->fetchAll(PDO::FETCH_ASSOC);

echo "\nCurrent Settings:\n";
foreach ($settings as $s) {
    echo "  {$s['key']}: {$s['value']}\n";
}
