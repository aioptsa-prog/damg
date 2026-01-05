<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/classify.php';
require_once __DIR__ . '/../lib/system.php';
header('Content-Type: application/json; charset=utf-8');

// Respect global stop/pause
if(system_is_globally_stopped() || system_is_in_pause_window()){
  http_response_code(503);
  echo json_encode(['error'=>'system_stopped_or_paused']);
  exit;
}

$secret = get_setting('maintenance_secret','');
$provided = $_GET['secret'] ?? ($_SERVER['HTTP_X_MAINTENANCE_SECRET'] ?? '');
if(!$secret || !$provided || !hash_equals($secret, $provided)){
  http_response_code(403);
  echo json_encode(['error'=>'forbidden']);
  exit;
}

$limit = max(1, min(2000, (int)($_GET['limit'] ?? (int)get_setting('reclassify_default_limit','200'))));
$onlyEmpty = (($_GET['only_empty'] ?? get_setting('reclassify_only_empty','1')) === '1');
$override = (($_GET['override'] ?? get_setting('reclassify_override','0')) === '1');

// Simple lock to avoid overlapping runs
$lockPath = __DIR__ . '/../storage/reclassify.lock';
@touch($lockPath);
$fh = @fopen($lockPath, 'c');
if(!$fh || !@flock($fh, LOCK_EX | LOCK_NB)){
  http_response_code(429);
  echo json_encode(['error'=>'busy']);
  exit;
}

try{
  $pdo = db();
  $where = $onlyEmpty ? 'WHERE category_id IS NULL' : '';
  $leads = $pdo->query("SELECT id, phone, name, city, country, website, email, gmap_types AS types, source_url, category_id FROM leads $where ORDER BY id DESC LIMIT ".$limit)->fetchAll();

  $upd = $pdo->prepare("UPDATE leads SET category_id=:cid WHERE id=:id");
  $updated=0; $processed=0; $skipped=0;
  foreach($leads as $L){
    $processed++;
    $cls = classify_lead([
      'name'=>$L['name'] ?? '',
      'gmap_types'=>$L['types'] ?? '',
      'website'=>$L['website'] ?? '',
      'email'=>$L['email'] ?? '',
      'source_url'=>$L['source_url'] ?? '',
      'city'=>$L['city'] ?? '',
      'country'=>$L['country'] ?? '',
      'phone'=>$L['phone'] ?? '',
    ]);
    $cid = $cls['category_id'] ?? null;
    if($cid && ($override || empty($L['category_id']))){
      $upd->execute([':cid'=>$cid, ':id'=>$L['id']]);
      $updated++;
    } else {
      $skipped++;
    }
  }
  $remaining = null;
  if($onlyEmpty){
    $remaining = (int)$pdo->query("SELECT COUNT(*) c FROM leads WHERE category_id IS NULL")->fetch()['c'];
  }
  echo json_encode(['ok'=>true,'processed'=>$processed,'updated'=>$updated,'skipped'=>$skipped,'remaining'=>$remaining]);
} finally {
  if($fh){ @flock($fh, LOCK_UN); @fclose($fh); }
}