<?php
declare(strict_types=1);
// Streams the Windows Worker artifact (EXE installer by default, ZIP when kind=zip).
// Primary source is releases/latest.json; falls back to worker/build/ or storage/releases/.

// Security: this serves a static binary only. Only the 'kind' query is accepted (exe|zip).

// Disable compression/buffers so Content-Length is honored and size is known
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', 'off');
while (@ob_end_clean());
if (function_exists('apache_setenv')) { @apache_setenv('no-gzip','1'); }
ignore_user_abort(true);
set_time_limit(0);

$file = null;
$latestMeta = null;
$kind = strtolower((string)($_GET['kind'] ?? ''));
$wantZip = ($kind === 'zip');
// Prefer global releases/latest.json located at site root (one level above active release)
$topReleases = dirname(__DIR__).'/releases';
$latestJson = $topReleases.'/latest.json';
if (!is_file($latestJson)) $latestJson = __DIR__.'/../releases/latest.json';
if (!is_file($latestJson)) $latestJson = __DIR__.'/../storage/releases/latest.json';
if (is_file($latestJson)) {
    $lj = json_decode((string)@file_get_contents($latestJson), true);
    $latestMeta = $lj;
    $url = (is_array($lj) && !empty($lj['url'])) ? (string)$lj['url'] : '';
    if ($url) {
        // Normalize and extract a safe relative path from the URL
        $urlPath = parse_url($url, PHP_URL_PATH) ?: (string)$url;
        $urlPath = str_replace('\\', '/', $urlPath);
        $urlPath = preg_replace('#/+#', '/', $urlPath);
        $safe = str_replace('..', '', $urlPath);
        $basename = basename($safe);

        // Build candidate relative paths without duplicating folder segments
        $relCandidates = [];
        if ($safe) {
            if (stripos($safe, '/releases/') === 0) {
                $relCandidates[] = substr($safe, strlen('/releases/'));
            } elseif (stripos($safe, '/storage/releases/') === 0) {
                $relCandidates[] = substr($safe, strlen('/storage/releases/'));
            } else {
                // If it's already a simple relative path (or api endpoint), just try the basename
                if ($basename) { $relCandidates[] = $basename; }
            }
        }
        // Always try basename as a fallback
        if ($basename && !in_array($basename, $relCandidates, true)) { $relCandidates[] = $basename; }

        // Attempt to resolve against common locations
        foreach ($relCandidates as $rel) {
            $rel = ltrim($rel, '/');
            // Prefer storage/releases
            $candStore = realpath(__DIR__.'/../storage/releases/'.$rel);
            if ($candStore && is_file($candStore)) { $file = $candStore; break; }
            // Then top-level releases (docroot/releases)
            $candTop = realpath($topReleases.'/'.$rel);
            if ($candTop && is_file($candTop)) { $file = $candTop; break; }
            // Then active release folder
            $cand = realpath(__DIR__.'/../releases/'.$rel);
            if ($cand && is_file($cand)) { $file = $cand; break; }
        }
    }
}

