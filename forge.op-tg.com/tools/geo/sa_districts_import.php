<?php
// Import Saudi districts list (from TSV/CSV) into sa_geo.db districts table.
// Usage: php tools/geo/sa_districts_import.php path/to/sa_districts.tsv
if (php_sapi_name() !== 'cli') { echo "CLI only\n"; exit(1); }
require_once __DIR__ . '/../../bootstrap.php';

$src = $argv[1] ?? '';
if(!$src || !is_file($src)){ fwrite(STDERR, "Provide path to TSV/CSV file.\n"); exit(1); }
$dbPath = __DIR__ . '/../../storage/data/geo/sa/sa_geo.db';
if(!is_file($dbPath)){ fwrite(STDERR, "Run sa_geo_init.php first.\n"); exit(1); }

$pdo = new PDO('sqlite:'.$dbPath); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$ins = $pdo->prepare("INSERT INTO districts(city_id,name_ar,name_en,lat,lon) VALUES(:cid,:ar,:en,:lat,:lon)");
// We need a way to map city names -> ids
$findCity = $pdo->prepare("SELECT id FROM cities WHERE name_ar = :name OR name_en = :name LIMIT 1");

$delim = (str_ends_with(strtolower($src), '.tsv') ? "\t" : ',');
$fh = fopen($src,'r'); if(!$fh){ fwrite(STDERR, "Failed to open file.\n"); exit(2); }
$header = fgetcsv($fh, 0, $delim); $map=[];
if($header){ foreach($header as $i=>$h){ $map[strtolower(trim($h))]=$i; }
  $get = function($row,$key,$def='') use($map){ return isset($map[$key]) ? trim($row[$map[$key]] ?? $def) : $def; };
  $count=0; $pdo->beginTransaction();
  while(($row=fgetcsv($fh,0,$delim))!==false){ if(count($row)<2) continue;
    $cname = $get($row,'city_name'); if($cname==='') continue; $ar=$get($row,'name_ar'); $en=$get($row,'name_en');
    $lat=(float)$get($row,'lat','0'); $lon=(float)$get($row,'lon','0');
    $findCity->execute([':name'=>$cname]); $cid = $findCity->fetch()['id'] ?? null; if(!$cid) continue;
    $ins->execute([':cid'=>$cid, ':ar'=>$ar, ':en'=>$en, ':lat'=>$lat, ':lon'=>$lon]); $count++;
  }
  $pdo->commit(); fclose($fh);
  echo "Imported $count districts.\n"; exit(0);
}

fclose($fh); fwrite(STDERR, "File must have header with columns: city_name,name_ar,name_en,lat,lon.\n"); exit(3);
