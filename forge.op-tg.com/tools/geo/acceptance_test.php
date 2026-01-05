<?php
// php tools/geo/acceptance_test.php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/geo.php';

$gdb = geo_db('SA');
$cities = $gdb->query('SELECT id, name_ar, lat, lon FROM cities WHERE lat IS NOT NULL AND lon IS NOT NULL LIMIT 200')->fetchAll();
if(count($cities) < 100){ fwrite(STDERR, "Need >=100 cities with lat/lon in sa_geo.db for acceptance test\n"); exit(2); }

$sample = array_slice($cities, 0, 120);
$ok=0; $n=count($sample); $times=[];
foreach($sample as $c){
  $lat = (float)$c['lat']; $lon = (float)$c['lon'];
  $t0 = microtime(true);
  $res = geo_classify_point($lat, $lon, 'SA');
  $dt = (microtime(true)-$t0)*1000.0; $times[]=$dt;
  if(($res['city_id']??null) === (int)$c['id']) $ok++;
}
$acc = $ok/$n*100.0;
$p50 = percentile($times,50); $p95=percentile($times,95);
echo json_encode(['n'=>$n,'ok'=>$ok,'acc_percent'=>$acc,'p50_ms'=>$p50,'p95_ms'=>$p95], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),"\n";
if($acc<98.0){ exit(3); }
if($p50>50.0){ fwrite(STDERR, "Median classification time > 50ms\n"); }

function percentile(array $arr, int $p){ sort($arr); $k = (int)ceil(($p/100)*count($arr))-1; return $arr[max(0,min($k,count($arr)-1))]; }