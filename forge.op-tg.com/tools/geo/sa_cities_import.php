<?php
// Import Saudi cities list (from TSV/CSV) into sa_geo.db cities table.
// Usage: php tools/geo/sa_cities_import.php path/to/sa_cities.tsv
if (php_sapi_name() !== 'cli') { echo "CLI only\n"; exit(1); }
require_once __DIR__ . '/../../bootstrap.php';

$src = $argv[1] ?? '';
if(!$src || !is_file($src)){ fwrite(STDERR, "Provide path to TSV/CSV file.\n"); exit(1); }
$dbPath = __DIR__ . '/../../storage/data/geo/sa/sa_geo.db';
if(!is_file($dbPath)){ fwrite(STDERR, "Run sa_geo_init.php first.\n"); exit(1); }

$pdo = new PDO('sqlite:'.$dbPath); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$ins = $pdo->prepare("INSERT INTO cities(region_code,name_ar,name_en,alt_names,lat,lon,population,wikidata) VALUES(:rc,:ar,:en,:alt,:lat,:lon,:pop,:wd)");

// Auto detect CSV vs TSV
$delim = (str_ends_with(strtolower($src), '.tsv') ? "\t" : ',');
$fh = fopen($src,'r'); if(!$fh){ fwrite(STDERR, "Failed to open file.\n"); exit(2); }
// Try header
$header = fgetcsv($fh, 0, $delim); $map=[];
if($header){ foreach($header as $i=>$h){ $map[strtolower(trim($h))]=$i; }
  $get = function($row,$key,$def='') use($map){ return isset($map[$key]) ? trim($row[$map[$key]] ?? $def) : $def; };
  $count=0; $pdo->beginTransaction();
  while(($row=fgetcsv($fh,0,$delim))!==false){ if(count($row)<2) continue;
    $rc = $get($row,'region_code'); $ar=$get($row,'name_ar'); if($ar==='') continue; $en=$get($row,'name_en'); $alt=$get($row,'alt_names');
    $lat=(float)$get($row,'lat','0'); $lon=(float)$get($row,'lon','0'); $pop=(int)$get($row,'population','0'); $wd=$get($row,'wikidata');
    $ins->execute([':rc'=>$rc, ':ar'=>$ar, ':en'=>$en, ':alt'=>$alt, ':lat'=>$lat, ':lon'=>$lon, ':pop'=>$pop, ':wd'=>$wd]); $count++;
  }
  $pdo->commit(); fclose($fh);
  echo "Imported $count cities.\n"; exit(0);
}
// No header fallback: cols fixed order
fclose($fh); fwrite(STDERR, "File must have header with columns: region_code,name_ar,name_en,alt_names,lat,lon,population,wikidata.\n"); exit(3);
