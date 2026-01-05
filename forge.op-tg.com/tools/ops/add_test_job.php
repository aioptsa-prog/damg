<?php
// Inserts a small demo job into internal_jobs for a live worker test
require_once __DIR__ . '/../../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

try{
  $pdo = db();
  // Ensure defaults
  if(get_setting('internal_server_enabled','')!=='1') set_setting('internal_server_enabled','1');
  if(get_setting('internal_secret','')==='') set_setting('internal_secret','testsecret');
  if(get_setting('default_ll','')==='') set_setting('default_ll','24.7136,46.6753');
  if(get_setting('default_language','')==='') set_setting('default_language','ar');
  if(get_setting('default_region','')==='') set_setting('default_region','sa');

  // Ensure an admin user exists (pick first or create)
  $adminId = (int)($pdo->query("SELECT id FROM users WHERE role='admin' ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 0);
  if(!$adminId){
    $st=$pdo->prepare("INSERT INTO users(mobile,name,role,password_hash,created_at) VALUES(?,?,?,?,datetime('now'))");
    $st->execute(['0599999999','Demo Admin','admin', password_hash('x', PASSWORD_DEFAULT)]);
    $adminId = (int)$pdo->lastInsertId();
  }

  $q  = isset($_GET['q']) ? trim($_GET['q']) : 'demo';
  $ll = isset($_GET['ll']) ? trim($_GET['ll']) : get_setting('default_ll','24.7136,46.6753');
  $radius_km = isset($_GET['r']) ? max(1,(int)$_GET['r']) : (int)(get_setting('default_radius_km','10'));
  $lang = get_setting('default_language','ar');
  $region = get_setting('default_region','sa');
  $target = isset($_GET['target']) ? max(1,(int)$_GET['target']) : 2;

  $ins = $pdo->prepare("INSERT INTO internal_jobs(requested_by_user_id, role, agent_id, query, ll, radius_km, lang, region, status, target_count, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?, 'queued', ?, datetime('now'), datetime('now'))");
  $ins->execute([$adminId,'admin', null, $q, $ll, $radius_km, $lang, $region, $target]);
  echo json_encode(['ok'=>true,'job_id'=>(int)$pdo->lastInsertId(),'q'=>$q,'ll'=>$ll,'target'=>$target], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){ http_response_code(500); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
