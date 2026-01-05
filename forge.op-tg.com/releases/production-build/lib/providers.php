<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/limits.php';

function http_get($url, $headers = []){ $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60,CURLOPT_SSL_VERIFYPEER=>false]); if($headers){ curl_setopt($ch, CURLOPT_HTTPHEADER, $headers); } $raw=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch); return [$code,$raw?:'', $err?:'']; }
function json_get($url, $headers = []){ list($code,$raw,$err)=http_get($url,$headers); if($code!==200||!$raw) return null; return json_decode($raw,true); }

function cache_get($provider,$external_id){ $st=db()->prepare("SELECT * FROM place_cache WHERE provider=? AND external_id=?"); $st->execute([$provider,$external_id]); return $st->fetch(); }
function cache_set($provider,$external_id,$data){ db()->prepare("INSERT INTO place_cache(provider,external_id,phone,name,city,country,updated_at) VALUES(?,?,?,?,?,?,datetime('now')) ON CONFLICT(provider,external_id) DO UPDATE SET phone=excluded.phone,name=excluded.name,city=excluded.city,country=excluded.country,updated_at=datetime('now')")->execute([$provider,$external_id,$data['phone']??null,$data['name']??null,$data['city']??null,$data['country']??null]); }

function tile_key_for($q,$ll,$r){ return sha1(trim($q).'|'.trim($ll).'|'.(int)$r.'|v1'); }
function tile_allowed($tile_key){ $ttl=max(1,(int)get_setting('tile_ttl_days','14')); $st=db()->prepare("SELECT updated_at FROM search_tiles WHERE tile_key=?"); $st->execute([$tile_key]); $r=$st->fetch(); if(!$r) return true; $updated=strtotime($r['updated_at']); return (time()-$updated) > ($ttl*86400); }
function tile_touch($tile_key,$q,$ll,$r,$order,$preview,$added){ db()->prepare("INSERT INTO search_tiles(tile_key,q,ll,radius_km,provider_order,preview_count,leads_added,updated_at) VALUES(?,?,?,?,?,?,?,datetime('now')) ON CONFLICT(tile_key) DO UPDATE SET provider_order=excluded.provider_order, preview_count=excluded.preview_count, leads_added=search_tiles.leads_added + excluded.leads_added, updated_at=datetime('now')")->execute([$tile_key,$q,$ll,(int)$r,$order,(int)$preview,(int)$added]); }

/* OSM */
function provider_osm($q,$lat,$lng,$radius_km){
  $radius_m = max(50, (int)($radius_km*1000));
  $limit = (int)get_setting('overpass_limit','100'); if($limit<50) $limit=50; if($limit>1000) $limit=1000;
  $query = "[out:json][timeout:25];("
           ."node(around:" . $radius_m . "," . $lat . "," . $lng . ")[\"name\"];"
           ."way(around:" . $radius_m . "," . $lat . "," . $lng . ")[\"name\"];"
           ."relation(around:" . $radius_m . "," . $lat . "," . $lng . ")[\"name\"];"
           .");out center " . $limit . ";";
  $url = 'https://overpass-api.de/api/interpreter?data=' . urlencode($query);
  $data=json_get($url); if(!$data||!isset($data['elements'])) return [];
  $out=[];
  foreach($data['elements'] as $el){
    $tags=$el['tags']??[]; $name=$tags['name']??''; if($q && $name && mb_stripos($name,$q)===false) continue;
    $phone = $tags['contact:phone'] ?? ($tags['phone'] ?? '');
    $eid = ($el['type']??'node').':'.($el['id']??'');
    $olat = isset($el['lat']) ? (float)$el['lat'] : (isset($el['center']['lat']) ? (float)$el['center']['lat'] : null);
    $olon = isset($el['lon']) ? (float)$el['lon'] : (isset($el['center']['lon']) ? (float)$el['center']['lon'] : null);
    $out[]=['provider'=>'osm','external_id'=>$eid,'name'=>$name,'phone'=>$phone,'city'=>$tags['addr:city']??'','country'=>$tags['addr:country']??'','lat'=>$olat,'lng'=>$olon];
  }
  return $out;
}

