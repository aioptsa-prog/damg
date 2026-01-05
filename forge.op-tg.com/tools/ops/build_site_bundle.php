<?php
// Build a site deployment bundle (ZIP) with selectable modes and manifest.
// Usage: php tools/ops/build_site_bundle.php [--out=<path.zip>] [--with-db] [--mode=site|full] [--include-worker-vendor] [--verbose]
// Defaults: mode=site, out=storage/releases/site_bundle_YYYYmmdd_HHMM.zip

declare(strict_types=1);

// This tool doesn't need DB; avoid pulling migrations or side effects.
if (php_sapi_name() !== 'cli') { fwrite(STDERR, "[ERR] CLI only\n"); exit(1); }
if (!class_exists('ZipArchive')) { fwrite(STDERR, "[ERR] ZipArchive extension is required\n"); exit(2); }

@ini_set('memory_limit', '1024M');
@date_default_timezone_set(@date_default_timezone_get() ?: 'UTC');

$withDb = false; $outPath = ''; $mode = 'site'; $includeWorkerVendor = false; $verbose = false;
foreach ($argv as $a) {
  if (preg_match('/^--out=(.+\.zip)$/i', $a, $m)) { $outPath = $m[1]; }
  if ($a === '--with-db') { $withDb = true; }
  if (preg_match('/^--mode=(site|full)$/i', $a, $m)) { $mode = strtolower($m[1]); }
  if ($a === '--include-worker-vendor') { $includeWorkerVendor = true; }
  if ($a === '--verbose' || $a === '-v') { $verbose = true; }
}
$root = realpath(__DIR__ . '/../../');
if (!$root) { fwrite(STDERR, "[ERR] Could not resolve project root\n"); exit(3); }

// Default output
if ($outPath === '') {
  $relDir = $root . '/storage/releases';
  if (!is_dir($relDir)) { @mkdir($relDir, 0777, true); }
  $outPath = $relDir . '/site_bundle_' . date('Ymd_His') . '.zip';
}

// Ensure parent directory exists and is writable
$parent = dirname($outPath);
if (!is_dir($parent)) { @mkdir($parent, 0777, true); }
if (!is_writable($parent)) {
  $err = [ 'ok'=>false, 'error'=>'Output directory not writable', 'out'=>$outPath ];
  echo json_encode($err, JSON_UNESCAPED_SLASHES), "\n"; exit(6);
}

$zip = new ZipArchive();
$openRes = $zip->open($outPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
if ($openRes !== true) {
  $err = [ 'ok'=>false, 'error'=>'Failed to open zip for writing', 'code'=>$openRes, 'out'=>$outPath ];
  echo json_encode($err, JSON_UNESCAPED_SLASHES), "\n"; exit(4);
}

$rootLen = strlen($root);
$toRel = function(string $p) use ($rootLen): string { return ltrim(str_replace('\\','/', substr($p, $rootLen)), '/'); };

$ignoreDir = function(string $rel) use ($withDb, $mode, $includeWorkerVendor): bool {
  $rel = str_replace('\\','/',$rel);
  // Always exclude these
  $deny = ['.git','/.git','/.github','/.vscode','/.idea','storage/logs','/storage/logs'];
  // In site mode, exclude heavy dev/vendor content; in full mode, include unless includeWorkerVendor=false for worker vendor
  if ($mode === 'site'){
    array_push($deny, 'node_modules','/node_modules','worker/node','/worker/node','worker/node_modules','/worker/node_modules','worker/ms-playwright','/worker/ms-playwright');
  } else { // full
    if (!$includeWorkerVendor){
      array_push($deny, 'worker/ms-playwright','/worker/ms-playwright');
    }
  }
  foreach ($deny as $d) { if (strpos($rel, $d) === 0) return true; }
  // Default: exclude DB unless --with-db
  if (!$withDb && (strpos($rel, 'storage/app.sqlite') === 0)) return true;
  return false;
};

$ignoreFile = function(string $rel) use ($withDb): bool {
  $rel = str_replace('\\','/',$rel);
  $denyFiles = [
    'nexus.op-tg.com.zip',
  ];
  foreach ($denyFiles as $f) { if ($rel === $f) return true; }
  if (!$withDb && $rel === 'storage/app.sqlite') return true;
  // Skip large site bundles to avoid recursion
  if (preg_match('#^storage/releases/site_bundle_#i', $rel)) return true;
  return false;
};

$failAdds = 0; $added = 0; $sampleEntries = [];
$addFile = function(string $absPath) use (&$zip, $toRel, $ignoreFile, &$failAdds, &$added, &$sampleEntries) {
  $rel = $toRel($absPath);
  if ($ignoreFile($rel)) return;
  $ok = $zip->addFile($absPath, $rel);
  if ($ok) {
    $added++;
    if (count($sampleEntries) < 5) { $sampleEntries[] = $rel; }
  } else { $failAdds++; }
};

// Walk the project root recursively with skips
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $fi) {
  /** @var SplFileInfo $fi */
  $abs = $fi->getPathname();
  $rel = $toRel($abs);
  // Skip directories by prefix
  $parts = explode('/', $rel);
  $prefix = '';
  $skip = false;
  for ($i=0; $i<count($parts); $i++) {
    $prefix = $i===0 ? $parts[0] : ($prefix . '/' . $parts[$i]);
    if ($ignoreDir($prefix)) { $skip = true; break; }
  }
  if ($skip) continue;
  if ($fi->isFile()) { $addFile($abs); }
}

