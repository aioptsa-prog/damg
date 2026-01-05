<?php
declare(strict_types=1);

// latest.php — returns latest.json with robust HTTP semantics and absolute download URLs
// - ETag and Last-Modified
// - Honors If-None-Match / If-Modified-Since (304)
// - HEAD responses with headers only

header('Content-Type: application/json; charset=UTF-8');

$channel = isset($_GET['channel']) ? strtolower(trim((string)$_GET['channel'])) : '';
if($channel !== '' && !in_array($channel, ['stable','canary','beta','dev'], true)) { $channel = 'stable'; }

// Resolve latest.json path:
$activeBase = dirname(__DIR__);
// Prefer global docroot/releases/latest.json (docroot is parent of activeBase)
$docroot = dirname($activeBase);
// Prefer channel-specific latest if requested
$file = '';
if($channel){
    $candCh = $docroot . DIRECTORY_SEPARATOR . 'releases' . DIRECTORY_SEPARATOR . 'latest_' . $channel . '.json';
    if (is_file($candCh)) { $file = $candCh; }
    if(!$file){ $candCh2 = $activeBase . DIRECTORY_SEPARATOR . 'releases' . DIRECTORY_SEPARATOR . 'latest_' . $channel . '.json'; if(is_file($candCh2)) { $file = $candCh2; } }
    if(!$file){ $candCh3 = $activeBase . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'releases' . DIRECTORY_SEPARATOR . 'latest_' . $channel . '.json'; if(is_file($candCh3)) { $file = $candCh3; } }
}
if(!$file){ $file = $docroot . DIRECTORY_SEPARATOR . 'releases' . DIRECTORY_SEPARATOR . 'latest.json'; }
if (!is_file($file)) {
    // Fallback to per-release path
    $cand = $activeBase . DIRECTORY_SEPARATOR . 'releases' . DIRECTORY_SEPARATOR . 'latest.json';
    if (is_file($cand)) { $file = $cand; }
}
if (!is_file($file)) {
    // Final fallback to storage within active release
    $alt = $activeBase . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'releases' . DIRECTORY_SEPARATOR . 'latest.json';
    if (is_file($alt)) { $file = $alt; }
}

