<?php
require_once __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
// Dev-only helper: add an internal job (local requests only)
if(($_SERVER['REMOTE_ADDR'] ?? '')!=='127.0.0.1'){
  http_response_code(403);
  echo json_encode(['error'=>'local_only']);
  exit;
}

$pdo = db();
$enabled = get_setting('internal_server_enabled','0')==='1';
if(!$enabled){
  http_response_code(409);
  echo json_encode(['error'=>'internal_disabled']);
  exit;
}

$q  = trim($_GET['q']  ?? '');
$ll = trim($_GET['ll'] ?? get_setting('default_ll',''));
$radius_km = max(1, intval($_GET['radius_km'] ?? get_setting('default_radius_km','25')));
$lang = get_setting('default_language','ar');
$region = get_setting('default_region','sa');
$target = isset($_GET['target']) ? max(1, intval($_GET['target'])) : null;

if($q===''){
  http_response_code(400);
  echo json_encode(['error'=>'missing_q']);
  exit;
}
if(!preg_match('/^-?\d+(?:\.\d+)?,\s*-?\d+(?:\.\d+)?$/', $ll)){
  http_response_code(400);
  echo json_encode(['error'=>'bad_ll_format']);
  exit;
}

// Pick any admin as the requester
$adminId = $pdo->query("SELECT id FROM users WHERE role='admin' ORDER BY id ASC LIMIT 1")->fetchColumn();
if(!$adminId){
  http_response_code(500);
  echo json_encode(['error'=>'no_admin_user']);
  exit;
}

$stmt=$pdo->prepare("INSERT INTO internal_jobs(requested_by_user_id,role,agent_id,query,ll,radius_km,lang,region,status,target_count,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?, 'queued', ?, datetime('now'), datetime('now'))");
$stmt->execute([$adminId,'admin',NULL,$q,$ll,$radius_km,$lang,$region,$target]);
$job_id = $pdo->lastInsertId();
echo json_encode(['ok'=>true,'job_id'=>intval($job_id),'q'=>$q,'ll'=>$ll,'target'=>$target]);
