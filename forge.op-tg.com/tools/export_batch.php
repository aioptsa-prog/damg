<?php
// CLI: Export large Places batch to CSV with streaming
// Usage: php tools/export_batch.php --batch <batch_id> --out storage/exports/places_<batch_id>.csv
if (php_sapi_name() !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }
require_once __DIR__ . '/../bootstrap.php';

function parse_args($argv){
  $out = ['batch'=>null,'out'=>null];
  for($i=1;$i<count($argv);$i++){
    $a=$argv[$i];
    if($a==='--batch'){ $out['batch'] = $argv[++$i] ?? null; continue; }
    if($a==='--out'){ $out['out'] = $argv[++$i] ?? null; continue; }
  }
  return $out;
}

function validate_batch($b){ return (bool)preg_match('/^[A-Za-z0-9_\-:\.]{1,64}$/', $b); }

try{
  $args = parse_args($argv);
  $bid = $args['batch'];
  $outPath = $args['out'] ?: null;
  if(!$bid || !validate_batch($bid)){
    fwrite(STDERR, "Missing or invalid --batch. Use alnum/_-:. up to 64 chars.\n");
    exit(2);
  }
  if(!$outPath){
    $outPath = __DIR__ . '/../storage/exports/places_'.preg_replace('/[^A-Za-z0-9_\-:\.]/','_',$bid).'.csv';
  }
  $dir = dirname($outPath);
  if(!is_dir($dir)){
    if(!@mkdir($dir, 0777, true) && !is_dir($dir)){
      fwrite(STDERR, "Cannot create dir: $dir\n"); exit(3);
    }
  }

  $pdo = db();
  // Index hint for performance (idempotent)
  try{ $pdo->exec("CREATE INDEX IF NOT EXISTS idx_places_batch_id ON places(batch_id)"); }catch(Throwable $e){}

  $stCount = $pdo->prepare("SELECT COUNT(*) FROM places WHERE batch_id=?");
  $stCount->execute([$bid]);
  $total = (int)$stCount->fetchColumn();
  if($total<=0){ fwrite(STDERR, "No rows for batch_id=$bid\n"); exit(4); }

  $fh = fopen($outPath, 'wb');
  if(!$fh){ fwrite(STDERR, "Cannot open file for write: $outPath\n"); exit(5); }
  // UTF-8 BOM
  fwrite($fh, "\xEF\xBB\xBF");
  $headers = ['place_id','name','phone','address','lat','lng','website','types_json','source','source_url','collected_at','last_seen_at','batch_id'];
  fputcsv($fh, $headers);

  $page = 0; $perPage = 5000; $written = 0; $start = microtime(true);
  $stmt = $pdo->prepare("SELECT place_id,name,phone,address,lat,lng,website,types_json,source,source_url,collected_at,last_seen_at,batch_id FROM places WHERE batch_id=? LIMIT ? OFFSET ?");
  while(true){
    $stmt->execute([$bid, $perPage, $page*$perPage]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if(!$rows) break;
    foreach($rows as $r){
      fputcsv($fh, [
        $r['place_id']??'', $r['name']??'', $r['phone']??'', $r['address']??'',
        $r['lat']??'', $r['lng']??'', $r['website']??'', $r['types_json']??'',
        $r['source']??'', $r['source_url']??'', $r['collected_at']??'', $r['last_seen_at']??'', $r['batch_id']??''
      ]);
      $written++;
    }
    fflush($fh); // periodic flush
    $page++;
  }
  fclose($fh);
  $dur = round((microtime(true)-$start)*1000);
  echo json_encode(['ok'=>true,'out'=>$outPath,'rows'=>$written,'took_ms'=>$dur], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
  exit(0);
}catch(Throwable $e){
  fwrite(STDERR, $e->getMessage()."\n");
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
  exit(10);
}
