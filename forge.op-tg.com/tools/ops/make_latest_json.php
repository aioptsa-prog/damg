<?php
// CLI: generate latest_<channel>.json for worker updates
// Usage: php tools/ops/make_latest_json.php --channel canary --file path\to\worker.exe --url https://example.com/downloads/worker.exe --version 1.2.3
if (php_sapi_name() !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }
require_once __DIR__ . '/../../bootstrap.php';

$opts = getopt('', ['channel:', 'file:', 'url:', 'version:']);
$channel = isset($opts['channel']) ? strtolower(trim((string)$opts['channel'])) : '';
$file = isset($opts['file']) ? (string)$opts['file'] : '';
$url = isset($opts['url']) ? (string)$opts['url'] : '';
$version = isset($opts['version']) ? (string)$opts['version'] : '';

if(!in_array($channel, ['stable','canary','beta'], true)){
  fwrite(STDERR, "--channel must be stable|canary|beta\n"); exit(2);
}
if($file===''){ fwrite(STDERR, "--file required\n"); exit(2); }
if(!is_file($file)){ fwrite(STDERR, "file not found: $file\n"); exit(3); }
if($url===''){ fwrite(STDERR, "--url required\n"); exit(2); }
if($version===''){ fwrite(STDERR, "--version required\n"); exit(2); }

$size = filesize($file);
$sha256 = hash_file('sha256', $file);
$payload = [
  'version' => $version,
  'url' => $url,
  'size' => $size,
  'sha256' => $sha256,
  'ts' => gmdate('c')
];
$root = realpath(__DIR__.'/../../');
$outPath = $root . DIRECTORY_SEPARATOR . 'latest_' . $channel . '.json';
file_put_contents($outPath, json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));

echo json_encode(['ok'=>true,'channel'=>$channel,'file'=>$outPath,'meta'=>$payload], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
