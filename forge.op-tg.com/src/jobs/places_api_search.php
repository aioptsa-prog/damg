<?php
// Google Places pipeline (lean mode) — executed server-side; no UI/URLs changes.
// Usage: include and call places_api_search_handle($jobRow, $payload);

function places_api_env($key){
  // We keep keys in settings table; do not hardcode secrets here
  if(!function_exists('get_setting')) require_once __DIR__ . '/../../lib/auth.php';
  return get_setting($key,'');
}

function places_api_search_handle(array $job, array $payload){
  $pdo = db();
  $apiKey = places_api_env('google_api_key');
  if(!$apiKey){ throw new RuntimeException('Google API key missing'); }
  $types = isset($payload['types']) && is_array($payload['types']) && $payload['types']? $payload['types'] : ['restaurant'];
  $keywords = isset($payload['keywords']) && is_array($payload['keywords'])? $payload['keywords'] : [];
  $center = $payload['center'] ?? null; if(!$center || !isset($center['lat'],$center['lng'])){ $center = [ 'lat'=> 24.7136, 'lng'=> 46.6753 ]; }
  $radiusKm = max(0.2, (float)($payload['radius_km'] ?? (float)places_api_env('GOOGLE_RADIUS_KM_DEFAULT') ?: 1));
  // Practically unlimited by default; can be reduced via payload
  $maxResults = (int)($payload['max_results'] ?? 100000000);
  $lang = $payload['language'] ?? (places_api_env('GOOGLE_LANGUAGE') ?: 'ar');
  $region = $payload['region'] ?? (places_api_env('GOOGLE_REGION') ?: 'SA');
  $rps = max(1, (int)($payload['rps'] ?? (int)(places_api_env('GOOGLE_RATE_LIMIT_RPS') ?: 5)));
  $pageDelay = max(0, (int)($payload['page_delay_ms'] ?? (int)(places_api_env('GOOGLE_PAGE_DELAY_MS') ?: 250)));
  $batchId = sprintf('%d-%s', time(), bin2hex(random_bytes(3)));

  $base = 'https://maps.googleapis.com/maps/api/place/textsearch/json';
  $nearby = 'https://maps.googleapis.com/maps/api/place/nearbysearch/json';
  $session = curl_init(); curl_setopt($session, CURLOPT_RETURNTRANSFER, true); curl_setopt($session, CURLOPT_TIMEOUT, 30);

  $stats = ['pages'=>0,'inserted'=>0,'updated'=>0,'deduped'=>0,'duration_ms'=>0,'batch_id'=>$batchId];
  $t0 = microtime(true);

  $queryStrings = [];
  if($keywords){ foreach($keywords as $kw){ $queryStrings[] = trim((string)$kw); } }
  if(!$queryStrings){ $queryStrings[] = implode(' ', $types); }

  // Build scan grid (optional exhaustive)
  $exhaustive = isset($payload['exhaustive']) ? (bool)$payload['exhaustive'] : true;
  $stepKm = isset($payload['grid_step_km']) ? (float)$payload['grid_step_km'] : (float)(places_api_env('exhaustive_grid_step_km') ?: 2);
  if(!is_finite($stepKm) || $stepKm<=0) $stepKm = 2.0;
  $maxPts = isset($payload['max_points']) ? (int)$payload['max_points'] : (int)(places_api_env('exhaustive_max_points') ?: 1000);
  if($maxPts<1) $maxPts=1; if($maxPts>10000) $maxPts=10000;
  $lat0 = (float)$center['lat']; $lng0 = (float)$center['lng'];
  $points = [ [ $lat0, $lng0 ] ];
  if($exhaustive){
    $degPerKmLat = 1.0/111.0; $degPerKmLon = 1.0/(111.0 * max(0.1, cos(deg2rad($lat0))));
    $R = max(0.2, (float)$radiusKm);
    $pts = [];
    for($dy=-$R; $dy<=$R; $dy+=$stepKm){
      for($dx=-$R; $dx<=$R; $dx+=$stepKm){
        $dist = sqrt($dx*$dx + $dy*$dy); if($dist > $R + 1e-6) continue;
        $plat = $lat0 + ($dy * $degPerKmLat);
        $plng = $lng0 + ($dx * $degPerKmLon);
        $pts[] = [ $plat, $plng ];
        if(count($pts) >= $maxPts){ break 2; }
      }
    }
    array_unshift($pts, [ $lat0, $lng0 ]);
    $uniq=[]; foreach($pts as $p){ $k = sprintf('%.5f,%.5f',$p[0],$p[1]); $uniq[$k]=$p; } $points = array_values($uniq);
  }

  $resultsSeen = 0; $pages = 0;
  foreach($points as $p){
    if($resultsSeen >= $maxResults) break;
    $pageToken = null;
    do {
      $params = [ 'key'=>$apiKey, 'language'=>$lang, 'region'=>$region ];
      if($pageToken){ $params['pagetoken']=$pageToken; }
      else {
        if($keywords){
          $params['query'] = $queryStrings[0];
          $params['location'] = $p[0].','.$p[1];
          $params['radius'] = (int)round($radiusKm*1000);
        } else {
          $params['type'] = $types[0];
          $params['location'] = $p[0].','.$p[1];
          $params['radius'] = (int)round($radiusKm*1000);
        }
      }
      $url = ($keywords? $base : $nearby) . '?' . http_build_query($params);
      curl_setopt($session, CURLOPT_URL, $url);
      $resp = curl_exec($session);
      if($resp===false){ throw new RuntimeException('curl error: '.curl_error($session)); }
      $json = json_decode($resp, true);
      $status = $json['status'] ?? 'UNKNOWN';
      if($status==='OVER_QUERY_LIMIT' || $status==='RESOURCE_EXHAUSTED' || $status==='UNKNOWN_ERROR' || $status==='INTERNAL_ERROR'){
        usleep(500000); // backoff 0.5s
        continue;
      }
      if($status!=='OK' && $status!=='ZERO_RESULTS'){
        break;
      }
      $pages++;
      $pageToken = $json['next_page_token'] ?? null;
      $items = $json['results'] ?? [];

      $pdo->beginTransaction();
      try{
        foreach($items as $it){
          if($resultsSeen >= $maxResults) break 2;
        $name = trim((string)($it['name'] ?? ''));
        $placeId = trim((string)($it['place_id'] ?? '')) ?: null;
        $vicinity = trim((string)($it['vicinity'] ?? ($it['formatted_address'] ?? '')));
        $typesJson = json_encode($it['types'] ?? [], JSON_UNESCAPED_UNICODE);
        $lat = isset($it['geometry']['location']['lat'])? (float)$it['geometry']['location']['lat'] : null;
        $lng = isset($it['geometry']['location']['lng'])? (float)$it['geometry']['location']['lng'] : null;
        $website = null; // Text/nearby response may not include website; requires details call (skipped in lean mode)
        $phone = null;   // same as above; lean mode avoids details to keep RPS low
        $sourceUrl = null; // could be built if needed
        $raw = json_encode($it, JSON_UNESCAPED_UNICODE);

        $now = date('Y-m-d H:i:s');
        if($placeId){
          $st = $pdo->prepare("UPDATE places SET name=:n, phone=COALESCE(phone,:ph), address=:ad, lat=:lat, lng=:lng, website=COALESCE(website,:wb), types_json=:tj, source='google_api', source_url=COALESCE(source_url,:su), raw_json=:raw, last_seen_at=:now, batch_id=:b WHERE place_id=:pid");
          $st->execute([':n'=>$name, ':ph'=>$phone, ':ad'=>$vicinity, ':lat'=>$lat, ':lng'=>$lng, ':wb'=>$website, ':tj'=>$typesJson, ':su'=>$sourceUrl, ':raw'=>$raw, ':now'=>$now, ':b'=>$batchId, ':pid'=>$placeId]);
          if($st->rowCount()===0){
            $ins = $pdo->prepare("INSERT OR IGNORE INTO places(name, phone, address, country, city, lat, lng, place_id, website, types_json, source, source_url, raw_json, collected_at, last_seen_at, batch_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,:now,:now,?)");
            $country=null; $city=null; $ins->execute([$name,$phone,$vicinity,$country,$city,$lat,$lng,$placeId,$website,$typesJson,'google_api',$sourceUrl,$raw,$now,$batchId]);
            if($ins->rowCount()>0){ $stats['inserted']++; } else { $stats['updated']++; }
          } else { $stats['updated']++; }
        } else {
          // Dedup (light): match by normalized name (prefix) + missing phone/address similarity
          $cand = $pdo->prepare("SELECT id,name,phone,address FROM places WHERE source='google_api' AND name LIKE :n AND city IS NULL LIMIT 1");
          $cand->execute([':n'=>substr($name,0,10).'%']);
          $row=$cand->fetch();
          if($row){
            $upd = $pdo->prepare("UPDATE places SET name=:n, address=:ad, lat=:lat, lng=:lng, types_json=:tj, raw_json=:raw, last_seen_at=:now, batch_id=:b WHERE id=:id");
            $upd->execute([':n'=>$name, ':ad'=>$vicinity, ':lat'=>$lat, ':lng'=>$lng, ':tj'=>$typesJson, ':raw'=>$raw, ':now'=>$now, ':b'=>$batchId, ':id'=>$row['id']]);
            $stats['deduped']++;
          } else {
            $ins = $pdo->prepare("INSERT INTO places(name, phone, address, country, city, lat, lng, place_id, website, types_json, source, source_url, raw_json, collected_at, last_seen_at, batch_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,:now,:now,?)");
            $country=null; $city=null; $ins->execute([$name,$phone,$vicinity,$country,$city,$lat,$lng,null,$website,$typesJson,'google_api',$sourceUrl,$raw,$now,$batchId]);
            $stats['inserted']++;
          }
        }
        $resultsSeen++;
      }
        $pdo->commit();
      }catch(Throwable $e){ $pdo->rollBack(); throw $e; }

      $stats['pages'] = $pages;
      if($pageToken){ $sleepMs = max(2000, (int)$pageDelay); usleep($sleepMs*1000); }
    } while($pageToken && $resultsSeen < $maxResults);
  }

  $stats['duration_ms'] = (int)round((microtime(true)-$t0)*1000);
  return $stats;
}
