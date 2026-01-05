<?php
// Verify that a site bundle zip looks complete and consistent with expectations.
// Usage: php tools/ops/verify_site_bundle.php <path.zip>

declare(strict_types=1);
if (php_sapi_name() !== 'cli') { fwrite(STDERR, "[ERR] CLI only\n"); exit(1); }
if (!class_exists('ZipArchive')) { fwrite(STDERR, "[ERR] ZipArchive required\n"); exit(2); }
$zipPath = $argv[1] ?? '';
if ($zipPath === '' || !is_file($zipPath)){ fwrite(STDERR, "Usage: php tools/ops/verify_site_bundle.php <path.zip>\n"); exit(3); }

$za = new ZipArchive();
if ($za->open($zipPath) !== true){ fwrite(STDERR, "[ERR] Could not open zip\n"); exit(4); }
$required = ['bootstrap.php','index.php','config/.env.php','api/health.php','lib/system.php'];
$found = array_fill_keys($required, false);
$hasManifest = false;
for($i=0; $i<$za->numFiles; $i++){
  $st = $za->statIndex($i); if(!$st) continue; $name = $st['name'];
  if($name === 'MANIFEST.json') $hasManifest = true;
  if(isset($found[$name])) $found[$name] = true;
}
$za->close();
$missing = [];
foreach($found as $k=>$v){ if(!$v) $missing[] = $k; }
$ok = (count($missing)===0);
$out = ['ok'=>$ok,'missing'=>$missing,'has_manifest'=>$hasManifest,'zip'=>$zipPath];
echo json_encode($out, JSON_UNESCAPED_SLASHES),"\n";
