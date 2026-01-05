<?php
require_once __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
$u = require_role('admin');

// Require POST and verify CSRF
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'method_not_allowed']); exit; }
if (!csrf_verify($_POST['csrf'] ?? '')) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'csrf']); exit; }

// Gate: only when explicitly enabled in settings
if (get_setting('enable_self_update','0') !== '1') {
  http_response_code(403);
  echo json_encode(['ok'=>false,'error'=>'disabled']); exit;
}

// Only operate online (not local): heuristic — require HTTP_HOST set and not localhost
$host = $_SERVER['HTTP_HOST'] ?? '';
if (!$host || stripos($host,'localhost')!==false || strpos($host,'127.0.0.1')!==false) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'error'=>'local_env']); exit;
}

$root = realpath(__DIR__.'/..');
$releasesDir = $root . DIRECTORY_SEPARATOR . 'releases';
if (!is_dir($releasesDir)) { @mkdir($releasesDir,0777,true); }
if (!is_dir($releasesDir)) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'releases_missing']); exit; }

// List site-*.zip and pick the latest by name (timestamp suffix)
$files = glob($releasesDir . DIRECTORY_SEPARATOR . 'site-*.zip');
if (!$files) { echo json_encode(['ok'=>false,'error'=>'no_releases']); exit; }
usort($files, function($a,$b){ return strcmp(basename($b), basename($a)); });
$latestZip = $files[0];

// Parse timestamp from filename
if (!preg_match('/site-(\d{8}_\d{6})\.zip$/', basename($latestZip), $m)) {
  echo json_encode(['ok'=>false,'error'=>'bad_name']); exit;
}
$ts = $m[1];
$currentVer = get_setting('app_version','');
if ($currentVer === $ts) { echo json_encode(['ok'=>true,'message'=>'up_to_date','version'=>$ts]); exit; }

// Preflight: ZipArchive, disk space (> size*2)
$zipSize = filesize($latestZip) ?: 0;
if (!class_exists('ZipArchive')) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'zip_missing']); exit; }
$free = @disk_free_space($releasesDir); if ($free!==false && $zipSize && $free < $zipSize*2) { http_response_code(507); echo json_encode(['ok'=>false,'error'=>'no_space']); exit; }

// Enter maintenance: write .htaccess with maintenance rule
$docroot = $root; // default docroot assumption
$ht = "AddDefaultCharset UTF-8\nOptions -Indexes\nDirectoryIndex index.php\n\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteBase /\nRewriteCond %{REQUEST_URI} !^/maintenance\\.html$\nRewriteRule ^ /releases/$ts/maintenance.html [L]\n</IfModule>\n";
@file_put_contents($docroot.'/.htaccess', $ht);
// If hosting docroot is a 'current' subfolder, write there as well
if (is_dir($root.'/current')) { @file_put_contents($root.'/current/.htaccess', $ht); }
@file_put_contents($root.'/storage/maintenance.flag','');

// Extract to releases/<ts>
$target = $releasesDir . DIRECTORY_SEPARATOR . $ts;
@mkdir($target,0777,true);
$zip = new ZipArchive();
if ($zip->open($latestZip)!==true) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'zip_open_failed']); exit; }
// Prevent traversal
for ($i=0; $i<$zip->numFiles; $i++) {
  $name = $zip->getNameIndex($i);
  if ($name === false) continue;
  $norm = str_replace(['\\','..'], ['/',''], $name);
  if ($norm==='') continue;
  $dest = $target . DIRECTORY_SEPARATOR . $norm;
  $destDir = dirname($dest);
  if (!is_dir($destDir)) { @mkdir($destDir,0777,true); }
  if (substr($name,-1)==='/') { if(!is_dir($dest)) @mkdir($dest,0777,true); continue; }
  $stream = $zip->getStream($name);
  if ($stream===false) continue;
  $out = @fopen($dest,'wb'); if(!$out){ fclose($stream); continue; }
  while (!feof($stream)) { $buf = fread($stream, 8192); if($buf===false) break; fwrite($out,$buf); }
  fclose($stream); fclose($out);
}
$zip->close();

// Ensure maintenance.html exists in new release (friendly message if accessed)
if (!is_file($target.'/maintenance.html')) {
  @file_put_contents($target.'/maintenance.html', "<!doctype html><meta charset=\"utf-8\"><title>الصيانة</title><body dir=rtl style=font-family:Tahoma,Arial,sans-serif;background:#0b2239;color:#f1f5f9;display:flex;align-items:center;justify-content:center;height:100vh><div>نقوم حالياً بأعمال صيانة..</div></body>");
}

// Quick health: index.php exists
if (!is_file($target.'/index.php')) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'health_index_missing']); exit; }

// Activate: rewrite to the new release
$htActive = "AddDefaultCharset UTF-8\nOptions -Indexes\nDirectoryIndex index.php\n\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteBase /\n# Route to active release (allow direct access to /releases/*)\nRewriteCond %{REQUEST_URI} !^/releases/$ts/\nRewriteRule ^(.*)$ /releases/$ts/$1 [L]\n</IfModule>\n";
@file_put_contents($docroot.'/.htaccess', $htActive);
if (is_dir($root.'/current')) { @file_put_contents($root.'/current/.htaccess', $htActive); }

// Exit maintenance: remove flag (optional), update version, log
@unlink($root.'/storage/maintenance.flag');
set_setting('app_version', $ts);

// Append log
try{
  $logDir = $root.'/storage/logs'; if(!is_dir($logDir)) @mkdir($logDir,0777,true);
  $line = sprintf("[%s] self_update to %s by %s\n", date('c'), $ts, (int)($u['id']??0));
  file_put_contents($logDir.'/update.log', $line, FILE_APPEND);
}catch(Throwable $e){}

echo json_encode(['ok'=>true,'version'=>$ts]);
exit;