<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/system.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/geo.php';
require_once __DIR__ . '/../lib/providers.php';
header('Content-Type: application/json; charset=utf-8');

try{
  $u = require_login(); // any authenticated user
  // Accept JSON or form
  $raw = file_get_contents('php://input');
  $isJson = isset($_SERVER['CONTENT_TYPE']) && stripos((string)$_SERVER['CONTENT_TYPE'],'application/json')!==false;
  $in = $isJson ? json_decode($raw,true) : $_POST;
  $lat = isset($in['lat']) ? floatval($in['lat']) : null;
  $lng = isset($in['lng']) ? floatval($in['lng']) : null;
  $cityQuery = trim((string)($in['city_name'] ?? ''));
  $suggest = isset($in['suggest']) ? (bool)$in['suggest'] : false;
  $qpref = isset($in['q']) ? trim((string)$in['q']) : trim((string)($in['prefix'] ?? ($in['city_prefix'] ?? '')));
  $strictCity = isset($in['strict_city']) ? (bool)$in['strict_city'] : false;
  $csrf = $in['csrf'] ?? ($_POST['csrf'] ?? '');
  if(!csrf_verify($csrf)){
    http_response_code(400); echo json_encode(['ok'=>false,'error'=>'bad_csrf']); return;
  }
  // Mode 0: Suggestions for city names (top 5)
  if($suggest && $qpref!==''){
    try{
      $db = geo_db('SA');
      $rows = $db->query("SELECT id, name_ar, name_en, region_code, lat, lon, alt_names FROM cities")->fetchAll();
      $needle = geo_norm_ar($qpref);
      $seen = [];
      $cands = [];
      foreach($rows as $r){
        $names = [ geo_norm_ar($r['name_ar'] ?? ''), geo_norm_ar($r['name_en'] ?? '') ];
        if(!empty($r['alt_names'])){ foreach(preg_split('/[|,؛،]/u',(string)$r['alt_names']) as $an){ $names[] = geo_norm_ar($an); } }
        $bestScore = 0.0;
        foreach($names as $nm){ if($nm==='') continue; if(strpos($nm, $needle)===0){ $bestScore = max($bestScore, 1.0); break; } if(strpos($nm, $needle)!==false){ $bestScore = max($bestScore, 0.7); } }
        if($bestScore>0){ $id=(int)$r['id']; if(!isset($seen[$id])){ $seen[$id]=true; $cands[] = [ 'id'=>$id, 'name'=>$r['name_ar'] ?: ($r['name_en'] ?: ''), 'region'=>$r['region_code'] ?: null, 'lat'=> isset($r['lat'])?(float)$r['lat']:null, 'lng'=> isset($r['lon'])?(float)$r['lon']:null, 'score'=>$bestScore ]; } }
      }
      // Sort by score desc, then by name asc
      usort($cands, function($a,$b){ if($a['score']==$b['score']) return strcmp($a['name'],$b['name']); return ($a['score']<$b['score'])?1:-1; });
      $cands = array_slice($cands, 0, 5);
      echo json_encode(['ok'=>true,'suggestions'=>$cands]); return;
    }catch(Throwable $e){ http_response_code(500); echo json_encode(['ok'=>false,'error'=>'server_error']); return; }
  }
  // Mode 1: Resolve city name -> coords
  if($cityQuery !== ''){
    try{
      $match = geo_sa_match_city_by_text($cityQuery);
      if(!$match){ echo json_encode(['ok'=>false,'error'=>'city_not_found']); return; }
      [$cRow,$score] = $match;
      $db = geo_db('SA');
      $s = $db->prepare("SELECT name_ar, region_code, lat, lon FROM cities WHERE id=?");
      $s->execute([(int)$cRow['id']]);
      $cr = $s->fetch();
      if(!$cr || $cr['lat']===null || $cr['lon']===null){ echo json_encode(['ok'=>false,'error'=>'city_missing_coords']); return; }
      echo json_encode(['ok'=>true,'city'=>$cr['name_ar'],'region'=>$cr['region_code'],'lat'=>(float)$cr['lat'],'lng'=>(float)$cr['lon'],'source'=>'sa_db_text','score'=>$score]);
      return;
    }catch(Throwable $e){ http_response_code(500); echo json_encode(['ok'=>false,'error'=>'server_error']); return; }
  }
  // Mode 2: Reverse coords -> city
  if($lat===null || $lng===null){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'bad_input']); return; }
  // Helper: check if point is within KSA bounding box (approx)
  $inSA = ($lat>=15.5 && $lat<=32.5 && $lng>=34.0 && $lng<=56.5);
  // Strict mode: always return city from SA DB and exit
  if($strictCity){
    try{
      $cls = geo_classify_point($lat, $lng, 'SA');
      if(isset($cls['city_id']) && $cls['city_id']){
        $db = geo_db('SA');
        $st = $db->prepare("SELECT name_ar, region_code FROM cities WHERE id=?");
        $st->execute([(int)$cls['city_id']]);
        $cr = $st->fetch();
        if($cr){ echo json_encode(['ok'=>true,'city'=>$cr['name_ar'],'region'=>$cr['region_code'],'source'=>'sa_point']); return; }
      }
      // Fallback to nearest SA city
      [$rowSa,$kmSa] = geo_sa_nearest_city($lat, $lng);
      if($rowSa && isset($rowSa['name_ar'])){ echo json_encode(['ok'=>true,'city'=>$rowSa['name_ar'],'region'=>$rowSa['region_code']??null,'source'=>'sa_db']); return; }
    }catch(Throwable $e){ /* fallthrough to generic path */ }
  }
  // Always prefer internal SA classification for precise city mapping
  $cityName = null; $region=null; $source='sa_point';
  try{
    $cls = geo_classify_point($lat, $lng, 'SA');
    if(isset($cls['city_id']) && $cls['city_id']){
      $db = geo_db('SA');
      $st = $db->prepare("SELECT name_ar, region_code FROM cities WHERE id=?");
      $st->execute([(int)$cls['city_id']]);
      $cr = $st->fetch();
      if($cr){ $cityName = $cr['name_ar']; $region = $cr['region_code']; $source='sa_point'; }
    }
  }catch(Throwable $e){ /* fallback below */ }

  // Fallback to Mapbox reverse geocode if configured (only when outside SA)
  if(!$cityName && !$inSA){
    $token = get_setting('mapbox_api_key','');
    if($token){
      $url = 'https://api.mapbox.com/geocoding/v5/mapbox.places/'.rawurlencode($lng.','.$lat).'.json?'.http_build_query(['types'=>'place','language'=>'ar','access_token'=>$token]);
      $data = json_get($url);
      if($data && !empty($data['features'])){
        $feat = $data['features'][0];
        $cityName = $feat['text_ar'] ?? ($feat['text'] ?? null);
        $source = 'mapbox';
      }
    }
  }
  // Fallback to OSM Nominatim (no key) if still empty — restrict to city-level only (only when outside SA)
  if(!$cityName && !$inSA){
    $url = 'https://nominatim.openstreetmap.org/reverse?'.http_build_query([
      'lat'=>$lat,
      'lon'=>$lng,
      'format'=>'json',
      'zoom'=>10,
      'accept-language'=>'ar'
    ]);
    $data = json_get($url, ['User-Agent: NexusOps/1.0 (+https://op-tg.com)']);
    if($data && isset($data['address'])){
      $addr = $data['address'];
      $cityName = $addr['city'] ?? ($addr['town'] ?? ($addr['village'] ?? null));
      $region = $addr['state'] ?? ($addr['county'] ?? $region);
      if($cityName){ $source = 'nominatim'; }
    }
  }
  // Final guard: if still not resolved OR point is within SA, snap to nearest SA city
  try{
    if(!$cityName || $inSA){
      [$rowSa,$kmSa] = geo_sa_nearest_city($lat, $lng);
      if($rowSa && isset($rowSa['name_ar'])){
        $cityName = $rowSa['name_ar'];
        $region = $rowSa['region_code'] ?? $region;
        $source = 'sa_db';
      }
    }
  }catch(Throwable $e){ /* keep previous */ }

  if(!$cityName){ echo json_encode(['ok'=>false,'error'=>'not_found']); return; }
  echo json_encode(['ok'=>true,'city'=>$cityName,'region'=>$region,'source'=>$source]);
}catch(Throwable $e){ http_response_code(500); echo json_encode(['ok'=>false,'error'=>'server_error']); }
