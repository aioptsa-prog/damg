<?php
require_once __DIR__ . '/../bootstrap.php';
function pick_washeej_token($lead_agent_id, $sent_by_user){
  $use = get_setting('washeej_use_per_agent','0')==='1'; $pdo = db();
  if($use && $lead_agent_id){ $st=$pdo->prepare("SELECT washeej_token FROM users WHERE id=?"); $st->execute([$lead_agent_id]); $r=$st->fetch(); if($r && !empty($r['washeej_token'])) return $r['washeej_token']; }
  if($use && $sent_by_user){ $st=$pdo->prepare("SELECT washeej_token FROM users WHERE id=?"); $st->execute([$sent_by_user]); $r=$st->fetch(); if($r && !empty($r['washeej_token'])) return $r['washeej_token']; }
  return get_setting('washeej_token','');
}
function pick_washeej_sender($lead_agent_id, $sent_by_user){
  $use = get_setting('washeej_use_per_agent','0')==='1'; $pdo = db();
  if($use && $lead_agent_id){ $st=$pdo->prepare("SELECT washeej_sender FROM users WHERE id=?"); $st->execute([$lead_agent_id]); $r=$st->fetch(); if($r && !empty($r['washeej_sender'])) return $r['washeej_sender']; }
  if($use && $sent_by_user){ $st=$pdo->prepare("SELECT washeej_sender FROM users WHERE id=?"); $st->execute([$sent_by_user]); $r=$st->fetch(); if($r && !empty($r['washeej_sender'])) return $r['washeej_sender']; }
  return get_setting('washeej_sender','');
}
function http_post_json_washeej($url, $token, $payload){
  $ch = curl_init($url); $payload['token'] = $token;
  $headers = ['Content-Type: application/json', 'token: ' . $token];
  curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_POSTFIELDS=>json_encode($payload, JSON_UNESCAPED_UNICODE)]);
  $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch); return [$code, $raw ?: '', $err ?: ''];
}
function http_get_washeej($url, $params){
  $full = $url . '?' . http_build_query($params);
  $ch = curl_init($full);
  curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_HTTPGET=>true]);
  $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err=curl_error($ch);
  curl_close($ch); return [$code, $raw ?: '', $err ?: ''];
}
function log_washeej($lead_id,$agent_id,$sent_by_user_id,$code,$resp){
  db()->prepare("INSERT INTO washeej_logs(lead_id,agent_id,sent_by_user_id,http_code,response,created_at) VALUES(?,?,?,?,?,datetime('now'))")->execute([$lead_id,$agent_id,$sent_by_user_id,$code,$resp]);
}
