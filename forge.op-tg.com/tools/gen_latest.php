<?php
// CLI tool: php tools/gen_latest.php
// Generates storage/releases/latest.json from the newest installer in storage/releases or fallback.
require_once __DIR__ . '/../bootstrap.php';
date_default_timezone_set('UTC');

function pick_latest_installer(): ?string {
  $dir = __DIR__ . '/../storage/releases';
  if (!is_dir($dir)) return null;
  // Prefer versioned installers first, fallback to any .exe
  $cands = glob($dir.'/OPTNexusWorker_Setup_v*.exe');
  if (!$cands || count($cands) === 0) {
    $cands = glob($dir.'/*.exe');
  }
  if (!$cands) return null;
  usort($cands, function($a,$b){ return filemtime($b) <=> filemtime($a); });
  return $cands[0];
}

$file = pick_latest_installer();
if(!$file){
  fwrite(STDERR, "No installer found in storage/releases\n");
  exit(1);
}
$rel = str_replace(realpath(PROJ_ROOT), '', realpath($file));
$rel = str_replace('\\','/',$rel);
if($rel && $rel[0] !== '/') $rel = '/'.ltrim($rel,'/');
$size = filesize($file);
$mtime = gmdate('c', filemtime($file));
$sha256 = hash_file('sha256', $file);
$overrideUrl = getenv('LATEST_URL');
if (!$overrideUrl && isset($argv) && count($argv) > 1) { $overrideUrl = $argv[1]; }
$out = [
  'version' => '1.4.1',
  'size' => $size,
  'sha256' => $sha256,
  'last_modified' => $mtime,
  'url' => $overrideUrl ? $overrideUrl : $rel,
  'channel' => 'stable'
];
$path = __DIR__ . '/../storage/releases/latest.json';
file_put_contents($path, json_encode($out, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
echo "Wrote latest.json => $path\n";
