<?php
require_once __DIR__ . '/../bootstrap.php';
$pdo = db();
$updatedTop = $pdo->exec("UPDATE categories SET icon_type='fa', icon_value='fa-folder-tree' WHERE (icon_type IS NULL OR icon_value IS NULL OR icon_type='none') AND depth<=1");
$updatedAll = 0; // leave deeper nodes as-is per requirement focus on root and upper levels
$details = json_encode(['note'=>'fixed missing icons','updated_top_levels'=>$updatedTop,'updated_deeper'=>$updatedAll], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
try{
  $admin = $pdo->query("SELECT id FROM users WHERE role='admin' AND active=1 LIMIT 1")->fetchColumn();
  $uid = $admin ? (int)$admin : 0;
  $pdo->prepare("INSERT INTO category_activity_log(action,user_id,details,created_at) VALUES('icon.fix_missing', ?, ?, datetime('now'))")
      ->execute([$uid, $details]);
}catch(Throwable $e){}
echo "OK updated_top={$updatedTop}\n";