/* Foursquare */
function provider_foursquare($q,$lat,$lng,$radius_km,$api_key,$exhaustive=false){
  if(!$api_key) return [];
  $radius_m = max(50, (int)($radius_km*1000));
  $headers=['Authorization: '.$api_key,'accept: application/json'];
  $out=[]; $cursor=null; $page=0; $limit=50;
  do{
    $page++;
    $params=['query'=>$q,'ll'=>$lat.','.$lng,'radius'=>$radius_m,'limit'=>$limit];
    if($cursor){ $params['cursor']=$cursor; }
    $data=json_get('https://api.foursquare.com/v3/places/search?'.http_build_query($params),$headers);
    if(!$data||empty($data['results'])) break;
    foreach($data['results'] as $r){
      $fsq_id=$r['fsq_id']??null; if(!$fsq_id) continue;
      $det=json_get('https://api.foursquare.com/v3/places/'.$fsq_id.'?fields=name,location,tel',$headers);
      $geo=$r['geocodes']['main']??[]; $olat=isset($geo['latitude'])?(float)$geo['latitude']:null; $olon=isset($geo['longitude'])?(float)$geo['longitude']:null;
      $out[]=['provider'=>'foursquare','external_id'=>$fsq_id,'name'=>$det['name']??($r['name']??''),'phone'=>$det['tel']??'','city'=>$det['location']['locality']??'','country'=>$det['location']['country']??'','lat'=>$olat,'lng'=>$olon];
    }
    $cursor = $data['context']['next_cursor'] ?? null;
  } while($exhaustive && $cursor && $page < 100);
  return $out;
}

/* Mapbox */
function provider_mapbox($q,$lat,$lng,$radius_km,$token){
  if(!$token) return [];
  $params = http_build_query(['access_token'=>$token,'proximity'=>$lng.','.$lat,'limit'=>20,'language'=>'ar']);
  $url = 'https://api.mapbox.com/geocoding/v5/mapbox.places/'.urlencode($q).'.json?'.$params;
  $data = json_get($url);
  if(!$data||!isset($data['features'])) return [];
  $out=[];
  foreach($data['features'] as $f){
    $name = $f['text_ar'] ?? ($f['text'] ?? '');
    $ctx = $f['context'] ?? [];
    $city=''; $country='';
    foreach($ctx as $c){
      $id = $c['id'] ?? '';
      if(strpos($id,'place')===0) $city = $c['text_ar'] ?? ($c['text'] ?? $city);
      if(strpos($id,'country')===0) $country = $c['text_ar'] ?? ($c['text'] ?? $country);
    }
    $props = $f['properties'] ?? [];
    $phone = $props['tel'] ?? ($props['phone'] ?? '');
    $eid = 'mapbox:'.$f['id'];
    $center = $f['center'] ?? null; $olat = (is_array($center)&&count($center)>=2)? (float)$center[1] : null; $olon = (is_array($center)&&count($center)>=2)? (float)$center[0] : null;
    $out[] = ['provider'=>'mapbox','external_id'=>$eid,'name'=>$name,'phone'=>$phone,'city'=>$city,'country'=>$country,'lat'=>$olat,'lng'=>$olon];
  }
  return $out;
}

/* Radar.io */
function provider_radar($q,$lat,$lng,$radius_km,$secret,$exhaustive=false){
  if(!$secret) return [];
  $out=[]; $page=1; $limit=50; $more=true; $radius=max(50,(int)($radius_km*1000));
  while($more){
    $params = http_build_query(['query'=>$q,'near'=>$lat.','.$lng,'radius'=>$radius,'limit'=>$limit,'page'=>$page]);
    $data = json_get('https://api.radar.io/v1/places/search?'.$params, ['Authorization: '.$secret]);
    if(!$data||!isset($data['places'])) break;
    $batch = $data['places']; if(empty($batch)) break;
    foreach($batch as $p){
      $eid = $p['_id'] ?? ($p['id'] ?? null); if(!$eid) continue;
      $name = $p['name'] ?? '';
      $addr = $p['location'] ?? [];
      $city = $addr['city'] ?? '';
      $country = $addr['country'] ?? '';
      $contact = $p['contact'] ?? [];
      $phone = $contact['phone'] ?? '';
      $coords = $addr['coordinates'] ?? ($p['geometry']['coordinates'] ?? null);
      $olon = (is_array($coords)&&count($coords)>=2)? (float)$coords[0] : null; $olat = (is_array($coords)&&count($coords)>=2)? (float)$coords[1] : null;
      $out[] = ['provider'=>'radar','external_id'=>$eid,'name'=>$name,'phone'=>$phone,'city'=>$city,'country'=>$country,'lat'=>$olat,'lng'=>$olon];
    }
    $page++;
    $more = $exhaustive && count($batch) >= $limit && $page <= 100;
  }
  return $out;
}

