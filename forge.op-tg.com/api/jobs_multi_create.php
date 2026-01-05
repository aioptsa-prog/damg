<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/categories.php';
require_once __DIR__ . '/../lib/system.php';
header('Content-Type: application/json; charset=utf-8');

try{
  $u = require_role('admin');
  $pdo = db();
  // Accept JSON or form
  $raw = file_get_contents('php://input');
  $isJson = isset($_SERVER['CONTENT_TYPE']) && stripos((string)$_SERVER['CONTENT_TYPE'],'application/json')!==false;
  $in = $isJson ? json_decode($raw,true) : $_POST;

  $csrf = $in['csrf'] ?? ($_GET['csrf'] ?? '');
  if(!csrf_verify($csrf)){
    http_response_code(400); echo json_encode(['ok'=>false,'error'=>'bad_csrf']); return;
  }

  // Inputs
  $category_id = isset($in['category_id']) ? (int)$in['category_id'] : 0;
  $base_query = trim((string)($in['base_query'] ?? ($in['q'] ?? '')));
  $locations = isset($in['locations']) && is_array($in['locations']) ? $in['locations'] : [];
  $multi_search = !empty($in['multi_search']);
  $target_count = isset($in['target_count']) && $in['target_count']!=='' ? max(1, (int)$in['target_count']) : null;
  $group_note = trim((string)($in['note'] ?? ''));

  if($category_id<=0){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'missing_category']); return; }
  if(empty($locations)){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'missing_locations']); return; }
  $maxLoc = (int)get_setting('MAX_MULTI_LOCATIONS','10'); if($maxLoc<=0) $maxLoc=10; if($maxLoc>100) $maxLoc=100;
  if(count($locations) > $maxLoc){ $locations = array_slice($locations, 0, $maxLoc); $locTrimmed = true; } else { $locTrimmed = false; }

  // Derive defaults
  $lang = get_setting('default_language','ar');
  $region = get_setting('default_region','sa');
  $maxJobs = (int)get_setting('MAX_EXPANDED_TASKS','30'); if($maxJobs<=0) $maxJobs=30; if($maxJobs>200) $maxJobs=200;

  // Prepare category context
  $catName = null;
  try{ $st=$pdo->prepare("SELECT name FROM categories WHERE id=?"); $st->execute([$category_id]); $catName = (string)($st->fetchColumn() ?: ''); }catch(Throwable $e){ $catName=null; }
  $keywords = category_get_keywords($category_id);
  $templates = category_get_templates($category_id);

  // Create group
  $pdo->beginTransaction();
  $stG = $pdo->prepare("INSERT INTO job_groups(created_by_user_id, category_id, base_query, note, created_at) VALUES(?,?,?,?, datetime('now'))");
  $stG->execute([$u['id'], $category_id, $base_query !== '' ? $base_query : null, $group_note !== '' ? $group_note : null]);
  $groupId = (int)$pdo->lastInsertId();

  // Detect optional job columns once
  $cols = $pdo->query("PRAGMA table_info(internal_jobs)")->fetchAll(PDO::FETCH_ASSOC);
  $has = function($n) use ($cols){ foreach($cols as $c){ if(($c['name']??$c['Name']??'')===$n) return true; } return false; };

  $totalCreated = 0; $perLoc = [];
  foreach($locations as $idx=>$loc){
    $llRaw = isset($loc['ll']) ? trim((string)$loc['ll']) : '';
    // allow lat/lng fields as well
    if($llRaw===''){
      $lat = isset($loc['lat']) ? (float)$loc['lat'] : null;
      $lng = isset($loc['lng']) ? (float)$loc['lng'] : null;
      if($lat!==null && $lng!==null){ $llRaw = sprintf('%.6f,%.6f', $lat, $lng); }
    }
    $radius_km = isset($loc['radius_km']) ? (int)$loc['radius_km'] : (int)get_setting('default_radius_km','25');
    $city_hint = isset($loc['city']) ? trim((string)$loc['city']) : '';

    // Validate ll
    if(!preg_match('/^-?\d+(?:\.\d+)?,\s*-?\d+(?:\.\d+)?$/', $llRaw)){
      $pdo->rollBack(); http_response_code(400); echo json_encode(['ok'=>false,'error'=>'bad_ll','at'=>$idx]); return;
    }
    $parts = array_map('trim', explode(',', $llRaw)); $lat = (float)$parts[0]; $lng = (float)$parts[1];
    if($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180){ $pdo->rollBack(); http_response_code(400); echo json_encode(['ok'=>false,'error'=>'ll_out_of_range','at'=>$idx]); return; }
    $radius_km = max(1, min(100, (int)$radius_km));
    $ll = sprintf('%.6f,%.6f', $lat, $lng);

    // Build queries for this location
    $queries = [];
    if($base_query !== ''){ $queries[] = ['text'=>$base_query, 'source'=>'user']; }
    if($multi_search){
      if($catName){ $queries[] = ['text'=>$catName, 'source'=>'category_name']; }
      foreach($keywords as $kw){ $queries[] = ['text'=>$kw, 'source'=>'keyword']; }
      foreach($templates as $tpl){
        $txt = (string)$tpl;
        if($catName){ $txt = str_replace(['{keyword}','{name}'], [$catName,$catName], $txt); }
        $txt = str_replace('{city}', $city_hint, $txt);
        $queries[] = ['text'=>$txt, 'source'=>'template'];
      }
    }
    if(empty($queries)){
      // If no base_query and not multi_search, still enqueue 1 job using cat name if available
      $fallback = $base_query !== '' ? $base_query : ($catName ?: '');
      if($fallback==='') $fallback = 'search';
      $queries[] = ['text'=>$fallback, 'source'=>'auto'];
    }
    $projected = count($queries); $trimmed=false; if($projected > $maxJobs){ $queries = array_slice($queries, 0, $maxJobs); $trimmed=true; }

    $created = 0;
    foreach($queries as $qq){
      $stmt=$pdo->prepare("INSERT INTO internal_jobs(requested_by_user_id,role,agent_id,query,ll,radius_km,lang,region,status,target_count,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,'queued',?, datetime('now'), datetime('now'))");
      $stmt->execute([$u['id'],'admin',NULL,$qq['text'],$ll,$radius_km,$lang,$region,$target_count]);
      $job_id = (int)$pdo->lastInsertId(); $created++;
      // Update optional fields
      try{
        // payload_json + job_type
        if($has('payload_json')){
          $payload = [
            'query'=>$qq['text'],'query_source'=>$qq['source'],'category_id'=>$category_id,
            'center'=>$ll,'radius_km'=>$radius_km,'language'=>$lang,'region'=>$region
          ];
          if($city_hint!==''){ $payload['city_hint'] = $city_hint; }
          $pj = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
          $sqlU = $has('job_type') ? "UPDATE internal_jobs SET job_type='places_api_search', payload_json=:p WHERE id=:id" : "UPDATE internal_jobs SET payload_json=:p WHERE id=:id";
          $stU=$pdo->prepare($sqlU); $stU->execute([':p'=>$pj, ':id'=>$job_id]);
        }
        // category_id
        if($has('category_id')){ $pdo->prepare("UPDATE internal_jobs SET category_id=? WHERE id=?")->execute([$category_id, $job_id]); }
        // job_group_id and city_hint
        if($has('job_group_id')){ $pdo->prepare("UPDATE internal_jobs SET job_group_id=?, city_hint=? WHERE id=?")->execute([$groupId, ($city_hint!==''?$city_hint:null), $job_id]); }
      }catch(Throwable $e){}
    }
    $totalCreated += $created;
    $perLoc[] = ['ll'=>$ll, 'radius_km'=>$radius_km, 'city'=>$city_hint, 'created'=>$created, 'trimmed'=>$trimmed, 'projected'=>$projected];
  }
  $pdo->commit();

  // usage_counters telemetry (best-effort)
  try{
    $day = date('Y-m-d');
    $inc = function($kind,$cnt) use ($pdo,$day){ if($cnt<=0) return; $pdo->prepare("INSERT INTO usage_counters(day,kind,count) VALUES(?,?,?) ON CONFLICT(day,kind) DO UPDATE SET count=count+excluded.count")->execute([$day,$kind,$cnt]); };
    $inc('ml_groups_created', 1);
    $inc('ml_jobs_created', $totalCreated);
  }catch(Throwable $e){}

  echo json_encode(['ok'=>true,'job_group_id'=>$groupId,'jobs_created_total'=>$totalCreated,'locations'=>$perLoc,'locations_trimmed'=>$locTrimmed]);
}catch(Throwable $e){ http_response_code(500); echo json_encode(['ok'=>false,'error'=>'server_error']); }
