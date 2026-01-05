<?php
// Note: introducing per-request CSP nonce (phase-0). Inline scripts can opt-in by echoing nonce via csp_nonce().
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/limits.php';

function settings_get($key, $default = null)
{
  $pdo = db();
  $stmt = $pdo->prepare("SELECT value FROM settings WHERE key=?");
  $stmt->execute([$key]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return $row ? $row['value'] : $default;
}

function feature_enabled($key, $default = '0')
{
  return settings_get($key, $default) === '1';
}

function emit_security_headers()
{
  header('X-Content-Type-Options: nosniff');
  header('X-Frame-Options: SAMEORIGIN');
  header('Referrer-Policy: strict-origin-when-cross-origin');
  // HSTS: send only when HTTPS is detected (avoid sending on HTTP)
  try {
    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
      || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
      || (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_SSL']) === 'on');
    if ($isHttps) {
      // One year, include subdomains; omit preload by default (can be added via upstream if desired)
      header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
  } catch (Throwable $e) { /* best-effort */
  }
  // CSP with nonce support; Phase-1 toggle to drop 'unsafe-inline' for admin pages
  $nonce = csp_nonce();
  $isAdminPage = isset($_SERVER['SCRIPT_NAME']) && strpos((string) $_SERVER['SCRIPT_NAME'], '/admin/') !== false;
  $phase1 = function_exists('get_setting') ? (get_setting('csp_phase1_enforced', '0') === '1') : false;
  $allowedScriptHosts = ['https://code.jquery.com', 'https://cdn.jsdelivr.net', 'https://cdnjs.cloudflare.com', 'https://cdn.datatables.net', 'https://unpkg.com'];
  $scriptSrc = ["'self'", "'nonce-{$nonce}'", ...$allowedScriptHosts];
  // Build CSP directives
  $csp = [
    "default-src 'self'",
    // Allow common external image sources used by Leaflet tiles and CDN-hosted assets
    "img-src 'self' data: https://unpkg.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://cdn.datatables.net https://tile.openstreetmap.org https://*.tile.openstreetmap.org",
    "style-src 'self' 'unsafe-inline' https://cdn.datatables.net https://fonts.googleapis.com https://unpkg.com https://cdn.jsdelivr.net",
  ];

  if ($phase1 && $isAdminPage) {
    // Phase-1 on admin pages:
    // - Enforce nonces for <script> elements via script-src-elem (modern browsers)
    // - Allow attribute event handlers via script-src-attr 'unsafe-inline'
    // - Keep legacy script-src with 'unsafe-inline' for older browsers that don't understand *-elem/attr
    $scriptSrcLegacy = ["'self'", "'nonce-{$nonce}'", ...$allowedScriptHosts];
    $scriptSrcElem = ["'self'", "'nonce-{$nonce}'", ...$allowedScriptHosts];
    $csp[] = 'script-src ' . implode(' ', $scriptSrcLegacy);
    $csp[] = 'script-src-elem ' . implode(' ', $scriptSrcElem);
    $csp[] = "script-src-attr 'unsafe-inline'";
  } else {
    // Non-admin or phase-1 disabled: keep simpler policy
    // Keep unsafe-inline unless Phase-1 enforced on admin pages
    array_unshift($scriptSrc, "'unsafe-inline'");
    $csp[] = 'script-src ' . implode(' ', $scriptSrc);
  }

  $csp = array_merge($csp, [
    "connect-src 'self' https://cdn.datatables.net",
    "font-src 'self' data: https://fonts.gstatic.com https://cdn.jsdelivr.net",
    "frame-ancestors 'self'",
    "base-uri 'self'",
    "form-action 'self'"
  ]);
  header('Content-Security-Policy: ' . implode('; ', $csp));
}

// Returns stable per-request CSP nonce (same value across a request).
function csp_nonce(): string
{
  static $nonce = null;
  if ($nonce !== null)
    return $nonce;
  if (isset($GLOBALS['__csp_nonce']) && is_string($GLOBALS['__csp_nonce'])) {
    $nonce = $GLOBALS['__csp_nonce'];
    return $nonce;
  }
  $bytes = random_bytes(16);
  $nonce = rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
  $GLOBALS['__csp_nonce'] = $nonce;
  return $nonce;
}

function is_form_post_request()
{
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST')
    return false;
  $ct = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
  return (strpos($ct, 'application/x-www-form-urlencoded') !== false) || (strpos($ct, 'multipart/form-data') !== false) || ($ct === '');
}

function system_product_name()
{
  // Prefer new brand key, then legacy product_name, then default
  $brand = settings_get('brand_name');
  if ($brand && is_string($brand) && $brand !== '')
    return $brand;
  $legacy = settings_get('product_name');
  if ($legacy && is_string($legacy) && $legacy !== '')
    return $legacy;
  return 'OptForge';
}

function system_tagline_ar()
{
  $v = settings_get('brand_tagline_ar');
  if ($v && is_string($v) && $v !== '')
    return $v;
  // Default Arabic tagline
  return 'منصة استخراج ومعالجة البيانات — OptForge';
}

function system_tagline_en()
{
  $v = settings_get('brand_tagline_en');
  if ($v && is_string($v) && $v !== '')
    return $v;
  // Default English tagline
  return 'OptForge — Data scraping and operations automation';
}

function system_is_globally_stopped()
{
  return settings_get('system_global_stop', '0') === '1';
}

function system_is_in_pause_window(?DateTime $now = null)
{
  $enabled = settings_get('system_pause_enabled', '0') === '1';
  if (!$enabled)
    return false;
  if (!$now)
    $now = new DateTime('now');
  $start = settings_get('system_pause_start', '23:59');
  $end = settings_get('system_pause_end', '09:00');
  // Interpret times as local server time (UTC assumed for simplicity)
  [$sh, $sm] = array_map('intval', explode(':', $start));
  [$eh, $em] = array_map('intval', explode(':', $end));
  $today = (clone $now)->setTime(0, 0, 0);
  $s = (clone $today)->setTime($sh, $sm, 0);
  $e = (clone $today)->setTime($eh, $em, 0);
  if ($e <= $s) { // window crosses midnight
    // active if now >= start OR now < end
    return ($now >= $s) || ($now < $e);
  } else {
    return ($now >= $s) && ($now < $e);
  }
}

function system_block_if_stopped()
{
  if (system_is_globally_stopped() || system_is_in_pause_window()) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'system_stopped', 'message' => 'System is temporarily paused by admin']);
    exit;
  }
}

