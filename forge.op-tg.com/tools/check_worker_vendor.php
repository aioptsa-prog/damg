<?php
// Quick probe to verify portable worker packaging prerequisites on server
// Usage: php tools/check_worker_vendor.php
// Exit code: 0 when acceptable, 1 when missing critical pieces

require_once __DIR__ . '/../bootstrap.php';

$root = realpath(__DIR__ . '/..');
$workerDir = $root . '/worker';
$paths = [
  'node_runtime' => [
    $workerDir . '/node',
    $root . '/storage/vendor/node-win64',
    $root . '/storage/vendor/node',
    $workerDir . '/vendor/node-win64',
  ],
  'node_modules' => [
    $workerDir . '/node_modules',
    $root . '/storage/vendor/node_modules',
    $workerDir . '/vendor/node_modules',
  ],
  'playwright' => [
    $workerDir . '/ms-playwright',
    $root . '/storage/vendor/ms-playwright',
    $workerDir . '/vendor/ms-playwright',
  ],
];

function pickDir(array $cands){ foreach($cands as $p){ if(is_dir($p)) return realpath($p) ?: $p; } return null; }

$nodeDir = pickDir($paths['node_runtime']);
$modsDir = pickDir($paths['node_modules']);
$pwDir   = pickDir($paths['playwright']);

$zipExt  = class_exists('ZipArchive');
$writable = is_dir($root . '/storage/releases') ? is_writable($root . '/storage/releases') : is_writable($root . '/storage');

$ok = (bool)$nodeDir && (bool)$modsDir && $zipExt && $writable;

$detail = [
  'root' => $root,
  'node_runtime' => $nodeDir,
  'node_modules' => $modsDir,
  'playwright' => $pwDir,
  'ziparchive_ext' => $zipExt,
  'storage_releases_writable' => $writable,
  'recommendation' => !$ok ? 'Ensure node runtime and node_modules are present under storage/vendor/, enable ZipArchive, and make storage/releases writable.' : 'OK',
];

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok'=>$ok] + $detail, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
exit($ok?0:1);
