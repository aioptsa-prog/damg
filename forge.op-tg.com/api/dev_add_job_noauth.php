<?php
require_once __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
if($_SERVER['REMOTE_ADDR']!=='127.0.0.1'){
  http_response_code(403); echo json_encode(['error'=>'local_only']); exit;
}
$pdo=db();
$q = trim($_GET['q'] ?? 'مطاعم');
$ll = trim($_GET['ll'] ?? '');
if($ll===''){
  $def = trim(get_setting('default_ll',''));
  $ll = $def!=='' ? $def : '24.7136,46.6753';
}
$r = max(1, (int)($_GET['r'] ?? get_setting('default_radius_km','5')));
$lang = get_setting('default_language','ar'); $region = get_setting('default_region','sa');
$requested_by = 1; // dev: admin seed
$role = 'admin';
$agent_id = null;
$target = isset($_GET['target']) ? max(1,(int)$_GET['target']) : null;
$stmt=$pdo->prepare("INSERT INTO internal_jobs(requested_by_user_id,role,agent_id,query,ll,radius_km,lang,region,status,target_count,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,'queued',?,datetime('now'),datetime('now'))");
$stmt->execute([$requested_by,$role,$agent_id,$q,$ll,$r,$lang,$region,$target]);
echo json_encode(['ok'=>true,'job_id'=>$pdo->lastInsertId()]);
