<?php
require_once __DIR__ . '/../bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');
$q = isset($argv[1]) ? (string)$argv[1] : 'Clinic';
$base = 'http://127.0.0.1:8080';
$cookieFile = __DIR__ . '/../storage/tmp/cookies.txt';
@mkdir(dirname($cookieFile), 0777, true);
@unlink($cookieFile);

$ch = curl_init($base.'/api/dev_session_bootstrap.php');
curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEJAR=>$cookieFile,CURLOPT_COOKIEFILE=>$cookieFile]);
$raw = curl_exec($ch); curl_close($ch);
$csrf = (json_decode($raw,true)['csrf'] ?? '');

$url = $base.'/api/category_search.php?q='.rawurlencode($q).'&limit=5&csrf='.rawurlencode($csrf);
$ch = curl_init($url);
curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_COOKIEJAR=>$cookieFile,CURLOPT_COOKIEFILE=>$cookieFile]);
$resp = curl_exec($ch); curl_close($ch);
echo $resp;
