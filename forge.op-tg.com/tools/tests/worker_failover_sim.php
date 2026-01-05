<?php
/**
 * Worker failover simulation: create a diagnostic job, let worker A lease it,
 * expire the lease, and ensure worker B reclaims and completes the job.
 */
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../lib/security.php';

function ensure_setting(string $key, string $value): void {
    $pdo = db();
    $pdo->prepare("INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value")
        ->execute([$key, $value]);
}

function ensure_user(string $mobile, string $name, string $role): int {
    $pdo = db();
    $st = $pdo->prepare("SELECT id FROM users WHERE mobile=? LIMIT 1");
    $st->execute([$mobile]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) { return (int)$row['id']; }
    $hash = password_hash($mobile, PASSWORD_BCRYPT);
    $pdo->prepare("INSERT INTO users(mobile,name,role,password_hash,active,created_at) VALUES(?,?,?,?,1,datetime('now'))")
        ->execute([$mobile, $name, $role, $hash]);
    return (int)$pdo->lastInsertId();
}

function register_worker(string $workerId, string $host = 'diag-host'): void {
    $pdo = db();
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("INSERT INTO internal_workers(worker_id,last_seen,status,host,version) VALUES(?,?,?,?,?)
                   ON CONFLICT(worker_id) DO UPDATE SET last_seen=excluded.last_seen, status=excluded.status, host=excluded.host, version=excluded.version")
        ->execute([$workerId, $now, 'idle', $host, 'diag']);
}

function http_req(string $url, string $method, array $headers = [], ?string $body = null): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
    if ($headers) { curl_setopt($ch, CURLOPT_HTTPHEADER, $headers); }
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['code' => 0, 'body' => '', 'headers' => [], 'error' => $err];
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsz = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $hraw = substr($resp, 0, $hsz);
    $bodyOut = substr($resp, $hsz);
    curl_close($ch);
    $hdrs = [];
    foreach (explode("\r\n", trim($hraw)) as $line) {
        if (strpos($line, ':') !== false) {
            [$k, $v] = explode(':', $line, 2);
            $hdrs[strtolower(trim($k))] = trim($v);
        }
    }
    return ['code' => $code, 'body' => $bodyOut, 'headers' => $hdrs, 'hraw' => $hraw];
}

function hmac_headers(string $secret, string $method, string $path, string $body = ''): array {
    $ts = (string)time();
    $sha = hash('sha256', $body);
    $msg = strtoupper($method) . '|' . $path . '|' . $sha . '|' . $ts;
    $sign = hash_hmac('sha256', $msg, $secret);
    return ['X-Auth-Ts: ' . $ts, 'X-Auth-Sign: ' . $sign];
}

function worker_pull(string $base, string $secret, string $workerId, int $leaseSec = 120): array {
    $path = '/api/pull_job.php';
    $headers = array_merge([
        'X-Worker-Id: ' . $workerId,
        'X-Internal-Secret: ' . $secret,
    ], hmac_headers($secret, 'GET', $path));
    $res = http_req(rtrim($base, '/') . $path . '?lease_sec=' . $leaseSec, 'GET', $headers);
    $json = $res['code'] === 200 ? json_decode($res['body'], true) : null;
    return ['http' => $res, 'json' => $json];
}

function worker_report(string $base, string $secret, string $workerId, array $payload): array {
    $path = '/api/report_results.php';
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $headers = array_merge([
        'Content-Type: application/json',
        'X-Worker-Id: ' . $workerId,
        'X-Internal-Secret: ' . $secret,
    ], hmac_headers($secret, 'POST', $path, $body));
    $res = http_req(rtrim($base, '/') . $path, 'POST', $headers, $body);
    $json = $res['code'] === 200 ? json_decode($res['body'], true) : null;
    return ['http' => $res, 'json' => $json];
}

$base = getenv('BASE_URL') ?: app_base_url();
if ($base === '') { fwrite(STDERR, "Base URL not set; configure worker_base_url or BASE_URL env.\n"); exit(2); }

$pdo = db();
ensure_setting('internal_server_enabled', '1');
$secret = get_setting('internal_secret', '');
if ($secret === '') {
    $secret = 'diag-' . bin2hex(random_bytes(8));
    ensure_setting('internal_secret', $secret);
}
ensure_setting('per_worker_secret_required', '0');

$adminId = ensure_user('500000301', 'Failover QA', 'admin');
$now = date('Y-m-d H:i:s');

// Create diagnostic job with high priority so it is selected first.
$pdo->prepare("INSERT INTO internal_jobs(requested_by_user_id, role, query, ll, radius_km, lang, region, status, created_at, updated_at, queued_at, priority, target_count, job_type, city_hint)
               VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
    ->execute([
        $adminId,
        'agent',
        'Failover QA sweep',
        '24.7136,46.6753',
        3,
        'ar',
        'sa',
        'queued',
        $now,
        $now,
        $now,
        999,
        5,
        'diagnostic',
        'الرياض'
    ]);
$jobId = (int)$pdo->lastInsertId();

register_worker('unit-alpha');
register_worker('unit-beta');

$timeline = [];

// Worker A pulls the job
$pullA = worker_pull($base, $secret, 'unit-alpha', 90);
$timeline['pull_alpha'] = [
    'http_code' => $pullA['http']['code'],
    'job' => $pullA['json']['job'] ?? null,
];
$attemptA = $pullA['json']['job']['attempt_id'] ?? null;

// Force lease expiry while worker A still marked online
$pdo->prepare("UPDATE internal_jobs SET lease_expires_at=datetime('now','-5 minutes'), updated_at=datetime('now') WHERE id=?")
    ->execute([$jobId]);

// Worker B attempts to pull -> should reclaim after requeue
$pullB = worker_pull($base, $secret, 'unit-beta', 120);
$timeline['pull_beta'] = [
    'http_code' => $pullB['http']['code'],
    'job' => $pullB['json']['job'] ?? null,
];
$attemptB = $pullB['json']['job']['attempt_id'] ?? null;

// Simulate worker B reporting completion
$items = [
    ['name' => 'QA Clinic 1', 'city' => 'الرياض', 'country' => 'sa', 'phone' => '050'.random_int(5000000, 5999999)],
    ['name' => 'QA Clinic 2', 'city' => 'Riyadh', 'country' => 'sa', 'phone' => '+96655'.random_int(1000000, 1999999)],
];
$reportPayload = [
    'job_id' => $jobId,
    'attempt_id' => $attemptB,
    'items' => $items,
    'cursor' => count($items),
    'done' => true,
    'extend_lease_sec' => 180,
];
$report = worker_report($base, $secret, 'unit-beta', $reportPayload);
$timeline['report_beta'] = [
    'http_code' => $report['http']['code'],
    'response' => $report['json'],
];

$jobSnap = $pdo->prepare("SELECT status, worker_id, finished_at, lease_expires_at, attempts, attempt_id FROM internal_jobs WHERE id=?");
$jobSnap->execute([$jobId]);
$timeline['job_final'] = $jobSnap->fetch(PDO::FETCH_ASSOC);

$attemptsLog = $pdo->prepare("SELECT success, log_excerpt FROM job_attempts WHERE job_id=? ORDER BY id DESC LIMIT 3");
$attemptsLog->execute([$jobId]);
$timeline['attempts_log'] = $attemptsLog->fetchAll(PDO::FETCH_ASSOC);

$timeline['job_id'] = $jobId;
$timeline['attempt_alpha'] = $attemptA;
$timeline['attempt_beta'] = $attemptB;

echo json_encode($timeline, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
