<?php
require_once __DIR__ . '/../../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
$pdo = db();

// Top 5 deepest categories by depth (tie-breaker: longest path length)
$rows = $pdo->query("SELECT id, COALESCE(path,name) AS path, COALESCE(depth,0) AS depth FROM categories ORDER BY depth DESC, LENGTH(COALESCE(path,name)) DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

// Count missing icons on top-level (depth=0) nodes
$missing = (int)$pdo->query("SELECT COUNT(*) FROM categories WHERE COALESCE(depth,0)=0 AND (icon_type IS NULL OR icon_value IS NULL OR icon_value='')")->fetchColumn();

// Totals
$totC = (int)$pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
$totK = 0; try{ $totK = (int)$pdo->query("SELECT COUNT(*) FROM category_keywords")->fetchColumn(); }catch(Throwable $e){}

echo json_encode([
  'top5_deep_paths' => array_map(function($r){ return ['id'=>(int)$r['id'], 'path'=>$r['path'], 'depth'=>(int)$r['depth']]; }, $rows),
  'top_level_missing_icons' => $missing,
  'totals' => ['categories'=>$totC, 'keywords'=>$totK],
], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT), "\n";