function system_auto_guard_request()
{
  // 0) Optional HTTPS enforce
  if (function_exists('feature_enabled') && feature_enabled('force_https', '0')) {
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
      || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
      || (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_SSL']) === 'on');
    if (!$https) {
      $host = $_SERVER['HTTP_HOST'] ?? '';
      $uri = $_SERVER['REQUEST_URI'] ?? '/';
      if ($host) {
        $to = 'https://' . $host . $uri;
        header('Location: ' . $to, true, 308);
        echo '<!doctype html><meta charset="utf-8"><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($to, ENT_QUOTES, 'UTF-8') . '">';
        exit;
      }
    }
  }
  // 1) Emit headers always
  emit_security_headers();
  // 2) Optional CSRF auto-enforce for form posts (does not affect JSON APIs)
  if (feature_enabled('security_csrf_auto', '0') && is_form_post_request()) {
    require_once __DIR__ . '/csrf.php';
    $ok = csrf_verify($_POST['csrf'] ?? '');
    if (!$ok) {
      http_response_code(400);
      header('Content-Type: text/html; charset=utf-8');
      echo '<!doctype html><meta charset="utf-8"><title>Bad Request</title><div style="padding:24px;font-family:system-ui">CSRF validation failed</div>';
      exit;
    }
  }
  // 3) Optional simple global rate limit per IP (very conservative defaults)
  if (feature_enabled('rate_limit_basic', '0')) {
    try {
      $pdo = db();
      $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
      $path = $_SERVER['SCRIPT_NAME'] ?? '';
      $key = sha1($ip . '|' . $path);
      $limit = (int) settings_get('rate_limit_global_per_min', '600');
      $now = time();
      $winStart = $now - 60; // sliding 60s window
      // Prune old windows occasionally (best-effort)
      if (($now % 29) === 0) {
        try {
          $pdo->prepare("DELETE FROM rate_limit WHERE window_start < ?")->execute([$now - 86400]);
        } catch (Throwable $e) {
        }
      }
      $sel = $pdo->prepare("SELECT window_start, count FROM rate_limit WHERE ip=? AND key=? LIMIT 1");
      $sel->execute([$ip, $key]);
      $row = $sel->fetch(PDO::FETCH_ASSOC);
      if ($row) {
        $ws = (int) $row['window_start'];
        $cnt = (int) $row['count'];
        if ($ws < $winStart) {
          // Reset window
          $cnt = 1;
          $ws = $now;
          $pdo->prepare("UPDATE rate_limit SET window_start=?, count=? WHERE ip=? AND key=?")->execute([$ws, $cnt, $ip, $key]);
        } else {
          $cnt++;
          $pdo->prepare("UPDATE rate_limit SET count=? WHERE ip=? AND key=?")->execute([$cnt, $ip, $key]);
        }
        if ($cnt > $limit) {
          http_response_code(429);
          header('Retry-After: 60');
          header('Content-Type: application/json; charset=utf-8');
          echo json_encode(['ok' => false, 'error' => 'rate_limited', 'message' => 'Too many requests']);
          exit;
        }
      } else {
        $pdo->prepare("INSERT INTO rate_limit(ip,key,window_start,count) VALUES(?,?,?,1)")->execute([$ip, $key, $now]);
      }
    } catch (Throwable $e) { /* best-effort */
    }
  }
}

function workers_upsert_seen($workerId, $info = null)
{
  $pdo = db();
  // Use server local time to match admin dashboards that compare with date('Y-m-d H:i:s')
  $now = date('Y-m-d H:i:s');
  $infoJson = $info ? json_encode($info, JSON_UNESCAPED_UNICODE) : null;
  $host = is_array($info) ? ($info['host'] ?? null) : null;
  $ver = is_array($info) ? ($info['ver'] ?? ($info['version'] ?? null)) : null;
  $status = is_array($info) ? ($info['status'] ?? null) : null;
  $activeJobId = is_array($info) ? ($info['active_job_id'] ?? null) : null;
  try {
    $sql = "INSERT INTO internal_workers(worker_id,last_seen,info,host,version,status,active_job_id)
            VALUES(?,?,?,?,?,?,?)
            ON CONFLICT(worker_id) DO UPDATE SET
              last_seen=excluded.last_seen,
              info=COALESCE(excluded.info,internal_workers.info),
              host=COALESCE(excluded.host,internal_workers.host),
              version=COALESCE(excluded.version,internal_workers.version),
              status=COALESCE(excluded.status,internal_workers.status),
              active_job_id=COALESCE(excluded.active_job_id,internal_workers.active_job_id)";
    $pdo->prepare($sql)->execute([$workerId, $now, $infoJson, $host, $ver, $status, $activeJobId]);
  } catch (Throwable $e) {
    // Fallback for older schemas without telemetry columns
    try {
      $pdo->prepare("INSERT INTO internal_workers(worker_id,last_seen,info) VALUES(?,?,?) ON CONFLICT(worker_id) DO UPDATE SET last_seen=excluded.last_seen, info=COALESCE(excluded.info,internal_workers.info)")
        ->execute([$workerId, $now, $infoJson]);
    } catch (Throwable $_) {
    }
  }
}

// Helper: configurable online window in seconds (default 90s)
function workers_online_window_sec(): int
{
  try {
    $w = (int) settings_get('workers_online_window_sec', '90');
    if ($w < 15)
      $w = 15;
    if ($w > 600)
      $w = 600; // sane bounds
    return $w;
  } catch (Throwable $e) {
    return 90;
  }
}

// Helper: count workers that are considered "online" recently.
// Excludes known dev/probe IDs by default to avoid false positives during diagnostics.
function workers_online_count(bool $includeDev = false): int
{
  try {
    $pdo = db();
    $cut = date('Y-m-d H:i:s', time() - workers_online_window_sec());
    if ($includeDev) {
      $st = $pdo->prepare("SELECT COUNT(*) c FROM internal_workers WHERE last_seen >= ?");
      $st->execute([$cut]);
    } else {
      $st = $pdo->prepare("SELECT COUNT(*) c FROM internal_workers WHERE last_seen >= ? AND worker_id NOT LIKE 'probe-%' AND worker_id NOT LIKE 'dev-%' AND worker_id NOT LIKE 'local-%'");
      $st->execute([$cut]);
    }
    return (int) ($st->fetch()['c'] ?? 0);
  } catch (Throwable $e) {
    return 0;
  }
}

// Optional maintenance: prune very old worker records to avoid ghost entries in total counts
function workers_prune_old(int $olderThanDays = 30): int
{
  try {
    $pdo = db();
    $days = max(1, $olderThanDays);
    $st = $pdo->prepare("DELETE FROM internal_workers WHERE last_seen < datetime('now', ?)");
    $st->execute([sprintf('-%d days', $days)]);
    return $st->rowCount();
  } catch (Throwable $e) {
    return 0;
  }
}


?>