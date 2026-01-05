<?php
require_once __DIR__ . '/../bootstrap.php';
$pdo = db();
$admin = $pdo->query("SELECT id FROM users WHERE role='admin' AND active=1 LIMIT 1")->fetchColumn();
if(!$admin){ echo "NO_ADMIN\n"; exit(0); }
$updated = $pdo->exec("UPDATE categories SET created_by_user_id=".(int)$admin." WHERE COALESCE(created_by_user_id,0)=0");
$details = json_encode(['note'=>'assigned created_by_user_id to admin','affected'=>$updated], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$st = $pdo->prepare("INSERT INTO category_activity_log(action,user_id,details,created_at) VALUES('seed.assign_creator', ?, ?, datetime('now'))");
$st->execute([(int)$admin, $details]);
echo "OK updated={$updated}\n";
