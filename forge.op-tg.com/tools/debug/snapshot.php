<?php
require_once __DIR__ . '/../../bootstrap.php';
$pdo = db();
$rows1 = $pdo->query("SELECT COUNT(*) c FROM leads")->fetch(PDO::FETCH_ASSOC);
$rows2 = $pdo->query("SELECT COALESCE(version,'') AS version, COUNT(*) c FROM internal_workers GROUP BY version ORDER BY version")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['leads_count'=>(int)$rows1['c'], 'workers_versions'=>$rows2], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),"\n";
