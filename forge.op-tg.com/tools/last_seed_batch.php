<?php
require_once __DIR__ . '/../bootstrap.php';
$pdo = db();
$sql = "SELECT json_extract(details,'$.batch_id') AS batch_id, action, MAX(id) as last_id
        FROM category_activity_log
        WHERE action LIKE 'seed.%' AND json_extract(details,'$.batch_id') IS NOT NULL
        GROUP BY batch_id
        ORDER BY last_id DESC LIMIT 1";
$row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
header('Content-Type: application/json');
echo json_encode($row, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),"\n";
