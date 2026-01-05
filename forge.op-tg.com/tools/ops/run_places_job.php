<?php
// CLI runner for Google Places (lean) pipeline.
// - Can run by job id (expects job_type='places_api_search') or by direct payload.
// - Writes results into places table and marks job done on success.
if (php_sapi_name() !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }
require_once __DIR__ . '/../../bootstrap.php';

function rpj_parse_args($argv){
  $out = ['job'=>null,'payload'=>null,'dry'=>false];
  for($i=1;$i<count($argv);$i++){
    $a=$argv[$i];
    if($a==='-j'||$a==='--job'){ $out['job'] = (int)($argv[++$i] ?? 0); continue; }
    if($a==='-p'||$a==='--payload'){ $out['payload'] = $argv[++$i] ?? null; continue; }
    if($a==='--dry-run'){ $out['dry'] = true; continue; }
  }
  return $out;
}

try{
  $args = rpj_parse_args($argv);
  $pdo = db();
  require_once __DIR__ . '/../../src/jobs/places_api_search.php';

  $jobRow = null; $payload = null;
  if($args['job']){
    $st = $pdo->prepare("SELECT * FROM internal_jobs WHERE id=?");
    $st->execute([$args['job']]);
    $jobRow = $st->fetch();
    if(!$jobRow){ throw new RuntimeException('job_not_found'); }
    // If provided, ensure job_type matches (best-effort; ignore if column absent)
    if(isset($jobRow['job_type']) && $jobRow['job_type'] && $jobRow['job_type']!=='places_api_search'){
      throw new RuntimeException('job_type_mismatch: expected places_api_search');
    }
    if(!empty($jobRow['payload_json'])){
      $payload = json_decode($jobRow['payload_json'], true);
    }
  }
  if(!$payload && $args['payload']){ $payload = json_decode($args['payload'], true); }
  // Normalize payload whether from job payload_json or args or derive from legacy columns
  if(!is_array($payload)) $payload = [];
  // center
  if(empty($payload['center'])){
    $center = ['lat'=>24.7136,'lng'=>46.6753];
    if($jobRow && !empty($jobRow['ll']) && strpos($jobRow['ll'],',')!==false){ [$lat,$lng] = array_map('trim', explode(',', $jobRow['ll'], 2)); $center = ['lat'=>(float)$lat, 'lng'=>(float)$lng]; }
    $payload['center'] = $center;
  }
  // keywords/types
  if(empty($payload['keywords']) || !is_array($payload['keywords'])){
    $q = trim((string)($payload['query'] ?? ($jobRow['query'] ?? '')));
    if($q!==''){ $payload['keywords'] = [$q]; }
  }
  if(empty($payload['types']) || !is_array($payload['types'])){ $payload['types'] = []; }
  // radius/lang/region/max
  if(!isset($payload['radius_km'])){ $payload['radius_km'] = (float)($jobRow['radius_km'] ?? get_setting('default_radius_km','5')); }
  if(empty($payload['language'])){ $payload['language'] = $jobRow['lang'] ?? get_setting('default_language','ar'); }
  if(empty($payload['region'])){ $payload['region'] = $jobRow['region'] ?? get_setting('default_region','sa'); }
  if(!isset($payload['max_results'])){ $payload['max_results'] = (int)($jobRow['target_count'] ?? 100); }
  if($args['dry']){ echo json_encode(['ok'=>true,'dry'=>true,'payload'=>$payload], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit(0); }

  $stats = places_api_search_handle($jobRow ?: [], $payload);

  // Mark job done if we had a job
  if($jobRow){
    $now = date('Y-m-d H:i:s');
    $added = (int)(($stats['inserted'] ?? 0) + ($stats['updated'] ?? 0));
    $st2 = $pdo->prepare("UPDATE internal_jobs SET status='done', finished_at=:now, updated_at=:now, result_count=COALESCE(result_count,0)+:added, last_error=NULL, done_reason=COALESCE(done_reason,'worker_done') WHERE id=:id");
    $st2->execute([':now'=>$now, ':added'=>$added, ':id'=>$jobRow['id']]);
  }

  echo json_encode(['ok'=>true,'stats'=>$stats], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit(0);
}catch(Throwable $e){
  fwrite(STDERR, $e->getMessage()."\n");
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
  exit(2);
}
