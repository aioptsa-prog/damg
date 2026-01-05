<?php
// CLI-only: enqueue a sample internal job safely. Not accessible over web server.
if (php_sapi_name() !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }
require_once __DIR__ . '/../../bootstrap.php';

function parse_args($argv){
  $out = ['type'=>null,'payload'=>null];
  for($i=1;$i<count($argv);$i++){
    $a=$argv[$i];
    if($a==='-t' || $a==='--type'){ $out['type'] = $argv[++$i] ?? null; continue; }
    if($a==='-p' || $a==='--payload'){ $out['payload'] = $argv[++$i] ?? null; continue; }
  }
  return $out;
}

try{
  $args = parse_args($argv);
  $type = $args['type'] ?: 'places_api_search';
  $payload = $args['payload'] ? json_decode($args['payload'], true) : null;
  if(!$payload || !is_array($payload)){
    $payload = [ 'query'=>'demo', 'll'=>get_setting('default_ll','24.7136,46.6753'), 'radius_km'=>5, 'lang'=>get_setting('default_language','ar'), 'region'=>get_setting('default_region','sa'), 'target'=>1 ];
  }
  $pdo = db();
  // Ensure internal server toggled for smoke
  if(get_setting('internal_server_enabled','')!=='1') set_setting('internal_server_enabled','1');
  if(get_setting('internal_secret','')==='') set_setting('internal_secret','testsecret');

  // Find creator
  $adminId = (int)($pdo->query("SELECT id FROM users WHERE role='admin' ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 0);
  if(!$adminId){
    $st=$pdo->prepare("INSERT INTO users(mobile,name,role,password_hash,created_at) VALUES(?,?,?,?,datetime('now'))");
    $st->execute(['0599999999','Smoke Admin','admin', password_hash('x', PASSWORD_DEFAULT)]);
    $adminId = (int)$pdo->lastInsertId();
  }

  $q = (string)($payload['query'] ?? 'demo');
  $ll = (string)($payload['ll'] ?? get_setting('default_ll','24.7136,46.6753'));
  $rk = (int)($payload['radius_km'] ?? (int)get_setting('default_radius_km','10'));
  $lang = (string)($payload['lang'] ?? get_setting('default_language','ar'));
  $region = (string)($payload['region'] ?? get_setting('default_region','sa'));
  $target = (int)max(1, (int)($payload['target'] ?? 1));

  // Persist job_type and payload_json when available
  $cols = $pdo->query("PRAGMA table_info(internal_jobs)")->fetchAll(PDO::FETCH_ASSOC);
  $has = function($n) use ($cols){ foreach($cols as $c){ if(($c['name']??$c['Name']??'')===$n) return true; } return false; };
  if($has('job_type') && $has('payload_json')){
    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $st = $pdo->prepare("INSERT INTO internal_jobs(requested_by_user_id, role, agent_id, query, ll, radius_km, lang, region, status, target_count, attempts, next_retry_at, job_type, payload_json, created_at, updated_at)
      VALUES(?,?,?,?,?,?,?,?, 'queued', ?, 0, NULL, ?, ?, datetime('now'), datetime('now'))");
    $st->execute([$adminId, 'admin', null, $q, $ll, $rk, $lang, $region, $target, $type, $payloadJson]);
  } else {
    $st = $pdo->prepare("INSERT INTO internal_jobs(requested_by_user_id, role, agent_id, query, ll, radius_km, lang, region, status, target_count, attempts, next_retry_at, created_at, updated_at)
      VALUES(?,?,?,?,?,?,?,?, 'queued', ?, 0, NULL, datetime('now'), datetime('now'))");
    $st->execute([$adminId, 'admin', null, $q, $ll, $rk, $lang, $region, $target]);
  }
  $id = (int)$pdo->lastInsertId();
  echo json_encode(['ok'=>true,'job_id'=>$id,'type'=>$type,'payload'=>$payload], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit(0);
}catch(Throwable $e){
  fwrite(STDERR, $e->getMessage()."\n");
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
  exit(2);
}
