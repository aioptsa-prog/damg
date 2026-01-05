<?php
// php tools/geo/gen_hierarchy.php  (emits storage/data/geo/sa/sa_hierarchy.json)
declare(strict_types=1);
$db = new PDO('sqlite:' . __DIR__ . '/../../storage/data/geo/sa/sa_geo.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$regions = $db->query('SELECT code, name_ar, name_en FROM regions ORDER BY code')->fetchAll();
$out = [ 'country'=>'SA', 'regions'=>[] ];
foreach($regions as $r){
  $rc = $r['code'];
  $cities = $db->prepare('SELECT id, name_ar, name_en FROM cities WHERE region_code=? ORDER BY name_ar');
  $cities->execute([$rc]);
  $crows = $cities->fetchAll();
  $cl = [];
  foreach($crows as $c){
    $cid = (int)$c['id'];
    $d = $db->prepare("SELECT id, name_ar, name_en FROM districts WHERE city_id=? ORDER BY name_ar");
    $ds = [];
    try{ $d->execute([$cid]); $ds = $d->fetchAll(); }catch(Throwable $e){}
    $cl[] = [ 'id'=>$cid, 'name_ar'=>$c['name_ar'], 'name_en'=>$c['name_en'], 'districts'=>array_map(fn($x)=>['id'=>(int)$x['id'],'name_ar'=>$x['name_ar'],'name_en'=>$x['name_en']], $ds) ];
  }
  $out['regions'][] = [ 'code'=>$rc, 'name_ar'=>$r['name_ar'], 'name_en'=>$r['name_en'], 'cities'=>$cl ];
}
$path = __DIR__ . '/../../storage/data/geo/sa/sa_hierarchy.json';
file_put_contents($path, json_encode($out, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
echo "Wrote $path\n";