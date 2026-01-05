<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/system.php';
require_once __DIR__ . '/../lib/geo.php';

$u = current_user();
if(!$u || $u['role']!=='admin'){ http_response_code(403); echo 'forbidden'; exit; }

// Release session lock for long-running stream to avoid blocking other requests
if(session_status() === PHP_SESSION_ACTIVE){ @session_write_close(); }
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-transform');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');
@set_time_limit(0);
@ignore_user_abort(true);

$pdo = db();
function snapshot($pdo){
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
  // Build workers list and enrich with active_job snapshot where info.active_job_id exists
  $rows = $pdo->query("SELECT worker_id, last_seen, info FROM internal_workers ORDER BY last_seen DESC LIMIT 50")->fetchAll();
  if($rows){
    foreach($rows as &$w){
      $info = null; try{ $info = $w['info']? json_decode($w['info'], true) : null; }catch(Throwable $e){ $info=null; }
      if(is_array($info) && isset($info['active_job_id'])){
        $jid = (int)$info['active_job_id'];
        if($jid>0){
          try{
            $sj = $pdo->prepare("SELECT id, attempt_id, lease_expires_at, last_progress_at FROM internal_jobs WHERE id=?");
            $sj->execute([$jid]); $w['active_job'] = $sj->fetch(PDO::FETCH_ASSOC) ?: null;
          }catch(Throwable $_){ $w['active_job']=null; }
        }
      }
    }
  }
  $stats['workers'] = [
    'online' => workers_online_count(false),
    'total' => (int)$pdo->query("SELECT COUNT(*) c FROM internal_workers")->fetch()['c'],
    'list' => $rows,
  ];
  // Ingestion metrics (24h)
  try{
    $q = $pdo->prepare("SELECT kind, SUM(count) cnt FROM usage_counters WHERE day >= date('now','-1 day') AND kind IN ('ingest_added','ingest_duplicates') GROUP BY kind");
    $q->execute();
    $rows2 = $q->fetchAll();
    $m = ['added'=>0,'duplicates'=>0,'dup_ratio'=>0.0];
    foreach($rows2 as $r){ if($r['kind']==='ingest_added') $m['added']=(int)$r['cnt']; if($r['kind']==='ingest_duplicates') $m['duplicates']=(int)$r['cnt']; }
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
  // Top cities aggregation (like monitor_stats)
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
  // Stuck processing detection
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
  return [ 'ok'=>true, 'now'=>$now, 'stats'=>$stats ];
}

$last = '';
$send = function($data) use (&$last){
  $json = json_encode($data, JSON_UNESCAPED_UNICODE);
  if($json!==$last){ echo "data: $json\n\n"; @ob_flush(); @flush(); $last=$json; }
};

// Advise client to retry after 3s on disconnect
echo "retry: 3000\n\n"; @ob_flush(); @flush();
// Initial snapshot
$send(snapshot($pdo));

// Stream for up to ~60 seconds then let client reconnect
$start = time();
while(!connection_aborted()){
  if((time()-$start) > 60){ break; }
  usleep(3000000);
  $send(snapshot($pdo));
}
exit;
?>