/* Google Text Search (preview IDs) */
function provider_google_preview($q,$lat,$lng,$radius_km,$lang,$region,$key,$pages=1){
  if(!$key) return ['ids'=>[], 'error'=>'no_key'];
  $radius_m = max(50, (int)($radius_km*1000));
  $ids=[]; $error=null; $page=0; $nextToken=null;
  do{
    $page++;
    if($nextToken){
      // Google requires a short delay before using next_page_token
      usleep(2200000);
      $params=['pagetoken'=>$nextToken,'key'=>$key];
    } else {
      $params=['query'=>$q,'location'=>$lat.','.$lng,'radius'=>$radius_m,'language'=>$lang,'region'=>$region,'key'=>$key];
    }
    $url='https://maps.googleapis.com/maps/api/place/textsearch/json?'.http_build_query($params);
    $data=json_get($url);
    if(!$data){ $error='no_response'; break; }
    foreach(($data['results']??[]) as $r){
      if(!isset($r['place_id'])) continue;
      $addr=$r['formatted_address']??'';
      $parts = $addr!=='' ? array_map('trim', explode(',', $addr)) : [];
      $country = ($parts && isset($parts[count($parts)-1])) ? $parts[count($parts)-1] : '';
      $city = ($parts && isset($parts[count($parts)-2])) ? $parts[count($parts)-2] : '';
      $gl=$r['geometry']['location']??[];
      $olat=isset($gl['lat'])?(float)$gl['lat']:null; $olon=isset($gl['lng'])?(float)$gl['lng']:null;
      $ids[]=['place_id'=>$r['place_id'],'name'=>$r['name']??'','city'=>$city,'country'=>$country,'lat'=>$olat,'lng'=>$olon];
    }
    $status = $data['status'] ?? '';
    if(isset($data['next_page_token']) && $data['next_page_token'] && $status==='OK'){
      $nextToken = $data['next_page_token'];
    } else {
      $nextToken = null;
    }
  } while($nextToken && ( (int)$pages <= 0 || $page < (int)$pages));
  return ['ids'=>$ids, 'error'=>$error];
}

/* Google Details (name, phone, geometry) */
function provider_google_details($place_id,$lang,$region,$key){
  if(!$key) return null;
  $params=['place_id'=>$place_id,'language'=>$lang,'region'=>$region,'fields'=>'name,formatted_phone_number,geometry','key'=>$key];
  $url='https://maps.googleapis.com/maps/api/place/details/json?'.http_build_query($params);
  $data=json_get($url);
  if(!$data || !isset($data['result'])) return null;
  $res=$data['result'];
  $name = $res['name'] ?? '';
  $phone = $res['formatted_phone_number'] ?? '';
  $gl = $res['geometry']['location'] ?? [];
  $lat = isset($gl['lat']) ? (float)$gl['lat'] : null;
  $lng = isset($gl['lng']) ? (float)$gl['lng'] : null;
  // Count towards daily cap only when we receive a result
  try{ counter_inc('google_details', 1); }catch(Throwable $e){}
  return ['name'=>$name,'phone'=>$phone,'lat'=>$lat,'lng'=>$lng];
}

