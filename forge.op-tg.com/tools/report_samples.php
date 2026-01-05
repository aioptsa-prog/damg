<?php
require_once __DIR__ . '/../bootstrap.php';
$pdo = db();
$out = [];
$out['missing_icons'] = (int)$pdo->query("SELECT COUNT(*) FROM categories WHERE icon_type IS NULL OR icon_value IS NULL OR icon_type='none'")->fetchColumn();
$out['total_categories'] = (int)$pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
$out['top_levels'] = (int)$pdo->query("SELECT COUNT(*) FROM categories WHERE depth=0 AND parent_id IS NULL")->fetchColumn();
$st = $pdo->query("SELECT id, name, path, slug, depth FROM categories WHERE depth>=4 ORDER BY id LIMIT 5");
$out['deep_samples'] = $st->fetchAll(PDO::FETCH_ASSOC);
header('Content-Type: application/json');
echo json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),"\n";
