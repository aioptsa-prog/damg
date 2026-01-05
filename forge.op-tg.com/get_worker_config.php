<?php
require_once __DIR__ . '/config/db.php';

$pdo = db();

// Get or create internal_secret
$secret = $pdo->query("SELECT value FROM settings WHERE key='internal_secret'")->fetchColumn();

if (!$secret) {
    // Generate one
    $secret = bin2hex(random_bytes(32));
    $pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('internal_secret', '$secret')");
    echo "✓ Generated new INTERNAL_SECRET\n";
} else {
    echo "✓ Found existing INTERNAL_SECRET\n";
}

echo "\nWorker Configuration:\n";
echo "====================\n";
echo "BASE_URL=http://localhost\n";
echo "INTERNAL_SECRET=$secret\n";
echo "WORKER_ID=wrk-test-01\n";
echo "PULL_INTERVAL_SEC=10\n";
echo "HEADLESS=false\n";
echo "MAX_PAGES=3\n";
echo "SCRAPE_UNTIL_END=true\n";
echo "\nCopy this to worker/.env file\n";
