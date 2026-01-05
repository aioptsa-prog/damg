<?php
require_once __DIR__ . '/auth.php';

function hmac_secret()
{
  return get_setting('internal_secret', '');
}
// Optional staged secret accepted during rotation grace period
function hmac_secret_next()
{
  return get_setting('internal_secret_next', '');
}

function hmac_body_sha256($raw)
{
  return hash('sha256', (string) $raw);
}

function hmac_sign($method, $path, $bodySha, $ts)
{
  $secret = hmac_secret();
  if ($secret === '')
    return '';
  $msg = strtoupper($method) . '|' . $path . '|' . $bodySha . '|' . $ts;
  return hash_hmac('sha256', $msg, $secret);
}

function hmac_verify_request($method, $path)
{
  $secret = hmac_secret();
  $secretNext = hmac_secret_next();
  if ($secret === '')
    return false;
  $sign = $_SERVER['HTTP_X_AUTH_SIGN'] ?? '';
  $ts = $_SERVER['HTTP_X_AUTH_TS'] ?? '';
  if (!$sign || !$ts)
    return false;
  if (!ctype_digit($ts))
    return false;
  $diff = abs(time() - (int) $ts);
  if ($diff > 300)
    return false; // 5-minute window
  // Prefer pre-read raw body if endpoint captured it to avoid php://input double-read
  $raw = isset($GLOBALS['__RAW_BODY__']) ? (string) $GLOBALS['__RAW_BODY__'] : file_get_contents('php://input');
  $bodySha = hmac_body_sha256($raw);
  $calc = hmac_sign($method, $path, $bodySha, $ts);
  if (hash_equals($calc, $sign))
    return true;
  // Accept HMAC with next secret during rotation grace
  if ($secretNext !== '') {
    $msg = strtoupper($method) . '|' . $path . '|' . $bodySha . '|' . $ts;
    $calc2 = hash_hmac('sha256', $msg, $secretNext);
    if (hash_equals($calc2, $sign))
      return true;
  }
  return false;
}

// Safe header accessor for built-in PHP server compatibility
function header_get(string $name): ?string
{
  $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
  return $_SERVER[$key] ?? null;
}

// Verify internal API auth with support for per-worker secret.
// Accept if any of the following is valid:
// - HMAC with global INTERNAL_SECRET (primary guard)
// - Legacy X-Internal-Secret matches settings('internal_secret')
// - If a per-worker secret exists for this worker_id: X-Worker-Secret matches that secret
// Optionally enforce per-worker secret strictly when setting per_worker_secret_required=1 and secret exists.
function verify_worker_auth(?string $workerId, string $method, string $path): bool
{
  // Try HMAC first
  $hmacOk = hmac_verify_request($method, $path);

  // If HMAC not provided or failed, try legacy X-Internal-Secret
  if (!$hmacOk) {
    $secretHeader = header_get('X-Internal-Secret') ?? '';
    $internalSecret = get_setting('internal_secret', '');

    // Accept legacy secret header if it matches
    if ($secretHeader !== '' && $internalSecret !== '' && hash_equals($internalSecret, $secretHeader)) {
      $hmacOk = true; // Legacy auth OK
    } else {
      return false; // Neither HMAC nor legacy secret worked
    }
  }

  // Per-worker secret enforcement (optional but recommended)
  $workerSecretHdr = (string) (header_get('X-Worker-Secret') ?? '');
  $requirePerWorker = get_setting('per_worker_secret_required', '0') === '1';
  $perWorkerSecret = '';
  try {
    if ($workerId) {
      $st = db()->prepare("SELECT secret, rotating_to FROM internal_workers WHERE worker_id=? LIMIT 1");
      $st->execute([$workerId]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if ($row) {
        $perWorkerSecret = (string) ($row['secret'] ?? '');
        $rotatingTo = (string) ($row['rotating_to'] ?? '');
        if ($rotatingTo !== '' && $workerSecretHdr !== '' && hash_equals($rotatingTo, $workerSecretHdr))
          return true;
      }
    }
  } catch (Throwable $e) { /* ignore */
  }

  if ($perWorkerSecret !== '') {
    if ($requirePerWorker) {
      return ($workerSecretHdr !== '' && hash_equals($perWorkerSecret, $workerSecretHdr));
    }
    if ($workerSecretHdr !== '' && hash_equals($perWorkerSecret, $workerSecretHdr))
      return true;
  }

  return true; // HMAC or legacy secret already verified
}

// Replay guard: returns true if (ts, body_sha, worker_id, method, path) is not seen in the recent window; false if replay.
function hmac_replay_check_ok(string $workerId, string $method, string $path, int $ts, string $bodySha, int $windowSec = 600): bool
{
  try {
    $pdo = db();
    // Prune old entries (best-effort)
    $cut = time() - max(60, min(3600, $windowSec));
    $pdo->prepare("DELETE FROM hmac_replay WHERE ts < ?")->execute([$cut]);
    // Insert unique tuple; if conflict -> replay
    $st = $pdo->prepare("INSERT INTO hmac_replay(worker_id, ts, body_sha, method, path, created_at) VALUES(?,?,?,?,?,datetime('now'))");
    $st->execute([$workerId ?: '', $ts, $bodySha, strtoupper($method), $path]);
    return true;
  } catch (Throwable $e) {
    return false;
  }
}
