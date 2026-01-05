<?php include __DIR__ . '/../bootstrap.php'; $u=current_user(); if(!$u||$u['role']!=='admin'){ http_response_code(403); echo 'forbidden'; exit; } require_once __DIR__.'/../lib/csrf.php';
if($_SERVER['REQUEST_METHOD']!=='POST' || !csrf_verify($_POST['csrf'] ?? '')){ http_response_code(400); echo 'bad request'; exit; }
$wid = isset($_POST['id']) ? trim((string)$_POST['id']) : '';
$act = isset($_POST['action']) ? trim((string)$_POST['action']) : '';
if($wid===''){ http_response_code(400); echo 'missing id'; exit; }
$raw = get_setting('cb_open_workers_json','[]'); $arr = json_decode($raw,true); if(!is_array($arr)) $arr=[];
if($act==='open'){ if(!in_array($wid,$arr,true)) $arr[]=$wid; }
elseif($act==='close'){ $arr = array_values(array_filter($arr, fn($x)=>$x!==$wid)); }
set_setting('cb_open_workers_json', json_encode($arr));
try{ db()->prepare("INSERT INTO audit_logs(user_id,action,target,payload,created_at) VALUES(?,?,?,?,datetime('now'))")
  ->execute([$u['id'],'cb_toggle','worker:'.$wid,json_encode(['action'=>$act])]); }catch(Throwable $e){}
header('Location: '.linkTo('admin/workers.php')); exit;