<?php
// Publish latest_{stable,canary}.json under /releases from newest EXE/ZIP in storage/releases
// Usage: php tools/ops/publish_latest.php [channel=stable]
if (php_sapi_name() !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }
require_once __DIR__ . '/../../bootstrap.php';

$channel = isset($argv[1]) ? (string)$argv[1] : 'stable';
$valid = ['stable','canary']; if(!in_array($channel,$valid,true)) $channel='stable';
$root = realpath(__DIR__.'/../../');
$srcDir = $root.'/storage/releases';
$dstDir = $root.'/releases'; if(!is_dir($dstDir)) @mkdir($dstDir, 0777, true);

function pickNewest(array $globPatterns){
  $files = [];
  foreach($globPatterns as $g){ foreach(glob($g)?:[] as $p){ if(is_file($p)) $files[]=$p; } }
  if(!$files) return null;
  usort($files, fn($a,$b)=> (@filemtime($b) <=> @filemtime($a)));
  return $files[0];
}

$exe = pickNewest([ $srcDir.'/*.exe', $root.'/releases/*.exe' ]);
$zip = pickNewest([ $srcDir.'/*Portable*.zip', $srcDir.'/*Worker*.zip', $root.'/releases/*Portable*.zip', $root.'/releases/*Worker*.zip' ]);
$pick = $zip ?: $exe; if(!$pick){ fwrite(STDERR, "No artifacts found in $srcDir\n"); exit(2);} 

$rel = str_replace(realpath(PROJ_ROOT), '', realpath($pick));
$rel = str_replace('\\','/',$rel); if($rel && $rel[0] !== '/') $rel = '/'.ltrim($rel,'/');
$size = @filesize($pick) ?: 0; $mtime = gmdate('c', @filemtime($pick) ?: time()); $sha256 = @hash_file('sha256',$pick) ?: '';
$ver = 'unknown'; $bn = basename($pick); if(preg_match('/v([0-9][^._]*)/i',$bn,$m)) $ver=$m[1];

$out = [ 'version'=>$ver, 'size'=>$size, 'sha256'=>$sha256, 'last_modified'=>$mtime, 'url'=>$rel, 'channel'=>$channel ];
$path = $dstDir.'/latest_'.$channel.'.json';
file_put_contents($path, json_encode($out, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
echo json_encode(['ok'=>true,'wrote'=>$path,'artifact'=>basename($pick)], JSON_PRETTY_PRINT),"\n";
