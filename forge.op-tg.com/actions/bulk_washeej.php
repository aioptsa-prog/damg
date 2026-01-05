<?php require_once __DIR__ . '/../bootstrap.php'; require_once __DIR__ . '/../lib/wh_sender.php'; $u=require_login();
if(!csrf_verify($_POST['csrf'] ?? '')){ http_response_code(400); echo 'CSRF'; exit; }
$lead_ids = array_map('intval', $_POST['lead_ids'] ?? []); $back = $_POST['back'] ?? '';
// تأمين إعادة التوجيه: قبول المسارات الداخلية فقط
if($back){
  $parsed = parse_url($back);
  $is_relative = !isset($parsed['scheme']) && !isset($parsed['host']);
  if(!$is_relative){ $back = ''; }
}
if($back===''){
  $back = linkTo($u['role']==='admin'?'admin/leads.php':'agent/dashboard.php');
}
if(!$lead_ids){ header('Location: '.$back.'?werr=noselection'); exit; }
$msg_tpl = trim($u['whatsapp_message'] ?? '') ?: get_setting('whatsapp_message','مرحبًا {name}');
$url = get_setting('washeej_url','https://wa.washeej.com/api/qr/rest/send_message');
$instance = get_setting('washeej_instance_id','');
$pdo = db();
$in = implode(',', array_fill(0, count($lead_ids), '?'));
$stmt = $pdo->prepare("SELECT l.id,l.name,l.phone,(SELECT agent_id FROM assignments a WHERE a.lead_id=l.id) as agent_id FROM leads l WHERE l.id IN ($in)");
$stmt->execute($lead_ids); $rows=$stmt->fetchAll();
$ok=0; $fail=0;
foreach($rows as $r){
  $phone=preg_replace('/\D+/','', $r['phone']); if($phone===''){ $fail++; continue; }
  $msg = strtr($msg_tpl, ['{name}'=>$r['name']??'', '{phone}'=>$phone]);
  $token = pick_washeej_token($r['agent_id'] ?? null, $u['id']); $from  = pick_washeej_sender($r['agent_id'] ?? null, $u['id']);
  $payload=['requestType'=>'POST','messageType'=>'text','from'=>$from,'to'=>$phone,'text'=>$msg]; if($instance!==''){ $payload['instanceId']=$instance; }
  list($code,$raw,$err) = http_post_json_washeej($url, $token, $payload);
  if(!($code>=200 && $code<300)){ $params=['requestType'=>'GET','token'=>$token,'from'=>$from,'to'=>$phone,'messageType'=>'text','text'=>$msg]; if($instance!==''){ $params['instanceId']=$instance; } list($code,$raw,$err) = http_get_washeej($url, $params); }
  log_washeej($r['id'],$r['agent_id'] ?? null,$u['id'],$code,$raw ?: $err);
  if($code>=200 && $code<300){ $ok++; } else { $fail++; }
}
$q = http_build_query(['sent_ok'=>$ok,'sent_fail'=>$fail]);
header('Location: '.$back.(strpos($back,'?')===false?'?':'&').$q); exit;
