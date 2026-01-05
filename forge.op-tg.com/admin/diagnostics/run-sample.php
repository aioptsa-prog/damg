<?php
require_once __DIR__ . '/../../bootstrap.php'; require_once __DIR__ . '/../../lib/csrf.php';
$u = require_role('admin');
if($_SERVER['REQUEST_METHOD']!=='POST'){ header('Location: '.linkTo('admin/diagnostics/index.php')); exit; }
if(!csrf_verify($_POST['csrf'] ?? '')){ http_response_code(400); echo 'CSRF فشل التحقق'; exit; }

$type = trim((string)($_POST['type'] ?? 'diagnostic_echo'));
$payloadRaw = trim((string)($_POST['payload'] ?? ''));
if($payloadRaw===''){
  $payload = [ 'query'=>'demo', 'll'=>get_setting('default_ll','24.7136,46.6753'), 'radius_km'=>5, 'lang'=>get_setting('default_language','ar'), 'region'=>get_setting('default_region','sa'), 'target'=>1 ];
} else {
  $payload = json_decode($payloadRaw, true); if(!is_array($payload)){ $payload = ['echo'=>$payloadRaw]; }
}

$script = __DIR__ . '/../../tools/ops/enqueue_sample.php';
if(!is_file($script)){
  http_response_code(500); echo 'لم يتم العثور على سكربت الإدراج (enqueue_sample.php)'; exit;
}
$args = ['php', $script, '--type', $type, '--payload', json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)];
$desc = [1 => ['pipe','w'], 2 => ['pipe','w']];
$proc = proc_open($args, $desc, $pipes, dirname($script));
if(!is_resource($proc)){
  http_response_code(500); echo 'تعذر تشغيل PHP-CLI'; exit;
}
$out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
$code = proc_close($proc);
if($code!==0){ http_response_code(500); echo 'فشل التشغيل: '.htmlspecialchars($err ?: $out); exit; }
$j = json_decode($out,true);
if(!$j || empty($j['ok'])){ http_response_code(500); echo 'خروج غير متوقع: '.htmlspecialchars($out); exit; }
$jobId = (int)$j['job_id'];
header('Location: '.linkTo('admin/diagnostics/index.php?m=ok&job='.$jobId));
exit;
