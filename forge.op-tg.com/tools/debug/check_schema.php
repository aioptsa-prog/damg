<?php
require_once __DIR__ . '/../../bootstrap.php';
$pdo = db();
$rows = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('rate_limit','auth_attempts','hmac_replay') ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),"\n";
