<?php
require_once __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
$pdo = db();

// Latest seed.* action
$row = $pdo->query("SELECT json_extract(details,'$.batch_id') AS batch_id, action, MAX(id) AS last_id
        FROM category_activity_log
        WHERE action LIKE 'seed.%' AND json_extract(details,'$.batch_id') IS NOT NULL
        GROUP BY batch_id
        ORDER BY last_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$batchId = $row['batch_id'] ?? null;
$latestAction = $row['action'] ?? null;
$lastId = isset($row['last_id']) ? (int)$row['last_id'] : null;
$ts = null;
if($lastId){
  try{ $st=$pdo->prepare("SELECT created_at FROM category_activity_log WHERE id=?"); $st->execute([$lastId]); $ts = $st->fetchColumn(); }catch(Throwable $e){}
}

$totalCategories = (int)$pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
$totalKeywords = (int)$pdo->query("SELECT COUNT(*) FROM category_keywords")->fetchColumn();

echo json_encode([
  'batch_id' => $batchId,
  'latest_action' => $latestAction,
  'timestamp' => $ts,
  'total_categories' => $totalCategories,
  'total_keywords' => $totalKeywords,
], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), "\n";
