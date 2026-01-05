<?php
// Fetch Saudi districts (neighbourhoods/suburbs/quarters) from OpenStreetMap (Overpass API)
// and insert into sa_geo.db, assigning each to the nearest city (<= 60km).
// Usage: php tools/geo/sa_osm_districts_fetch.php
if (php_sapi_name() !== 'cli') { echo "CLI only\n"; exit(1); }

$dbPath = __DIR__ . '/../../storage/data/geo/sa/sa_geo.db';
if(!is_file($dbPath)){ fwrite(STDERR, "Run tools/geo/sa_geo_init.php then tools/geo/sa_osm_cities_fetch.php first.\n"); exit(1); }
$pdo = new PDO('sqlite:'.$dbPath); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

function http_get_raw($url){
  $ch=curl_init($url);
  curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>180,CURLOPT_SSL_VERIFYPEER=>false, CURLOPT_USERAGENT=>'NexusOps-Geo/1.0 (+https://op-tg.com)']);
  $raw=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
  if($code!==200 || !$raw){ fwrite(STDERR, "HTTP $code $err\n"); return null; }
  return $raw;
}
function json_get_raw($url){ $raw=http_get_raw($url); if(!$raw) return null; $j=json_decode($raw,true); return is_array($j)?$j:null; }

$cc = (int)$pdo->query("SELECT COUNT(*) c FROM cities")->fetch()['c'];
if($cc===0){ fwrite(STDERR, "No cities found. Run tools/geo/sa_osm_cities_fetch.php first.\n"); exit(2); }
$cities = $pdo->query("SELECT id,name_ar,lat,lon FROM cities WHERE lat IS NOT NULL AND lon IS NOT NULL")->fetchAll();
if(!$cities){ fwrite(STDERR, "Cities missing coords.\n"); exit(3); }

function nearest_city($lat,$lon,$cities){
  $best=null; $min=null;
  foreach($cities as $c){
    $dlat=deg2rad($lat-$c['lat']); $dlon=deg2rad($lon-$c['lon']);
    $a=sin($dlat/2)**2 + cos(deg2rad($lat))*cos(deg2rad($c['lat']))*sin($dlon/2)**2; 
    $cang=2*atan2(sqrt($a), sqrt(1-$a)); $km=6371*$cang; 
    if($min===null||$km<$min){ $min=$km; $best=$c; }
  }
  return [$best,$min];
}

$q = <<<'Q'
[out:json][timeout:180];
area["ISO3166-1"="SA"][admin_level=2]->.sa;
(
  node["place"~"neighbourhood|suburb|quarter|district"](area.sa);
  way["place"~"neighbourhood|suburb|quarter|district"](area.sa);
  relation["place"~"neighbourhood|suburb|quarter|district"](area.sa);
);
out center;
Q;
$url = 'https://overpass-api.de/api/interpreter?data='.urlencode($q);
$resp = json_get_raw($url);
if(!$resp || empty($resp['elements'])){ fwrite(STDERR, "No elements returned from Overpass.\n"); exit(4); }

$ins = $pdo->prepare("INSERT INTO districts(city_id,name_ar,name_en,lat,lon) VALUES(?,?,?,?,?)");
$upd = $pdo->prepare("UPDATE districts SET city_id=?, name_ar=?, name_en=?, lat=?, lon=? WHERE id=?");
$exByName = $pdo->prepare("SELECT d.id FROM districts d JOIN cities c ON c.id=d.city_id WHERE d.name_ar=:ar AND ABS(d.lat-:lat)<0.01 AND ABS(d.lon-:lon)<0.01 LIMIT 1");

$inserted=0; $updated=0; $skipped=0; $seen=0;
$pdo->beginTransaction();
foreach($resp['elements'] as $el){ $seen++;
  $tags=$el['tags']??[];
  $name = $tags['name:ar'] ?? ($tags['name'] ?? ($tags['name:en'] ?? null)); if(!$name) { $skipped++; continue; }
  $name_ar = $tags['name:ar'] ?? $name; $name_en = $tags['name:en'] ?? null;
  $lat = isset($el['lat']) ? (float)$el['lat'] : (isset($el['center']['lat']) ? (float)$el['center']['lat'] : null);
  $lon = isset($el['lon']) ? (float)$el['lon'] : (isset($el['center']['lon']) ? (float)$el['center']['lon'] : null);
  if($lat===null||$lon===null){ $skipped++; continue; }
  [$city,$km] = nearest_city($lat,$lon,$cities);
  if(!$city){ $skipped++; continue; }
  if($km>60){ $skipped++; continue; }
  $exByName->execute([':ar'=>$name_ar, ':lat'=>$lat, ':lon'=>$lon]); $row=$exByName->fetch();
  if($row){ $upd->execute([$city['id'],$name_ar,$name_en,$lat,$lon,$row['id']]); $updated++; }
  else { $ins->execute([$city['id'],$name_ar,$name_en,$lat,$lon]); $inserted++; }
}
$pdo->commit();

echo json_encode(['ok'=>true,'seen'=>$seen,'inserted'=>$inserted,'updated'=>$updated,'skipped'=>$skipped], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
exit(0);
