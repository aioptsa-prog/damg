<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/system.php';
require_once __DIR__ . '/../lib/geo.php';
header('Content-Type: application/json; charset=utf-8');

$u = current_user();
if(!$u || $u['role']!=='admin'){ http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }

$pdo = db();
$now = date('Y-m-d H:i:s');
$twoMinAgo = date('Y-m-d H:i:s', time()-workers_online_window_sec());
$stats = [];

$stats['system'] = [
  'stopped' => system_is_globally_stopped(),
  'pause_active' => system_is_in_pause_window(),
  'pick_order' => get_setting('job_pick_order','fifo'),
];

$stats['jobs'] = [
  'queued' => (int)$pdo->query("SELECT COUNT(*) c FROM internal_jobs WHERE status='queued'")->fetch()['c'],
  'processing' => (int)$pdo->query("SELECT COUNT(*) c FROM internal_jobs WHERE status='processing'")->fetch()['c'],
  'expired' => (int)$pdo->query("SELECT COUNT(*) c FROM internal_jobs WHERE status='processing' AND (lease_expires_at IS NULL OR lease_expires_at < datetime('now'))")->fetch()['c'],
  'done24h' => (int)$pdo->query("SELECT COUNT(*) c FROM internal_jobs WHERE status='done' AND finished_at >= datetime('now','-1 day')")->fetch()['c'],
  'requeue24h' => (int)$pdo->query("SELECT COUNT(*) c FROM job_attempts WHERE success=0 AND log_excerpt='requeue_offline' AND finished_at >= datetime('now','-1 day')")->fetch()['c'],
];

$stats['workers'] = [
  'online' => workers_online_count(false),
  'total' => (int)$pdo->query("SELECT COUNT(*) c FROM internal_workers")->fetch()['c'],
  'list' => $pdo->query("SELECT worker_id, last_seen, info FROM internal_workers ORDER BY last_seen DESC LIMIT 50")->fetchAll(),
];

// Simple ingestion metrics (last 24h): added vs duplicates ratio
try{
  $d = date('Y-m-d');
  $q = $pdo->prepare("SELECT kind, SUM(count) cnt FROM usage_counters WHERE day >= date('now','-1 day') AND kind IN ('ingest_added','ingest_duplicates') GROUP BY kind");
  $q->execute();
  $rows = $q->fetchAll();
  $m = ['added'=>0,'duplicates'=>0,'dup_ratio'=>0.0];
  foreach($rows as $r){ if($r['kind']==='ingest_added') $m['added']=(int)$r['cnt']; if($r['kind']==='ingest_duplicates') $m['duplicates']=(int)$r['cnt']; }
  $den = max(1, $m['added'] + $m['duplicates']);
  $m['dup_ratio'] = round(($m['duplicates'] / $den) * 100, 1);
  $stats['ingest'] = $m;
  // 7-day trend
  $trend = [];
  for($i=6; $i>=0; $i--){
    $day = date('Y-m-d', strtotime("-{$i} day"));
    $st = $pdo->prepare("SELECT kind, SUM(count) cnt FROM usage_counters WHERE day=? AND kind IN ('ingest_added','ingest_duplicates') GROUP BY kind");
    $st->execute([$day]);
    $r2 = $st->fetchAll();
    $added=0; $dups=0; foreach($r2 as $row){ if($row['kind']==='ingest_added') $added=(int)$row['cnt']; if($row['kind']==='ingest_duplicates') $dups=(int)$row['cnt']; }
    $den2 = max(1, $added + $dups);
    $trend[] = ['day'=>$day, 'added'=>$added, 'duplicates'=>$dups, 'dup_ratio'=>round(($dups/$den2)*100,1)];
  }
  $stats['ingest_trend'] = $trend;
}catch(Throwable $e){ $stats['ingest'] = ['added'=>0,'duplicates'=>0,'dup_ratio'=>0.0]; }

// Detect stuck processing beyond threshold while worker appears online
try{
  $thrMin = (int)get_setting('stuck_processing_threshold_min','10');
  $thr = max(5, min(180, $thrMin));
  $stuck = $pdo->prepare("SELECT j.id, j.worker_id, j.last_progress_at, j.lease_expires_at
    FROM internal_jobs j
    WHERE j.status='processing'
      AND (j.last_progress_at IS NULL OR j.last_progress_at < datetime('now', :neg))
      AND j.worker_id IS NOT NULL
      AND EXISTS (SELECT 1 FROM internal_workers w WHERE w.worker_id=j.worker_id AND w.last_seen >= datetime('now', :win))");
  $neg = '-' . $thr . ' minutes'; $win = '-' . ceil(workers_online_window_sec()/60) . ' minutes';
  $stuck->execute([':neg'=>$neg, ':win'=>$win]);
  $stats['jobs_stuck'] = $stuck->fetchAll();
  $stats['stuck_threshold_min'] = $thr;
}catch(Throwable $e){ $stats['jobs_stuck'] = []; }

// Aggregation: top cities by leads count (all time). Uses geo_city_id mapping to SA geo database.
try{
  $top = $pdo->query("SELECT geo_city_id cid, COUNT(*) cnt FROM leads WHERE geo_city_id IS NOT NULL GROUP BY geo_city_id ORDER BY cnt DESC LIMIT 10")->fetchAll();
  $out=[]; if($top){
    $gdb = geo_db('SA');
    $stmt = $gdb->prepare("SELECT id, name_ar, region_code FROM cities WHERE id=?");
    foreach($top as $row){
      $cid = (int)$row['cid']; $cnt=(int)$row['cnt'];
      $stmt->execute([$cid]); $city=$stmt->fetch();
      if($city){ $out[] = ['city_id'=>$cid, 'name_ar'=>$city['name_ar'], 'region_code'=>$city['region_code'], 'count'=>$cnt]; }
      else { $out[] = ['city_id'=>$cid, 'name_ar'=>null, 'region_code'=>null, 'count'=>$cnt]; }
    }
  }
  $stats['top_cities'] = $out;
}catch(Throwable $e){ $stats['top_cities'] = []; }

echo json_encode(['ok'=>true, 'now'=>$now, 'stats'=>$stats], JSON_UNESCAPED_UNICODE);
exit;
?>
