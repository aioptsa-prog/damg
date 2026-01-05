<?php
// Queue runner for places_api_search jobs (conservative, CLI-only)
// Usage: php tools/ops/run_places_queue.php [--max N] [--window-minutes M] [--dry-run]
if (php_sapi_name() !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }
require_once __DIR__ . '/../../bootstrap.php';

function rpq_args($argv){
  $a=['max'=>5,'window'=>null,'dry'=>false];
  for($i=1;$i<count($argv);$i++){
    $t=$argv[$i];
    if($t==='--max'){ $a['max'] = max(1, (int)($argv[++$i] ?? 5)); continue; }
    if($t==='--window-minutes'){ $a['window'] = max(1, (int)($argv[++$i] ?? 0)); continue; }
    if($t==='--dry-run'){ $a['dry'] = true; continue; }
  }
  return $a;
}

function rpq_log_path(){ $dir=__DIR__.'/../../storage/logs/ops'; if(!is_dir($dir)) @mkdir($dir,0777,true); $d=date('Ymd'); return $dir."/places_queue_{$d}.log"; }
function rpq_log($msg){ $line='['.date('c').'] '.$msg."\n"; echo $line; @file_put_contents(rpq_log_path(), $line, FILE_APPEND); }

try{
  $args = rpq_args($argv);
  $pdo = db();
  // Discover columns for safe filtering
  $cols = $pdo->query("PRAGMA table_info(internal_jobs)")->fetchAll(PDO::FETCH_ASSOC);
  $has = function($n) use ($cols){ foreach($cols as $c){ if(($c['name']??$c['Name']??'')===$n) return true; } return false; };
  $now = date('Y-m-d H:i:s');
  $where = ["status='queued'", "(next_retry_at IS NULL OR next_retry_at <= :now)"];
  $params = [':now'=>$now];
  if($has('job_type')){ $where[] = "job_type='places_api_search'"; }
  if($has('attempts')){
    if($has('max_attempts')){ $where[] = "(max_attempts IS NULL OR attempts < max_attempts)"; }
    else { $maxA = (int)settings_get('MAX_ATTEMPTS_DEFAULT','5'); $where[] = "attempts < ".$maxA; }
  }
  if(!empty($args['window'])){ $winTs = date('Y-m-d H:i:s', time() - ((int)$args['window']*60)); $where[] = "created_at >= :win"; $params[':win']=$winTs; }
  $sql = "SELECT id, query, created_at FROM internal_jobs WHERE ".implode(' AND ', $where)." ORDER BY created_at ASC LIMIT ".(int)$args['max'];
  $st = $pdo->prepare($sql); $st->execute($params); $jobs = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $hdr = sprintf("Queue Runner start (max=%d%s)%s",
    (int)$args['max'],
    $args['window']? ", window_min=%d min" : '',
    $args['dry']? ' [DRY-RUN]' : ''
  );
  $hdr = $args['window']? sprintf($hdr, (int)$args['window']) : $hdr;
  rpq_log($hdr);

  if(!$jobs){ rpq_log('No jobs to run.'); exit(0); }
  if($args['dry']){
    rpq_log('DRY-RUN: would run job ids: '.implode(',', array_map(function($r){return (string)$r['id'];}, $jobs)));
    exit(0);
  }

  $okCount = 0; $failCount = 0;
  $sum = ['inserted'=>0,'updated'=>0,'deduped'=>0,'pages'=>0];
  foreach($jobs as $j){
    $jid = (int)$j['id'];
    rpq_log("Running job #$jid query='".str_replace(["\n","\r"],' ', (string)$j['query'])."'...");
    $proc = proc_open([
      PHP_BINARY,
      __DIR__ . '/run_places_job.php',
      '--job', (string)$jid
    ], [1=>['pipe','w'], 2=>['pipe','w']], $pipes, dirname(__DIR__,2));
    if(!is_resource($proc)){
      rpq_log("ERROR: proc_open failed for job #$jid"); $failCount++; usleep(1000000); continue;
    }
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); $code = proc_close($proc);
    if($code!==0){ rpq_log("ERROR: job #$jid exit=$code stderr=".trim($err)); $failCount++; usleep(1000000); continue; }
    $jout = json_decode($out, true);
    if(!$jout || empty($jout['ok'])){ rpq_log("ERROR: job #$jid returned invalid output: ".substr(trim($out),0,400)); $failCount++; usleep(1000000); continue; }
    $stats = $jout['stats'] ?? [];
    $ins = (int)($stats['inserted'] ?? 0); $upd=(int)($stats['updated'] ?? 0); $ded=(int)($stats['deduped'] ?? 0); $pg=(int)($stats['pages'] ?? 0); $batch = (string)($stats['batch_id'] ?? '');
    $sum['inserted'] += $ins; $sum['updated'] += $upd; $sum['deduped'] += $ded; $sum['pages'] += $pg;
    rpq_log(sprintf("Job #%d OK: pages=%d, inserted=%d, updated=%d, deduped=%d, batch_id=%s", $jid, $pg, $ins, $upd, $ded, $batch ?: '-'));
    $okCount++;
    usleep(1000000); // 1s gap between jobs
  }

  rpq_log(sprintf("Done. ok=%d, fail=%d, total_pages=%d, total_inserted=%d, total_updated=%d, total_deduped=%d", $okCount, $failCount, $sum['pages'], $sum['inserted'], $sum['updated'], $sum['deduped']));
  exit(($okCount>0 || $failCount===0)? 0 : 2);
}catch(Throwable $e){
  rpq_log('FATAL: '.$e->getMessage());
  exit(2);
}