if (!is_file($file)) {
    http_response_code(404);
    echo json_encode(['error' => 'latest.json not found'], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

$mtime = filemtime($file) ?: time();
$lastModified = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';

// Load and normalize JSON so that 'url' is absolute and provide API fallback
$raw = (string)@file_get_contents($file);
$data = json_decode($raw, true);
if (!is_array($data) || empty($data)) {
    // Try alternate locations if the chosen file is unreadable/empty
    $alts = [
        $activeBase . DIRECTORY_SEPARATOR . 'releases' . DIRECTORY_SEPARATOR . 'latest.json',
        $activeBase . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'releases' . DIRECTORY_SEPARATOR . 'latest.json',
        $docroot . DIRECTORY_SEPARATOR . 'releases' . DIRECTORY_SEPARATOR . 'latest.json',
    ];
    foreach ($alts as $p) {
        if (is_file($p)) {
            $try = json_decode((string)@file_get_contents($p), true);
            if (is_array($try) && !empty($try)) { $data = $try; $file = $p; $mtime = filemtime($file) ?: $mtime; $lastModified = gmdate('D, d M Y H:i:s', $mtime) . ' GMT'; break; }
        }
    }
    if (!is_array($data)) { $data = []; }
}

// Compute origin (scheme + host) for absolute URL construction
$isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$scheme = $isHttps ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? '';
$origin = $host ? ($scheme . '://' . $host) : '';

// Ensure url is absolute; also add download_api as a reliable fallback
if (!empty($data['url']) && is_string($data['url'])) {
    $u = $data['url'];
    if ($origin) {
        if (strpos($u, 'http://') !== 0 && strpos($u, 'https://') !== 0) {
            // relative path like "/releases/..." or "releases/..."
            if ($u && $u[0] !== '/') { $u = '/' . $u; }
            $data['url'] = $origin . $u;
        }
    }
}

if ($origin) {
    $data['download_api'] = $origin . '/api/download_worker.php';
    // Prefer serving the installer via API; if we can locate the EXE, override url/size/sha256 accordingly
    $exe = null;
    $root = dirname(__DIR__); // active release root
    // Search both new and legacy installer names and pick the newest by mtime
    $candGlobs = [
        $root . DIRECTORY_SEPARATOR . 'worker' . DIRECTORY_SEPARATOR . 'build' . DIRECTORY_SEPARATOR . '*Worker_Setup*.exe',
        $root . DIRECTORY_SEPARATOR . 'releases' . DIRECTORY_SEPARATOR . '*Worker_Setup*.exe',
        $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'releases' . DIRECTORY_SEPARATOR . '*Worker_Setup*.exe',
    ];
    $best = null; $bestM = -1;
    foreach ($candGlobs as $g) {
        foreach (glob($g) ?: [] as $p) {
            if (is_file($p)) { $m = @filemtime($p); if ($m !== false && $m > $bestM) { $bestM = $m; $best = realpath($p); } }
        }
    }
    if ($best) { $exe = $best; }
    if ($exe) {
        $data['url'] = $data['download_api'];
        $data['kind'] = $data['kind'] ?? 'exe';
        $data['size'] = (int)filesize($exe);
        // Computing sha256 on demand; acceptable frequency for update checks
        $data['sha256'] = hash_file('sha256', $exe);
        $data['last_modified'] = gmdate('c', filemtime($exe) ?: time());
    }
    // Ensure absolute URL if a relative one is present
    if (!empty($data['url']) && is_string($data['url'])) {
        $u = $data['url'];
        if (strpos($u, 'http://') !== 0 && strpos($u, 'https://') !== 0) {
            if ($u && $u[0] !== '/') { $u = '/' . $u; }
            $data['url'] = $origin . $u;
        }
    }
    // If url still missing, default to the API endpoint
    if (empty($data['url'])) {
        $data['url'] = $data['download_api'];
    }
}

// If essential fields are still missing, attempt to merge from installer_meta.json
$metaCandidates = [
    $docroot . DIRECTORY_SEPARATOR . 'releases' . DIRECTORY_SEPARATOR . 'installer_meta.json',
    $activeBase . DIRECTORY_SEPARATOR . 'releases' . DIRECTORY_SEPARATOR . 'installer_meta.json',
    $activeBase . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'releases' . DIRECTORY_SEPARATOR . 'installer_meta.json',
];
foreach ($metaCandidates as $mp) {
    if (is_file($mp)) {
        $mj = json_decode((string)@file_get_contents($mp), true);
        if (is_array($mj)) {
            foreach (['version','sha256','size','mtime','kind'] as $k) {
                if (empty($data[$k]) && !empty($mj[$k])) { $data[$k] = $mj[$k]; }
            }
        }
        break;
    }
}

// Ensure we always have a reasonable shape
if (empty($data['version'])) {
    // Try to parse version from worker/index.js as a last resort
    $ix = $activeBase . DIRECTORY_SEPARATOR . 'worker' . DIRECTORY_SEPARATOR . 'index.js';
    $ver = null;
    if (is_file($ix)) {
        $src = (string)@file_get_contents($ix);
        if (preg_match("/APP_VER\\s*=\\s*'([^']+)'/", $src, $mm)) { $ver = $mm[1]; }
    }
    $data['version'] = $ver ?: '0.0.0';
}
if (empty($data['channel'])) { $data['channel'] = 'stable'; }
if (empty($data['kind'])) { $data['kind'] = 'portable'; }

// Prepare body and ETag based on output body (not just the file content)
$body = json_encode($data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
$etag = 'W/"' . sha1($body) . '"';

// Conditional headers first to allow 304 without body
if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    header('ETag: ' . $etag);
    header('Last-Modified: ' . $lastModified);
    header('Cache-Control: public, max-age=60');
    http_response_code(304);
    exit;
}
if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && trim($_SERVER['HTTP_IF_MODIFIED_SINCE']) === $lastModified) {
    header('ETag: ' . $etag);
    header('Last-Modified: ' . $lastModified);
    header('Cache-Control: public, max-age=60');
    http_response_code(304);
    exit;
}

header('ETag: ' . $etag);
header('Last-Modified: ' . $lastModified);
header('Cache-Control: public, max-age=60');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
    http_response_code(200);
    exit;
}

echo $body;
exit;
