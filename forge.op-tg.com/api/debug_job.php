<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/system.php';
$u = current_user(); if(!$u || $u['role']!=='admin'){ http_response_code(403); header('Content-Type: application/json'); echo json_encode(['error'=>'forbidden']); exit; }
header('Content-Type: application/json; charset=utf-8');
$id = isset($_GET['id'])? (int)$_GET['id'] : 0;
if(!$id){ echo json_encode(['error'=>'missing_id']); exit; }
$pdo = db();
$st = $pdo->prepare("SELECT id,status,worker_id,claimed_at,lease_expires_at,progress_count,result_count,last_cursor,last_error,done_reason,updated_at FROM internal_jobs WHERE id=?");
$st->execute([$id]);
$job = $st->fetch(PDO::FETCH_ASSOC);
if(!$job){ echo json_encode(['error'=>'not_found']); exit; }
$wid = $job['worker_id'] ?? null; $worker=null;
if($wid){ $sw = $pdo->prepare("SELECT worker_id,last_seen,info FROM internal_workers WHERE worker_id=?"); $sw->execute([$wid]); $worker = $sw->fetch(PDO::FETCH_ASSOC) ?: null; }
echo json_encode(['ok'=>true,'job'=>$job,'worker'=>$worker], JSON_UNESCAPED_UNICODE);
exit;
?>