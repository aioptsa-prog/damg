<?php
require_once __DIR__ . '/../bootstrap.php';
$pdo = db();
$cid1 = (int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
$cid2 = (int)$pdo->query('SELECT id FROM categories ORDER BY id DESC LIMIT 1')->fetchColumn();
$ph = '9999999990';
$adminId = (int)$pdo->query("SELECT id FROM users WHERE role='admin' AND active=1 LIMIT 1")->fetchColumn();
$stmt = $pdo->prepare("INSERT INTO leads(phone,phone_norm,name,city,country,created_at,source,created_by_user_id,category_id) VALUES(?,?,?,?,?,?,?,?,?)");
$stmt->execute([$ph,$ph,'Test','Riyadh','SA',date('c'),'internal',$adminId,$cid1]);
$pdo->prepare("UPDATE leads SET category_id=COALESCE(category_id, :cid) WHERE phone_norm=:phn OR phone=:ph")
    ->execute([':cid'=>$cid2, ':phn'=>$ph, ':ph'=>$ph]);
$final = (int)$pdo->query("SELECT category_id FROM leads WHERE phone='".$ph."'")->fetchColumn();
// Cleanup
$pdo->prepare('DELETE FROM leads WHERE phone=?')->execute([$ph]);
header('Content-Type: application/json');
echo json_encode(['cid1'=>$cid1,'cid2'=>$cid2,'final'=>$final], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),"\n";
