<?php
require_once __DIR__ . '/../../bootstrap.php';

$base = getenv('BASE_URL') ?: app_base_url();
if(!$base){
    fwrite(STDERR, "Base URL is empty. Set worker_base_url in settings.\n");
    exit(2);
}
$url = rtrim($base,'/').'/api/heartbeat.php';
$secret = get_setting('internal_secret','');
$headers = "X-Internal-Secret: ".$secret."\r\nX-Worker-Id: diag-probe";
$ctx = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => $headers,
        'ignore_errors' => true,
        'timeout' => 20,
    ]
]);
$res = @file_get_contents($url, false, $ctx);
echo "URL=$url\n";
if($res === false){
    $e = error_get_last();
    echo "RES=(null)\n";
    if($e){ echo "PHP_WARNING=".$e['message']."\n"; }
    exit(1);
}
echo "RES=$res\n";
exit(0);