/* Orchestration across providers */
function orchestrate_fetch($opts){
  $pdo = db();
  $q = trim((string)($opts['q'] ?? ''));
  $ll = trim((string)($opts['ll'] ?? ''));
  $radius_km = max(1, (float)($opts['radius_km'] ?? 25));
  $lang = (string)($opts['lang'] ?? 'ar');
  $region = (string)($opts['region'] ?? 'sa');
  $key = (string)($opts['google_key'] ?? '');
  $fsq = (string)($opts['foursquare_key'] ?? '');
  $preview = !empty($opts['preview']);
  $role = (string)($opts['role'] ?? 'admin');
  $user_id = (int)($opts['user_id'] ?? 0);
  $city_hint = trim((string)($opts['city_hint'] ?? ''));
  $exhaustive = isset($opts['exhaustive']) ? (bool)$opts['exhaustive'] : (get_setting('fetch_exhaustive','0')==='1');
  $ignore_tile_ttl = !empty($opts['ignore_tile_ttl']);
  // Providers order from settings (fallback to default)
  $provStr = (string)get_setting('provider_order','osm,foursquare,mapbox,radar,google');
  $providers = array_values(array_filter(array_map('trim', explode(',', $provStr)), function($p){ return $p!==''; }));
  if(empty($providers)){ $providers = ['osm','foursquare','mapbox','radar','google']; }

  // Parse lat,lng
  if(!preg_match('/^-?\d+(?:\.\d+)?,\s*-?\d+(?:\.\d+)?$/', $ll)){
    return ['preview'=>0,'added'=>0,'by'=>[],'cap_remaining'=>cap_remaining_google_details(),'errors'=>['bad_ll'],'from_tile_cache'=>false];
  }
  $parts = array_map('trim', explode(',', $ll));
  $lat = (float)$parts[0];
  $lng = (float)$parts[1];

  $tile_key = tile_key_for($q,$ll,$radius_km);
  $allow_tile = tile_allowed($tile_key);
  $summary=['preview'=>0,'added'=>0,'by'=>[],'cap_remaining'=>cap_remaining_google_details(),'errors'=>[],'from_tile_cache'=>!$allow_tile,'provider_order'=>implode(',', $providers),'points_scanned'=>0];
  // If tile not allowed and not exhaustive and not preview, short-circuit early
  if(!$allow_tile && !$exhaustive && !$preview && !$ignore_tile_ttl){ return $summary; }

  // Build scan grid
  $points = [ [ $lat, $lng ] ];
  if($exhaustive){
    $step_km = max(0.5, (float)get_setting('exhaustive_grid_step_km','2'));
    $max_pts = max(1, (int)get_setting('exhaustive_max_points','400'));
    $R = max(0.2, (float)$radius_km);
    $degPerKmLat = 1.0/111.0; $degPerKmLon = 1.0/(111.0 * max(0.1, cos(deg2rad($lat))));
    $pts=[];
    for($dy=-$R; $dy<=$R; $dy+=$step_km){
      for($dx=-$R; $dx<=$R; $dx+=$step_km){
        $dist = sqrt($dx*$dx + $dy*$dy); if($dist > $R + 1e-6) continue;
        $plat = $lat + ($dy * $degPerKmLat);
        $plng = $lng + ($dx * $degPerKmLon);
        $pts[] = [ $plat, $plng ];
        if(count($pts) >= $max_pts){ break 2; }
      }
    }
    array_unshift($pts, [ $lat, $lng ]);
    $uniq=[]; foreach($pts as $p){ $k = sprintf('%.5f,%.5f',$p[0],$p[1]); $uniq[$k]=$p; } $points = array_values($uniq);
  }
  $summary['points_scanned'] = count($points);

  // Helper to insert/update one row into leads with geo classification and dedupe on phone
  $process_row = function(array $r, string $source) use (&$summary, $pdo, $role, $user_id, $city_hint){
    $phone=preg_replace('/\D+/','',$r['phone']??''); if($phone==='') return false;
    $rcity = ($city_hint !== '') ? $city_hint : ($r['city'] ?? '');
    // Geo classify
    $geo=null; if(isset($r['lat'],$r['lng']) && $r['lat']!==null && $r['lng']!==null){ require_once __DIR__.'/geo.php'; $geo=geo_classify_point((float)$r['lat'], (float)$r['lng'],'SA'); }
    if(!$geo && $rcity!==''){ require_once __DIR__.'/geo.php'; $geo=geo_classify_text($rcity,null,'SA'); }
    $gc=$geo['country']??null; $gr=$geo['region_code']??null; $gci=isset($geo['city_id'])?(int)$geo['city_id']:null; $gdi=isset($geo['district_id'])?(int)$geo['district_id']:null; $gconf=isset($geo['confidence'])?(float)$geo['confidence']:null;
    try{
      $pdo->prepare("INSERT INTO leads(phone,name,city,country,created_at,source,created_by_user_id,lat,lon,geo_country,geo_region_code,geo_city_id,geo_district_id,geo_confidence) VALUES(?,?,?,?,datetime('now'),?, ?,?, ?,?,?,?,?)")
          ->execute([$phone,$r['name']??'',$rcity,$r['country']??'', $source, $user_id, isset($r['lat'])?$r['lat']:null, isset($r['lng'])?$r['lng']:null, $gc,$gr,$gci,$gdi,$gconf]);
      if($role==='agent'){ $pdo->prepare("INSERT INTO assignments(lead_id,agent_id,status,assigned_at) VALUES(last_insert_rowid(),?, ?, datetime('now'))")->execute([$user_id,'new']); }
      $summary['added']++;
      return true;
    }catch(Throwable $e){
      if($gconf){ try{ $pdo->prepare("UPDATE leads SET lat=COALESCE(lat,:lat), lon=COALESCE(lon,:lon), geo_country=COALESCE(geo_country,:gc), geo_region_code=COALESCE(geo_region_code,:gr), geo_city_id=COALESCE(geo_city_id,:gci), geo_district_id=COALESCE(geo_district_id,:gdi), geo_confidence=MAX(geo_confidence, :gconf), name=COALESCE(NULLIF(name,''), :nm) WHERE phone=:ph")
        ->execute([':lat'=>isset($r['lat'])?$r['lat']:null, ':lon'=>isset($r['lng'])?$r['lng']:null, ':gc'=>$gc, ':gr'=>$gr, ':gci'=>$gci, ':gdi'=>$gdi, ':gconf'=>$gconf, ':nm'=>$r['name']??'', ':ph'=>$phone]); }catch(Throwable $e2){} }
      return false;
    }
  };

  // Iterate providers in the configured order
  foreach($providers as $prov){
    if($prov === 'osm'){
      $count=0;
      foreach($points as $pt){ $rows=provider_osm($q,$pt[0],$pt[1],$radius_km); $count+=count($rows); foreach($rows as $r){ if(!empty($r['external_id'])){ $c=cache_get('osm',$r['external_id']); if(!$c && !empty($r['phone'])) cache_set('osm',$r['external_id'],$r); } if($preview){ $summary['preview']++; continue; } $process_row($r,'osm'); } }
      $summary['by']['osm']=$count;
    }
    else if($prov === 'foursquare'){
      $count=0;
      foreach($points as $pt){ $rows=provider_foursquare($q,$pt[0],$pt[1],$radius_km,$fsq,$exhaustive); $count+=count($rows); foreach($rows as $r){ if(!empty($r['external_id'])){ $c=cache_get('foursquare',$r['external_id']); if(!$c && !empty($r['phone'])) cache_set('foursquare',$r['external_id'],$r); } if($preview){ $summary['preview']++; continue; } $process_row($r,'foursquare'); } }
      $summary['by']['foursquare']=$count;
    }
    else if($prov === 'mapbox'){
      $count=0;
      foreach($points as $pt){ $rows=provider_mapbox($q,$pt[0],$pt[1],$radius_km,get_setting('mapbox_api_key','')); $count+=count($rows); foreach($rows as $r){ if(!empty($r['external_id'])){ $c=cache_get('mapbox',$r['external_id']); if(!$c && !empty($r['phone'])) cache_set('mapbox',$r['external_id'],$r); } if($preview){ $summary['preview']++; continue; } $process_row($r,'mapbox'); } }
      $summary['by']['mapbox']=$count;
    }
    else if($prov === 'radar'){
      $count=0;
      foreach($points as $pt){ $rows=provider_radar($q,$pt[0],$pt[1],$radius_km,get_setting('radar_api_key',''),$exhaustive); $count+=count($rows); foreach($rows as $r){ if(!empty($r['external_id'])){ $c=cache_get('radar',$r['external_id']); if(!$c && !empty($r['phone'])) cache_set('radar',$r['external_id'],$r); } if($preview){ $summary['preview']++; continue; } $process_row($r,'radar'); } }
      $summary['by']['radar']=$count;
    }
    else if($prov === 'google'){
      // Google (preview -> details)
      $gcount = 0;
  // When exhaustive, remove artificial page cap: keep following next_page_token until exhausted
  $pages = $exhaustive ? 0 : 1;
      foreach($points as $pt){
        $pv_prev = provider_google_preview($q, $pt[0], $pt[1], $radius_km, $lang, $region, $key, $pages);
        $ids = is_array($pv_prev) && isset($pv_prev['ids']) && is_array($pv_prev['ids']) ? $pv_prev['ids'] : [];
        $gcount += count($ids);
        if($preview){
          $summary['preview'] += count($ids);
          continue;
        }
        foreach($ids as $it){
          $pid = $it['place_id'] ?? null; if(!$pid) continue;
          $name = $it['name'] ?? '';
          $city_from_prev = $it['city'] ?? '';
          $country_from_prev = $it['country'] ?? '';
          $c = cache_get('google', $pid);
          $phone = '';
          $latIt = $it['lat'] ?? null; $lngIt = $it['lng'] ?? null;
          if($c && !empty($c['phone'])){
            $phone = preg_replace('/\D+/', '', (string)$c['phone']);
          } else {
            if(cap_remaining_google_details() <= 0){
              $summary['errors'][] = 'CAP_REACHED';
              break; // stop details for this point; cap reached
            }
            $det = provider_google_details($pid, $lang, $region, $key);
            if($det){
              $name = $det['name'] ?: $name;
              $phone = preg_replace('/\D+/', '', (string)($det['phone'] ?? ''));
              if(isset($det['lat'])) $latIt = $det['lat'];
              if(isset($det['lng'])) $lngIt = $det['lng'];
              if(!empty($det['phone'])) cache_set('google', $pid, ['phone'=>$det['phone'], 'name'=>$name]);
            }
          }
          if($phone === '') continue;
          $rcity = ($city_hint !== '') ? $city_hint : ($city_from_prev ?: '');
          // Geo classify
          $geo = null;
          if($latIt !== null && $lngIt !== null){ require_once __DIR__.'/geo.php'; $geo = geo_classify_point((float)$latIt, (float)$lngIt, 'SA'); }
          if(!$geo && $rcity !== ''){ require_once __DIR__.'/geo.php'; $geo = geo_classify_text($rcity, null, 'SA'); }
          $gc = $geo['country'] ?? null; $gr = $geo['region_code'] ?? null; $gci = isset($geo['city_id']) ? (int)$geo['city_id'] : null; $gdi = isset($geo['district_id']) ? (int)$geo['district_id'] : null; $gconf = isset($geo['confidence']) ? (float)$geo['confidence'] : null;
          try{
            $pdo->prepare("INSERT INTO leads(phone,name,city,country,created_at,source,created_by_user_id,lat,lon,geo_country,geo_region_code,geo_city_id,geo_district_id,geo_confidence) VALUES(?,?,?,?,datetime('now'),'google',?, ?,?, ?,?,?,?,?)")
                ->execute([$phone, $name, $rcity, $country_from_prev, $user_id, $latIt, $lngIt, $gc, $gr, $gci, $gdi, $gconf]);
            if($role === 'agent'){
              $pdo->prepare("INSERT INTO assignments(lead_id,agent_id,status,assigned_at) VALUES(last_insert_rowid(),?, ?, datetime('now'))")
                  ->execute([$user_id, 'new']);
            }
            $summary['added']++;
          } catch(Throwable $e){
            if($gconf){
              try{
                $pdo->prepare("UPDATE leads SET lat=COALESCE(lat,:lat), lon=COALESCE(lon,:lon), geo_country=COALESCE(geo_country,:gc), geo_region_code=COALESCE(geo_region_code,:gr), geo_city_id=COALESCE(geo_city_id,:gci), geo_district_id=COALESCE(geo_district_id,:gdi), geo_confidence=MAX(geo_confidence, :gconf), name=COALESCE(NULLIF(name,''), :nm) WHERE phone=:ph")
                    ->execute([':lat'=>$latIt, ':lon'=>$lngIt, ':gc'=>$gc, ':gr'=>$gr, ':gci'=>$gci, ':gdi'=>$gdi, ':gconf'=>$gconf, ':nm'=>$name, ':ph'=>$phone]);
              } catch(Throwable $e2){}
            }
          }
        } // end foreach ids
      } // end foreach points
      $summary['by']['google_preview'] = $gcount;
    }
    
  }

  // Touch tile and finalize
  tile_touch($tile_key,$q,$ll,$radius_km,implode(',',$providers),$summary['preview'],$summary['added']);
  $summary['cap_remaining']=cap_remaining_google_details();
  return $summary;
}
 

