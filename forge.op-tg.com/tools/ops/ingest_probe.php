<?php
// CLI tool: simulate report_results ingestion without HTTP.
// - Creates a minimal internal job
// - Ingests a small batch of leads (some duplicates across runs)
// - Prints { added, duplicates } and usage_counters delta
if (php_sapi_name() !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../lib/classify.php';
require_once __DIR__ . '/../../lib/geo.php';

try{
  $pdo = db();
  $adminId = $pdo->query("SELECT id FROM users WHERE role='admin' ORDER BY id ASC LIMIT 1")->fetchColumn();
  if(!$adminId){ throw new RuntimeException('no_admin_user'); }
  $now = date('Y-m-d H:i:s');
  $q = 'test_cafe_probe'; $ll = get_setting('default_ll','24.7136,46.6753');
  $pdo->prepare("INSERT INTO internal_jobs(requested_by_user_id,role,query,ll,radius_km,lang,region,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?, 'queued', datetime('now'), datetime('now'))")
      ->execute([(int)$adminId,'admin',$q,$ll,(int)get_setting('default_radius_km','5'),get_setting('default_language','ar'),get_setting('default_region','sa')]);
  $job_id = (int)$pdo->lastInsertId();

  $mkItems = function(){
    return [
      [ 'phone'=> '0551234567', 'name'=>'Test Cafe', 'city'=>'الرياض', 'country'=>'sa', 'rating'=>4.2, 'types'=>['cafe','food'], 'lat'=>24.7136, 'lng'=>46.6753, 'provider'=>'web' ],
      [ 'phone'=> '+966551234567', 'name'=>'Test Cafe Duplicate', 'city'=>'الرياض', 'country'=>'sa', 'rating'=>4.1, 'types'=>['cafe'], 'lat'=>24.7137, 'lng'=>46.6754, 'provider'=>'web' ],
      [ 'phone'=> '0559876543', 'name'=>'Another Place', 'city'=>'الرياض', 'country'=>'sa', 'rating'=>3.9, 'types'=>['restaurant'], 'lat'=>24.7140, 'lng'=>46.6760, 'provider'=>'web' ],
    ];
  };

  $ingest = function($items) use ($pdo, $job_id){
    $added=0; $dups=0; $job = $pdo->prepare("SELECT * FROM internal_jobs WHERE id=?"); $job->execute([$job_id]); $job=$job->fetch();
    $insLead = $pdo->prepare("INSERT OR IGNORE INTO leads(
        phone,phone_norm,name,city,country,created_at,source,created_by_user_id,
        rating,website,email,gmap_types,source_url,social,category_id,lat,lon,
        geo_country,geo_region_code,geo_city_id,geo_district_id,geo_confidence)
      VALUES(?,?,?,?,?, ?, ?, ?, ?,?,?,?,?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $insAssign = $pdo->prepare("INSERT OR IGNORE INTO assignments(lead_id,agent_id,status,assigned_at) VALUES(?,?,'new',datetime('now'))");
    foreach($items as $it){
      $name = trim((string)($it['name'] ?? ''));
      $city = trim((string)($it['city'] ?? ''));
      $country = trim((string)($it['country'] ?? ''));
      $digits = preg_replace('/\D+/','', (string)($it['phone'] ?? ''));
      if($digits !== '' && $digits[0] === '0'){ $digits = ltrim($digits, '0'); }
      if((strlen($digits)===9 || strlen($digits)===10) && (mb_strtolower($country)==='sa' || $country==='')){ if(strpos($digits, '966')!==0){ $digits = '966'.$digits; } }
      $phone = $digits; $phone_norm = $digits; if($phone==='') continue;
      $rating = isset($it['rating']) ? (float)$it['rating'] : null;
      $types = is_array($it['types'] ?? null) ? implode(',', $it['types']) : null;
      $lat = isset($it['lat']) ? (float)$it['lat'] : null;
      $lon = isset($it['lng']) ? (float)$it['lng'] : (isset($it['lon']) ? (float)$it['lon'] : null);
      $provider = isset($it['provider']) ? (string)$it['provider'] : 'unknown';
      $day = gmdate('Y-m-d'); $latr = $lat!==null ? round($lat, 3) : null; $lonr = $lon!==null ? round($lon, 3) : null;
      $fpSrc = implode('|', [strtolower($phone_norm), strtolower($provider), strtolower($city), (string)$latr, (string)$lonr, $day]);
      $fingerprint = sha1($fpSrc);
      $cls = classify_lead(['name'=>$name,'gmap_types'=>$types,'city'=>$city,'country'=>$country,'phone'=>$phone]);
      $catId = $cls['category_id'] ?? null;
      $geo = null; if(mb_strtolower($country)==='sa' || $country===''){
        if($lat!==null && $lon!==null){ $geo = geo_classify_point($lat,$lon,'SA'); }
        if(!$geo && $city!==''){ $geo = geo_classify_text($city,null,'SA'); }
      }
      try{
        $insLead->execute([
          $phone,$phone_norm,$name,$city,$country,
          date('Y-m-d H:i:s'),'internal', (int)$job['requested_by_user_id'],
          $rating, null, null, $types, null, null, $catId, $lat, $lon,
          $geo['country']??null,$geo['region_code']??null, isset($geo['city_id'])?(int)$geo['city_id']:null, isset($geo['district_id'])?(int)$geo['district_id']:null, isset($geo['confidence'])?(float)$geo['confidence']:null
        ]);
        if($insLead->rowCount()>0){
          $added++; $leadId = (int)$pdo->lastInsertId();
          try{ $pdo->prepare("INSERT OR IGNORE INTO leads_fingerprints(lead_id,fingerprint,created_at) VALUES(?,?,datetime('now'))")->execute([$leadId,$fingerprint]); }catch(Throwable $e){}
        } else {
          try{ $exists = $pdo->prepare("SELECT 1 FROM leads_fingerprints WHERE fingerprint=? LIMIT 1"); $exists->execute([$fingerprint]); $dup = (bool)$exists->fetch(); }catch(Throwable $e){ $dup=false; }
          if($dup){ $dups++; }
          if($catId){ $st = $pdo->prepare("UPDATE leads SET category_id=COALESCE(category_id, :cid) WHERE phone_norm=:phn OR phone=:ph"); $st->execute([':cid'=>$catId, ':phn'=>$phone_norm, ':ph'=>$phone]); }
          if($geo && ($geo['confidence'] ?? 0) > 0){ $st = $pdo->prepare("UPDATE leads SET lat=COALESCE(lat,:lat), lon=COALESCE(lon,:lon), geo_country=COALESCE(geo_country,:gc), geo_region_code=COALESCE(geo_region_code,:gr), geo_city_id=COALESCE(geo_city_id,:gci), geo_district_id=COALESCE(geo_district_id,:gdi), geo_confidence=MAX(geo_confidence, :gconf) WHERE phone_norm=:phn OR phone=:ph"); $st->execute([':lat'=>$lat, ':lon'=>$lon, ':gc'=>$geo['country']??null, ':gr'=>$geo['region_code']??null, ':gci'=>isset($geo['city_id'])?(int)$geo['city_id']:null, ':gdi'=>isset($geo['district_id'])?(int)$geo['district_id']:null, ':gconf'=>isset($geo['confidence'])?(float)$geo['confidence']:null, ':phn'=>$phone_norm, ':ph'=>$phone]); }
        }
      }catch(Throwable $e){}
    }
    // telemetry
    $day = date('Y-m-d');
    $inc = function($kind,$cnt) use ($pdo,$day){ if($cnt<=0) return; $pdo->prepare("INSERT INTO usage_counters(day,kind,count) VALUES(?,?,?) ON CONFLICT(day,kind) DO UPDATE SET count=count+excluded.count")->execute([$day,$kind,$cnt]); };
    $inc('ingest_added', $added);
    $inc('ingest_duplicates', $dups);
    return ['added'=>$added,'duplicates'=>$dups];
  };

  // Snapshot before
  $before = $pdo->query("SELECT kind, SUM(count) cnt FROM usage_counters WHERE day=date('now') AND kind IN ('ingest_added','ingest_duplicates') GROUP BY kind")->fetchAll(PDO::FETCH_KEY_PAIR);
  $r1 = $ingest($mkItems());
  $r2 = $ingest($mkItems()); // second run to create duplicates
  $after = $pdo->query("SELECT kind, SUM(count) cnt FROM usage_counters WHERE day=date('now') AND kind IN ('ingest_added','ingest_duplicates') GROUP BY kind")->fetchAll(PDO::FETCH_KEY_PAIR);
  $res = [ 'ok'=>true, 'job_id'=>$job_id, 'run1'=>$r1, 'run2'=>$r2, 'before'=>$before ?: [], 'after'=>$after ?: [] ];
  echo json_encode($res, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit(0);
}catch(Throwable $e){ fwrite(STDERR, $e->getMessage()."\n"); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit(2); }