// If kind=zip requested, prefer a portable ZIP regardless of latest.json url
if ($wantZip) {
    // Ignore any previously resolved EXE from latest.json; we only want ZIP
    $file = null;
    $zip = null;
    // If latest.json url looks like a zip, try to resolve it first
    if (!$zip && isset($latestMeta['url']) && is_string($latestMeta['url']) && preg_match('/\.zip$/i', $latestMeta['url'])){
        // Extract the file name from the URL/path safely
        $urlPath = parse_url((string)$latestMeta['url'], PHP_URL_PATH) ?: (string)$latestMeta['url'];
        $baseName = basename($urlPath);
        $safeName = str_replace(['..','\\','/'], ['','',''], $baseName);
        if ($safeName) {
            // Prefer storage/releases then top-level releases
            $candStore = realpath(__DIR__.'/../storage/releases/'.$safeName);
            if ($candStore && is_file($candStore)) { $zip = $candStore; }
            if (!$zip) {
                $candTop = realpath($topReleases.'/'.$safeName);
                if ($candTop && is_file($candTop)) { $zip = $candTop; }
            }
            // As a last resort, try resolving relative to active root
            if (!$zip) {
                $cand = realpath(__DIR__.'/../releases/'.$safeName);
                if ($cand && is_file($cand)) { $zip = $cand; }
            }
        }
    }
    // Fallback by pattern: pick the newest by mtime; prefer storage/releases
    if (!$zip) {
        $patterns = [
            __DIR__.'/../storage/releases/*Portable*.zip',
            $topReleases.'/*Portable*.zip',
            __DIR__.'/../releases/*Portable*.zip',
            // Broaden search in case naming changed (avoid site zips)
            __DIR__.'/../storage/releases/*Worker*.zip',
            $topReleases.'/*Worker*.zip',
            __DIR__.'/../releases/*Worker*.zip',
        ];
        $cands = [];
        foreach ($patterns as $g) { $c = glob($g) ?: []; if ($c) { $cands = array_merge($cands, $c); } }
        $best = null; $bestM = -1;
        foreach ($cands as $p) {
            if (!is_file($p)) continue;
            $name = basename($p);
            // Skip site zips (deployment bundles)
            if (preg_match('/^site[-_].*\.zip$/i', $name)) continue;
            $m = @filemtime($p);
            if ($m !== false && $m > $bestM) { $bestM = $m; $best = $p; }
        }
        if ($best) { $zip = realpath($best); }
    }
    // Decide whether to REBUILD even if a zip candidate exists (version mismatch, stale sources, or corrupt zip)
    $shouldRebuild = false;
    $workerDir = realpath(__DIR__ . '/../worker') ?: (__DIR__.'/../worker');
    // Extract current worker APP_VER
    $curVer = null; $idxPath = __DIR__.'/../worker/index.js';
    if (is_file($idxPath)){
        $src = @file_get_contents($idxPath);
        if (is_string($src) && preg_match("/APP_VER\s*=\s*'([^']+)'/", $src, $m)) { $curVer = trim($m[1]); }
    }
    // Find latest mtime among key worker sources (cheap heuristic)
    $latestSrcM = 0;
    $bump = function($p) use (&$latestSrcM){ $t=@filemtime($p); if($t!==false && $t>$latestSrcM) $latestSrcM=$t; };
    foreach (['index.js','package.json','worker_run.bat','worker_service.bat','install_service.ps1','update_worker.ps1'] as $fn){ $p=$workerDir.'/'.$fn; if(is_file($p)) $bump($p); }
    // Consider node runtime folder timestamp if present (top-level files only)
    $nodeDir = $workerDir.'/node';
    if (is_dir($nodeDir)){
        $it = new DirectoryIterator($nodeDir);
        foreach ($it as $fi){ if($fi->isFile()){ $bump($fi->getPathname()); } }
    }
    if ($zip) {
        // Validate zip can be opened and compare version/mtime and required contents
        $zOk = true; $zipM = @filemtime($zip) ?: 0;
        $zipName = basename($zip);
        $verInName = null; if (preg_match('/v(\d+[^._]*)/i', $zipName, $mm)) { $verInName = $mm[1]; }
        $zipTooSmall = ((int)@filesize($zip)) > 0 && ((int)@filesize($zip)) < 30*1024*1024; // <30MB suspicious for portable
        $missingEntries = false;
        if (class_exists('ZipArchive')){
            $za = new ZipArchive();
            if($za->open($zip)!==true){ $zOk=false; }
            else {
                // Expect core files inside the root folder used by builder
                $hasIndex = ($za->locateName('OptForgeWorker/index.js') !== false);
                $hasNode  = ($za->locateName('OptForgeWorker/node/node.exe') !== false);
                $missingEntries = (!$hasIndex || !$hasNode);
                $za->close();
            }
        }
        if (!$zOk) { $shouldRebuild = true; }
        if ($zipTooSmall) { $shouldRebuild = true; }
        if ($missingEntries) { $shouldRebuild = true; }
        if ($curVer && $verInName && strcasecmp($curVer, $verInName)!==0) { $shouldRebuild = true; }
        if ($latestSrcM && $zipM && $zipM + 2 < $latestSrcM) { $shouldRebuild = true; }
    } else {
        $shouldRebuild = true;
    }
    if (!$shouldRebuild && $zip) {
        $file = $zip; $latestMeta = is_array($latestMeta)? $latestMeta : []; $latestMeta['kind'] = 'portable';
    }
    // If ZIP explicitly requested but not found, return 404 instead of serving EXE
    if (!$file) {
        // Attempt on-demand portable ZIP build from /worker directory
        $workerDir = realpath(__DIR__ . '/../worker');
        if ($workerDir && is_dir($workerDir) && class_exists('ZipArchive')) {
            // Derive version from worker/index.js (APP_VER), fallback to date
            $ver = $curVer; $idx = __DIR__.'/../worker/index.js';
            if(!$ver){ $ver = date('Y.m.d_His'); }
            $outDir = realpath(__DIR__ . '/../storage') ?: (__DIR__ . '/../storage');
            if (!is_dir($outDir . '/releases')) { @mkdir($outDir . '/releases', 0777, true); }
            $zipPath = $outDir . '/releases/OptForgeWorker_Portable_v' . $ver . '.zip';
            // Create zip with curated contents under a friendly root folder
            $rootName = 'OptForgeWorker';
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                // Helper: add a file preserving relative path beneath workerDir
                $addFile = function($absPath) use ($zip, $workerDir, $rootName){
                    $rel = ltrim(str_replace('\\','/', substr($absPath, strlen($workerDir))), '/');
                    if ($rel==='') return;
                    $zip->addFile($absPath, $rootName . '/' . $rel);
                };
                // Helper: add an entire directory into the zip under a subfolder
                $addDir = function($absDir, $targetSub) use ($zip, $rootName){
                    $base = rtrim(str_replace('\\','/',$absDir), '/');
                    if (!is_dir($base)) return;
                    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
                    foreach ($it as $fi){ if($fi->isFile()){
                        $rel = substr(str_replace('\\','/',$fi->getPathname()), strlen($base));
                        $rel = ltrim($rel, '/');
                        $zip->addFile($fi->getPathname(), $rootName . '/' . ($targetSub? ($targetSub.'/') : '') . $rel);
                    }}
                };
                // Add selected top-level files if present
                $pickFiles = [
                    'worker.exe','index.js','launcher.js','package.json','package-lock.json',
                    'worker_run.bat','worker_service.bat','install_service.ps1','update_worker.ps1','watchdog.ps1',
                    'README.txt','README.md','README-install.md','.env.example','.env'
                ];
                foreach ($pickFiles as $f){ $p = $workerDir . '/' . $f; if (is_file($p)) { $addFile($p); } }
                // Add portable Node runtime: prefer worker/node then vendor fallbacks
                $nodeCandidates = [
                    $workerDir . '/node',
                    __DIR__ . '/../worker/vendor/node-win64',
                    __DIR__ . '/../storage/vendor/node-win64',
                    __DIR__ . '/../storage/vendor/node',
                ];
                $nodeAdded=false; foreach($nodeCandidates as $cand){ if(is_dir($cand)){ $addDir($cand, 'node'); $nodeAdded=true; break; } }

                // Add node_modules for offline use: prefer worker/node_modules then vendor fallbacks
                $modsCandidates = [
                    $workerDir . '/node_modules',
                    __DIR__ . '/../worker/vendor/node_modules',
                    __DIR__ . '/../storage/vendor/node_modules',
                ];
                foreach($modsCandidates as $cand){ if(is_dir($cand)){ $addDir($cand, 'node_modules'); break; } }

                // Add Playwright browsers cache if provided (optional but enables fully offline first run)
                $pwCandidates = [
                    $workerDir . '/ms-playwright',
                    __DIR__ . '/../worker/vendor/ms-playwright',
                    __DIR__ . '/../storage/vendor/ms-playwright',
                ];
                foreach($pwCandidates as $cand){ if(is_dir($cand)){ $addDir($cand, 'ms-playwright'); break; } }

                // Add profile-data skeleton if exists (small)
                $prof = $workerDir . '/profile-data';
                if (is_dir($prof)){
                    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($prof, FilesystemIterator::SKIP_DOTS));
                    $added = 0;
                    foreach ($it as $fi) { if($fi->isFile()){ $addFile($fi->getPathname()); $added++; if($added>200){ break; } } }
                }
                $zip->close();
                // If we produced the zip, serve it
                if (is_file($zipPath)) {
                    $file = realpath($zipPath);
                    $latestMeta = ['version'=>$ver, 'kind'=>'portable'];
                    // Write basic metadata best-effort
                    try{
                        $sha = @hash_file('sha256', $zipPath);
                        $meta = [
                            'name' => basename($zipPath),
                            'size' => (int)@filesize($zipPath),
                            'sha256' => $sha ?: null,
                            'last_modified' => gmdate('c', @filemtime($zipPath) ?: time()),
                            'version' => $ver,
                            'kind' => 'portable',
                            'url' => '/releases/' . basename($zipPath)
                        ];
                        @file_put_contents($outDir . '/releases/installer_meta.json', json_encode($meta, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
                        // Persist a simple source freshness marker for admin UI/debugging
                        $marker = [
                            'built_at' => gmdate('c'),
                            'source_latest_mtime' => $latestSrcM ?: null,
                            'app_ver' => $curVer,
                            'zip' => basename($zipPath)
                        ];
                        @file_put_contents($outDir . '/releases/worker_zip_meta.json', json_encode($marker, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
                        // Do not overwrite top-level releases/latest.json here to avoid conflicts in multi-host setups
                    }catch(Throwable $e){}
                }
            }
        }
        if (!$file) {
            http_response_code(404);
            header('Content-Type: text/html; charset=utf-8');
            $hint = class_exists('ZipArchive') ? '' : '<br><b>ملاحظة:</b> امتداد ZipArchive غير متاح في PHP على الخادم؛ فعّله أولاً.';
            echo '<meta charset="utf-8"><div style="font-family:system-ui;direction:rtl;padding:20px">لا توجد حزمة ZIP حديثة، وفشلت المحاولة التلقائية لإنشائها. يُرجى التأكد من صلاحيات الكتابة للمسار <code>storage/releases</code> أو إنشاء الحزمة يدويًا (worker/build_installer.ps1).'.$hint.'</div>';
            exit;
        }
    }
}

// If still no file, fall back to EXE installer
if (!$file) {
    $candGlobs = [
        __DIR__ . '/../worker/build/*Worker_Setup*.exe',
        __DIR__ . '/../releases/*Worker_Setup*.exe',
        __DIR__ . '/../storage/releases/*Worker_Setup*.exe',
    ];
    $best = null; $bestM = -1;
    foreach ($candGlobs as $g) {
        foreach (glob($g) ?: [] as $p) {
            if (is_file($p)) { $m = @filemtime($p); if ($m !== false && $m > $bestM) { $bestM = $m; $best = realpath($p); } }
        }
    }
    if ($best) { $file = $best; }
}

if (!$file) {
    // Fallback: if no EXE found, try the newest portable ZIP (without attempting a rebuild)
    $patterns = [
        __DIR__.'/../storage/releases/*Portable*.zip',
        $topReleases.'/*Portable*.zip',
        __DIR__.'/../releases/*Portable*.zip',
        __DIR__.'/../storage/releases/*Worker*.zip',
        $topReleases.'/*Worker*.zip',
        __DIR__.'/../releases/*Worker*.zip',
    ];
    $cands = [];
    foreach ($patterns as $g) { $c = glob($g) ?: []; if ($c) { $cands = array_merge($cands, $c); } }
    $best = null; $bestM = -1;
    foreach ($cands as $p) {
        if (!is_file($p)) continue;
        $name = basename($p);
        if (preg_match('/^site[-_].*\.zip$/i', $name)) continue; // skip site bundles
        $m = @filemtime($p);
        if ($m !== false && $m > $bestM) { $bestM = $m; $best = realpath($p); }
    }
    if ($best) { $file = $best; $latestMeta = is_array($latestMeta)? $latestMeta : []; $latestMeta['kind'] = 'portable'; }
}

if (!$file) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<meta charset="utf-8"><div style="font-family:system-ui;direction:rtl;padding:20px">لم يتم العثور على مُثبّت العامل. فضلاً قم ببنائه أولاً من خلال build_installer.ps1 ثم أعد المحاولة.</div>';
    exit;
}

$filename = basename($file);
clearstatcache(true, $file);
$size = (int)filesize($file);
$mtime = filemtime($file);
$etag = 'W/"'.md5($filename.'|'.$size.'|'.$mtime).'"';

// Integrity metadata: select installer_meta.json closest to the resolved file, then verify size/sha256
$expectedSha = null; $expectedSize = null; $expectedName = null;
$storeDir = realpath(__DIR__.'/../storage/releases') ?: (__DIR__.'/../storage/releases');
$activeRelDir = realpath(__DIR__.'/../releases') ?: (__DIR__.'/../releases');
$topRelDir = realpath($topReleases) ?: $topReleases;
$metaCandidates = [];
$rf = realpath($file) ?: $file;
if ($storeDir && strpos($rf, rtrim($storeDir,'/\\')) === 0) { $metaCandidates[] = rtrim($storeDir,'/\\').'/installer_meta.json'; }
if ($topRelDir && strpos($rf, rtrim($topRelDir,'/\\')) === 0) { $metaCandidates[] = rtrim($topRelDir,'/\\').'/installer_meta.json'; }
if ($activeRelDir && strpos($rf, rtrim($activeRelDir,'/\\')) === 0) { $metaCandidates[] = rtrim($activeRelDir,'/\\').'/installer_meta.json'; }
// generic fallbacks
$metaCandidates[] = rtrim($topRelDir,'/\\').'/installer_meta.json';
$metaCandidates[] = rtrim($activeRelDir,'/\\').'/installer_meta.json';
$metaCandidates[] = rtrim($storeDir,'/\\').'/installer_meta.json';
foreach ($metaCandidates as $mc) {
    if (is_file($mc)) {
        $j = json_decode((string)@file_get_contents($mc), true);
        if (is_array($j)) {
            $expectedSha = isset($j['sha256']) && is_string($j['sha256']) ? strtolower($j['sha256']) : $expectedSha;
            $expectedSize = isset($j['size']) ? (int)$j['size'] : $expectedSize;
            $expectedName = isset($j['name']) && is_string($j['name']) ? $j['name'] : $expectedName;
        }
        break;
    }
}
// If latest.json carries integrity fields, prefer them only if missing in installer_meta
if (is_array($latestMeta)) {
    if (!$expectedSha && !empty($latestMeta['sha256'])) $expectedSha = strtolower((string)$latestMeta['sha256']);
    if (!$expectedSize && !empty($latestMeta['size'])) $expectedSize = (int)$latestMeta['size'];
    if (!$expectedName && !empty($latestMeta['name'])) $expectedName = (string)$latestMeta['name'];
}
// Compare basename if provided (best-effort)
if ($expectedName && basename($file) !== basename($expectedName)) {
    // Not necessarily fatal if path changed; keep note via header
    header('X-Installer-Name-Mismatch: 1');
}
// Size check if provided
if ($expectedSize !== null && $expectedSize > 0 && $expectedSize !== $size) {
    http_response_code(412);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>'integrity_size_mismatch','expected'=>$expectedSize,'actual'=>$size]);
    exit;
}
// SHA-256 check if provided
if ($expectedSha) {
    $actualSha = strtolower((string)@hash_file('sha256', $file));
    if ($actualSha !== $expectedSha) {
        http_response_code(412);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'error'=>'integrity_hash_mismatch','expected'=>$expectedSha,'actual'=>$actualSha]);
        exit;
    }
}

