<?php
// CSRF simple helper using session token
function csrf_token(){
  if(session_status()===PHP_SESSION_NONE) session_start();
  if(empty($_SESSION['csrf_token'])){ $_SESSION['csrf_token']=bin2hex(random_bytes(32)); }
  return $_SESSION['csrf_token'];
}
function csrf_input(){
  $t = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
  return '<input type="hidden" name="csrf" value="'.$t.'">';
}
function csrf_verify($token){
  if(session_status()===PHP_SESSION_NONE) session_start();
  $t = $_SESSION['csrf_token'] ?? '';
  return is_string($token) && is_string($t) && hash_equals($t, $token);
}