// Build a manifest with sizes and sha256 for integrity
$manifest = [
  'built_at' => gmdate('c'),
  'mode' => $mode,
  'with_db' => $withDb,
  'include_worker_vendor' => $includeWorkerVendor,
  'files' => [],
  'stats' => ['added'=>$added, 'failed_adds'=>$failAdds, 'sample'=>$sampleEntries]
];
// Add initial manifest inside the zip
$zip->addFromString('MANIFEST.json', json_encode($manifest, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
$statusBeforeClose = method_exists($zip,'getStatusString') ? $zip->getStatusString() : null;
$closed = @$zip->close();
$statusAfterClose = method_exists($zip,'getStatusString') ? $zip->getStatusString() : null;
if ($closed !== true) {
  // Fallback on Windows: use PowerShell Compress-Archive with a staged directory
  $isWin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' || (PHP_OS_FAMILY ?? '') === 'Windows';
  if ($isWin) {
    $staging = $parent . DIRECTORY_SEPARATOR . '_bundle_staging_' . date('Ymd_His');
    @mkdir($staging, 0777, true);
    // Copy files honoring ignore rules
    $copied = 0; $copyFailed = 0;
    $copyFile = function(string $absPath) use ($staging, $root, $toRel, &$copied, &$copyFailed, $ignoreFile) {
      $rel = $toRel($absPath);
      if ($ignoreFile($rel)) return;
      $dest = $staging . DIRECTORY_SEPARATOR . str_replace(['\\','/'], DIRECTORY_SEPARATOR, $rel);
      $ddir = dirname($dest);
      if (!is_dir($ddir)) { @mkdir($ddir, 0777, true); }
      if (@copy($absPath, $dest)) { $copied++; } else { $copyFailed++; }
    };
    // Walk again to copy files
    $it2 = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it2 as $fi2) {
      /** @var SplFileInfo $fi2 */
      $abs2 = $fi2->getPathname();
      $rel2 = $toRel($abs2);
      // Skip directories by prefix
      $parts2 = explode('/', $rel2);
      $prefix2 = '';
      $skip2 = false;
      for ($j=0; $j<count($parts2); $j++) {
        $prefix2 = $j===0 ? $parts2[0] : ($prefix2 . '/' . $parts2[$j]);
        if ($ignoreDir($prefix2)) { $skip2 = true; break; }
      }
      if ($skip2) continue;
      if ($fi2->isFile()) { $copyFile($abs2); }
    }
    // Write manifest inside staging
    $manifest['stats']['staged_copied'] = $copied; $manifest['stats']['staged_failed'] = $copyFailed;
    @file_put_contents($staging . DIRECTORY_SEPARATOR . 'MANIFEST.json', json_encode($manifest, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    // Build zip via PowerShell Compress-Archive
    $ps = 'powershell -NoProfile -NonInteractive -Command ';
    // Ensure destination parent exists
    if (!is_dir(dirname($outPath))) { @mkdir(dirname($outPath), 0777, true); }
    $cmd = $ps . '"Compress-Archive -Path ' . escapeshellarg($staging . DIRECTORY_SEPARATOR . '*') . ' -DestinationPath ' . escapeshellarg($outPath) . ' -Force -CompressionLevel Optimal"';
    $out = [];$rc = 0;
    @exec($cmd, $out, $rc);
    // Cleanup staging
    $rrmdir = function($dir) use (&$rrmdir) {
      if (!is_dir($dir)) return; $items = scandir($dir); if (!$items) return;
      foreach ($items as $itx) { if ($itx==='.'||$itx==='..') continue; $p=$dir.DIRECTORY_SEPARATOR.$itx; if (is_dir($p)) { $rrmdir($p); } else { @unlink($p); } }
      @rmdir($dir);
    };
    $rrmdir($staging);
    if ($rc === 0 && is_file($outPath) && filesize($outPath) > 0) {
      // Success via fallback, continue to manifest adjacent generation
    } else {
      $err = [ 'ok'=>false, 'error'=>'ZipArchive::close failed and fallback Compress-Archive failed', 'out'=>$outPath, 'added'=>$added, 'failed_adds'=>$failAdds, 'status_before'=>$statusBeforeClose, 'status_after'=>$statusAfterClose, 'fallback_rc'=>$rc, 'fallback_out'=>implode("\n", $out) ];
      echo json_encode($err, JSON_UNESCAPED_SLASHES), "\n"; exit(5);
    }
  } else {
    $err = [ 'ok'=>false, 'error'=>'ZipArchive::close failed', 'out'=>$outPath, 'added'=>$added, 'failed_adds'=>$failAdds, 'status_before'=>$statusBeforeClose, 'status_after'=>$statusAfterClose ];
    echo json_encode($err, JSON_UNESCAPED_SLASHES), "\n"; exit(5);
  }
}

// Compute checksums by streaming from zip (best-effort). For performance, hash select critical files + top-level.
$shaFiles = [
  'bootstrap.php','index.php','config/.env.php','api/health.php','api/heartbeat.php','api/pull_job.php','api/download_worker.php',
  'lib/system.php','lib/security.php','lib/auth.php','admin/dashboard.php','worker/index.js'
];
try{
  $za = new ZipArchive();
  if($za->open($outPath) === true){
    $mani = json_decode($za->getFromName('MANIFEST.json') ?: '[]', true);
    if(!is_array($mani)) $mani = [];
    $mani['files'] = [];
    $n = $za->numFiles;
    // Build quick lookup for targeted sha files
    $shaSet = array_flip($shaFiles);
    for($i=0; $i<$n; $i++){
      $st = $za->statIndex($i);
      if(!$st) continue;
      $name = $st['name']; $size = (int)($st['size'] ?? 0);
      $entry = ['path'=>$name,'size'=>$size];
      if(isset($shaSet[$name])){
        $data = $za->getFromIndex($i);
        if($data !== false){ $entry['sha256'] = hash('sha256', $data); }
      }
      $mani['files'][] = $entry;
    }
    // Write adjacent manifest file .manifest.json (we avoid rewriting the zip again)
    file_put_contents($outPath.'.manifest.json', json_encode($mani, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    $za->close();
  }
}catch(Throwable $e){ /* best-effort */ }

$stat = [ 'ok'=>true, 'out'=>$outPath, 'size'=>(int)@filesize($outPath), 'mode'=>$mode ];
echo json_encode($stat, JSON_UNESCAPED_SLASHES), "\n";
exit(0);
