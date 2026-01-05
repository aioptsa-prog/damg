<?php
require_once __DIR__ . '/config/db.php';
$pdo = db();
$t1 = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='whatsapp_bulk_campaigns'")->fetch();
$t2 = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='whatsapp_queue'")->fetch();
echo 'Campaigns: ' . ($t1 ? 'OK' : 'MISSING') . PHP_EOL;
echo 'Queue: ' . ($t2 ? 'OK' : 'MISSING') . PHP_EOL;