// Conditional GET to enable caching
if ((isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag)
    || (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) >= $mtime)) {
    header('ETag: '.$etag);
    header('Last-Modified: '.gmdate('D, d M Y H:i:s', $mtime).' GMT');
    http_response_code(304);
    exit;
}

// Content type per artifact
$ctype = 'application/octet-stream';
if (preg_match('/\.zip$/i', $file)) { $ctype = 'application/zip'; }
header('Content-Type: ' . $ctype);
header('Accept-Ranges: bytes');
header('Cache-Control: public, max-age=600');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Content-Transfer-Encoding: binary');
header('Connection: close');
header('X-Worker-Installer-Filename: '.$filename);
header('X-Worker-Installer-Size: '.$size);
header('X-Worker-Installer-MTime: '.gmdate('c', $mtime));
header('ETag: '.$etag);
header('Last-Modified: '.gmdate('D, d M Y H:i:s', $mtime).' GMT');
if (is_array($latestMeta)) {
    if (!empty($latestMeta['version'])) header('X-Worker-Installer-Version: '.$latestMeta['version']);
    if (!empty($latestMeta['kind'])) header('X-Worker-Installer-Kind: '.$latestMeta['kind']);
    elseif (preg_match('/\.zip$/i', $filename)) header('X-Worker-Installer-Kind: portable');
}
// Expose integrity headers if known
if ($expectedSha) header('X-Worker-Installer-SHA256: '.$expectedSha);

// Support HEAD and Range
$start = 0; $end = $size - 1; $httpStatus = 200;
if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
    if ($m[1] !== '') { $start = (int)$m[1]; }
    if ($m[2] !== '') { $end = (int)$m[2]; }
    if ($start > $end || $start >= $size) { http_response_code(416); header('Content-Range: bytes */'.$size); exit; }
    $httpStatus = 206; header('Content-Range: bytes '.$start.'-'.$end.'/'.$size);
}
http_response_code($httpStatus);
header('Content-Length: '.(($end - $start) + 1));

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'HEAD') { exit; }

$fp = fopen($file, 'rb');
if ($fp) {
    if ($start > 0) { fseek($fp, $start); }
    $left = ($end - $start) + 1;
    while ($left > 0 && !feof($fp)) {
        $chunk = ($left > 8192) ? 8192 : $left;
        echo fread($fp, $chunk);
        $left -= $chunk;
        @ob_flush(); flush();
    }
    fclose($fp);
}
exit;
?>
