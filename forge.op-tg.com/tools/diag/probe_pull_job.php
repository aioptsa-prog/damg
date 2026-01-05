<?php
// CLI-only: probe /api/pull_job.php with proper headers to debug 401/500 issues safely
if (php_sapi_name() !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }
require_once __DIR__ . '/../../bootstrap.php';

$base = getenv('BASE_URL') ?: app_base_url();
if($base===''){ fwrite(STDERR, "Base URL not derivable; set worker_base_url in settings.\n"); exit(2); }
$url = rtrim($base,'/').'/api/pull_job.php?lease_sec=120';
$secret = get_setting('internal_secret','');
if($secret===''){ fwrite(STDERR, "internal_secret not set. Enable internal server.\n"); exit(3); }
$workerId = 'probe-'.substr(bin2hex(random_bytes(4)),0,8);
$headers = [ 'X-Internal-Secret: '.$secret, 'X-Worker-Id: '.$workerId ];
// Enforce HMAC auth to mirror real workers
$ts = (string)time();
$method = 'GET';
$path = '/api/pull_job.php';
$bodySha = hash('sha256', '');
$msg = strtoupper($method) . '|' . $path . '|' . $bodySha . '|' . $ts;
$sign = hash_hmac('sha256', $msg, $secret);
$headers[] = 'X-Auth-Ts: '.$ts;
$headers[] = 'X-Auth-Sign: '.$sign;

$ch = curl_init($url);
curl_setopt_array($ch, [ CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>20, CURLOPT_SSL_VERIFYPEER=>false, CURLOPT_HTTPHEADER=>$headers ]);
$raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
echo "URL=$url\n";
echo "CODE=$code\n";
echo "RES=".($raw ?: '')."\n";
exit(0);
