<?php
// Fetch Saudi cities from OpenStreetMap (Overpass API) and insert into sa_geo.db.
// Usage: php tools/geo/sa_osm_cities_fetch.php
if (php_sapi_name() !== 'cli') { echo "CLI only\n"; exit(1); }
require_once __DIR__ . '/../../bootstrap.php';

function http_get_raw($url){
  $ch=curl_init($url);
  curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>120,CURLOPT_SSL_VERIFYPEER=>false, CURLOPT_USERAGENT=>'NexusOps-Geo/1.0 (+https://op-tg.com)']);
  $raw=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
  if($code!==200 || !$raw){ fwrite(STDERR, "HTTP $code $err\n"); return null; }
  return $raw;
}
function json_get_raw($url){ $raw=http_get_raw($url); if(!$raw) return null; $j=json_decode($raw,true); return is_array($j)?$j:null; }

$dbPath = __DIR__ . '/../../storage/data/geo/sa/sa_geo.db';
if(!is_file($dbPath)){ fwrite(STDERR, "Run sa_geo_init.php first.\n"); exit(1); }
$pdo = new PDO('sqlite:'.$dbPath); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// Load regions for nearest assignment
$regions = $pdo->query("SELECT code,name_ar,lat,lon FROM regions WHERE lat IS NOT NULL AND lon IS NOT NULL")->fetchAll();
if(!$regions){ fwrite(STDERR, "Seed regions first.\n"); exit(2); }
function nearest_region_code($lat,$lon,$regions){
  $best=null; $min=null;
  foreach($regions as $r){ $dlat=deg2rad($lat-$r['lat']); $dlon=deg2rad($lon-$r['lon']); $a=sin($dlat/2)**2 + cos(deg2rad($lat))*cos(deg2rad($r['lat']))*sin($dlon/2)**2; $c=2*atan2(sqrt($a), sqrt(1-$a)); $km=6371*$c; if($min===null||$km<$min){ $min=$km; $best=$r['code']; } }
  return $best ?: null;
}

// Overpass query: SA boundary by ISO3166-1, places city|town
$q = <<<'Q'
[out:json][timeout:60];
area["ISO3166-1"="SA"][admin_level=2]->.sa;
(
  node["place"~"city|town"](area.sa);
  way["place"~"city|town"](area.sa);
  relation["place"~"city|town"](area.sa);
);
out center;
Q;
$url = 'https://overpass-api.de/api/interpreter?data='.urlencode($q);
$resp = json_get_raw($url);
if(!$resp || empty($resp['elements'])){ fwrite(STDERR, "No elements returned from Overpass.\n"); exit(3); }

$ins = $pdo->prepare("INSERT INTO cities(region_code,name_ar,name_en,alt_names,lat,lon,population,wikidata) VALUES(?,?,?,?,?,?,?,?)");
$upd = $pdo->prepare("UPDATE cities SET region_code=?, name_ar=?, name_en=?, alt_names=?, lat=?, lon=?, population=?, wikidata=? WHERE id=?");

$inserted=0; $updated=0; $seen=0;
$pdo->beginTransaction();
foreach($resp['elements'] as $el){ $seen++;
  $tags = $el['tags'] ?? [];
  $name = $tags['name:ar'] ?? ($tags['name:en'] ?? ($tags['name'] ?? null)); if(!$name) continue;
  $name_ar = $tags['name:ar'] ?? null; $name_en = $tags['name:en'] ?? ($tags['int_name'] ?? null);
  $lat = isset($el['lat']) ? (float)$el['lat'] : (isset($el['center']['lat']) ? (float)$el['center']['lat'] : null);
  $lon = isset($el['lon']) ? (float)$el['lon'] : (isset($el['center']['lon']) ? (float)$el['center']['lon'] : null);
  if($lat===null||$lon===null) continue;
  $rc = nearest_region_code($lat,$lon,$regions);
  $alt = [];
  foreach(['alt_name','alt_name:ar','alt_name:en'] as $k){ if(!empty($tags[$k])) $alt[] = $tags[$k]; }
  $alt_names = $alt ? implode('|', $alt) : null;
  $pop = isset($tags['population']) ? (int)$tags['population'] : null;
  $wd  = $tags['wikidata'] ?? null;
  // naive upsert by name+coords
  $exists = $pdo->prepare("SELECT id FROM cities WHERE name_ar=:ar AND ABS(lat-:lat)<0.005 AND ABS(lon-:lon)<0.005 LIMIT 1");
  $exists->execute([':ar'=>$name_ar ?: $name, ':lat'=>$lat, ':lon'=>$lon]);
  $row = $exists->fetch();
  if($row){ $upd->execute([$rc, $name_ar ?: $name, $name_en, $alt_names, $lat, $lon, $pop, $wd, $row['id']]); $updated++; }
  else { $ins->execute([$rc, $name_ar ?: $name, $name_en, $alt_names, $lat, $lon, $pop, $wd]); $inserted++; }
}
$pdo->commit();

echo json_encode(['ok'=>true,'seen'=>$seen,'inserted'=>$inserted,'updated'=>$updated], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
exit(0);
