<?php
// php tools/rotate_logs.php --max-size=10 --max-days=14 [--path=storage/logs] [--path=worker/logs]
declare(strict_types=1);
$maxSizeMB = 10; $maxDays = 14; $paths = [];
foreach ($argv as $arg) {
  if (preg_match('/--max-size=(\d+)/',$arg,$m)) $maxSizeMB=(int)$m[1];
  if (preg_match('/--max-days=(\d+)/',$arg,$m)) $maxDays=(int)$m[1];
  if (preg_match('/--path=([^\s]+)/',$arg,$m)) $paths[] = $m[1];
}
// Defaults: app logs + worker logs if caller didn't specify paths
if (!$paths){
  $paths = [ __DIR__.'/../storage/logs', __DIR__.'/../worker/logs' ];
}
$now = time(); $rotated=0; $deleted=0; $scanned=[];
foreach ($paths as $dir){
  if(!is_dir($dir)) continue; $scanned[] = realpath($dir) ?: $dir;
  $dh = opendir($dir); if(!$dh) continue;
  while(($f=readdir($dh))!==false){
    if($f==='.'||$f==='..') continue;
    $p = $dir.'/'.$f; if(!is_file($p)) continue;
    // Skip compressed archives
    if(preg_match('/\.(gz|zip|7z|bz2)$/i',$p)) continue;
    $sz = @filesize($p) ?: 0; $mt = @filemtime($p) ?: $now; $ageDays = ($now - $mt)/86400.0;
    if ($ageDays > $maxDays) { @unlink($p); $deleted++; continue; }
    if ($sz > $maxSizeMB*1024*1024) {
      $dst = $p.'.'.date('Ymd_His').'.gz';
      $gz = @gzopen($dst,'wb9'); $in = @fopen($p,'rb');
      if($gz && $in){ while(!feof($in)){ gzwrite($gz, fread($in, 8192)); } fclose($in); gzclose($gz); file_put_contents($p,''); $rotated++; }
    }
  }
  closedir($dh);
}
echo json_encode(['rotated'=>$rotated,'deleted'=>$deleted,'paths'=>$scanned,'max_size_mb'=>$maxSizeMB,'max_days'=>$maxDays], JSON_PRETTY_PRINT),"\n";
