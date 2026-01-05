<?php require_once __DIR__ . '/../bootstrap.php'; require_once __DIR__ . '/../lib/wh_sender.php'; $u=require_login();
if(!csrf_verify($_POST['csrf'] ?? '')){ http_response_code(400); echo 'CSRF'; exit; }
$lead_id = isset($_POST['lead_id']) ? (int)$_POST['lead_id'] : null;
$phone = preg_replace('/\D+/','', $_POST['phone'] ?? '');
$name  = trim($_POST['name'] ?? '');
$back  = $_POST['back'] ?? '';
// تأمين إعادة التوجيه: قبول المسارات الداخلية فقط
if($back){
	$parsed = parse_url($back);
	$is_relative = !isset($parsed['scheme']) && !isset($parsed['host']);
	if(!$is_relative){ $back = ''; }
}
if($back===''){
	$back = linkTo($u['role']==='admin'?'admin/leads.php':'agent/dashboard.php');
}
if($phone===''){ header('Location: '.$back.'?werr=phone'); exit; }
$msg_tpl = trim($u['whatsapp_message'] ?? '') ?: get_setting('whatsapp_message','مرحبًا {name}');
$msg = strtr($msg_tpl, ['{name}'=>$name, '{phone}'=>$phone]);
$url = get_setting('washeej_url','https://wa.washeej.com/api/qr/rest/send_message');
$instance = get_setting('washeej_instance_id','');
$agent_id = null;
if($lead_id){ $stmt=db()->prepare("SELECT agent_id FROM assignments WHERE lead_id=?"); $stmt->execute([$lead_id]); $row=$stmt->fetch(); $agent_id=$row['agent_id'] ?? null; }
$token = pick_washeej_token($agent_id, $u['id']); $from  = pick_washeej_sender($agent_id, $u['id']);
$payload = ['requestType'=>'POST','messageType'=>'text','from'=>$from,'to'=>$phone,'text'=>$msg]; if($instance!==''){ $payload['instanceId']=$instance; }
list($code,$raw,$err) = http_post_json_washeej($url, $token, $payload);
if(!($code>=200 && $code<300)){ $params=['requestType'=>'GET','token'=>$token,'from'=>$from,'to'=>$phone,'messageType'=>'text','text'=>$msg]; if($instance!==''){ $params['instanceId']=$instance; } list($code,$raw,$err) = http_get_washeej($url, $params); }
log_washeej($lead_id,$agent_id,$u['id'],$code,$raw ?: $err);
$q = http_build_query(['wcode'=>$code, 'werr'=>$err ? $err : '']);
header('Location: '.$back.(strpos($back,'?')===false?'?':'&').$q); exit;
