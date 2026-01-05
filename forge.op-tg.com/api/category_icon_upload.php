<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/system.php';

header('Content-Type: application/json; charset=utf-8');
$u = current_user(); if(!$u || ($u['role']??'')!=='admin'){ http_response_code(403); echo json_encode(['ok'=>false]); exit; }
if($_SERVER['REQUEST_METHOD']!=='POST'){ http_response_code(405); echo json_encode(['ok'=>false]); exit; }
if(!csrf_verify($_POST['csrf'] ?? '')){ http_response_code(400); echo json_encode(['ok'=>false, 'err'=>'csrf']); exit; }

$pdo = db();
$cid = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;
$action = $_POST['action'] ?? 'upload';
if($cid<=0){ http_response_code(400); echo json_encode(['ok'=>false]); exit; }

$iconsDir = __DIR__ . '/../storage/icons/categories'; if(!is_dir($iconsDir)) @mkdir($iconsDir,0777,true);
$webBase = '/storage/icons/categories';

function sanitize_svg($svg){
  // Remove script tags, on* attributes, external xlink:href
  $svg = preg_replace('#<script[\s\S]*?</script>#i','',$svg);
  $svg = preg_replace('/ on[a-zA-Z]+\s*=\s*"[^"]*"/i','',$svg);
  $svg = preg_replace('/ on[a-zA-Z]+\s*=\s*\'[^\']*\'/i','',$svg);
  $svg = preg_replace('/ xlink:href\s*=\s*"https?:[^\"]*"/i','',$svg);
  return $svg;
}

try{
  if($action==='clear'){
    $pdo->prepare("UPDATE categories SET icon_type='none', icon_value=NULL, updated_at=datetime('now') WHERE id=?")->execute([$cid]);
    $pdo->prepare("INSERT INTO category_activity_log(action,category_id,user_id,details,created_at) VALUES('icon.clear',?,?,?,datetime('now'))")
        ->execute([$cid,$u['id'], json_encode([], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    echo json_encode(['ok'=>true]); exit;
  }
  if(empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])){ http_response_code(400); echo json_encode(['ok'=>false,'err'=>'nofile']); exit; }
  $f=$_FILES['file']; if($f['error']!==UPLOAD_ERR_OK){ http_response_code(400); echo json_encode(['ok'=>false,'err'=>'upload']); exit; }
  $size=(int)$f['size']; if($size<=0 || $size>200*1024){ http_response_code(400); echo json_encode(['ok'=>false,'err'=>'size']); exit; }
  $tmp=$f['tmp_name']; $name=strtolower($f['name']);
  $isSvg = (strpos($f['type'],'svg')!==false) || preg_match('/\.svg$/',$name);
  $isPng = (strpos($f['type'],'png')!==false) || preg_match('/\.png$/',$name);
  if(!$isSvg && !$isPng){ http_response_code(400); echo json_encode(['ok'=>false,'err'=>'type']); exit; }
  if($isPng){
    $info=@getimagesize($tmp); if(!$info){ http_response_code(400); echo json_encode(['ok'=>false,'err'=>'img']); exit; }
    $w=$info[0]??0; $h=$info[1]??0; if($w>512||$h>512){ http_response_code(400); echo json_encode(['ok'=>false,'err'=>'dims']); exit; }
    $dest=$iconsDir.'/'.$cid.'.png';
    if(!move_uploaded_file($tmp,$dest)){ http_response_code(500); echo json_encode(['ok'=>false]); exit; }
    @chmod($dest,0666);
    $pdo->prepare("UPDATE categories SET icon_type='img', icon_value=:v, updated_at=datetime('now') WHERE id=:id")
        ->execute([':v'=>$webBase.'/'.$cid.'.png', ':id'=>$cid]);
    $pdo->prepare("INSERT INTO category_activity_log(action,category_id,user_id,details,created_at) VALUES('icon.upload',?,?,?,datetime('now'))")
        ->execute([$cid,$u['id'], json_encode(['type'=>'png'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    echo json_encode(['ok'=>true, 'path'=>$webBase.'/'.$cid.'.png']); exit;
  } else {
    $svg=file_get_contents($tmp); $svg=sanitize_svg($svg);
    $dest=$iconsDir.'/'.$cid.'.svg';
    if(file_put_contents($dest,$svg)===false){ http_response_code(500); echo json_encode(['ok'=>false]); exit; }
    @chmod($dest,0666);
    $pdo->prepare("UPDATE categories SET icon_type='img', icon_value=:v, updated_at=datetime('now') WHERE id=:id")
        ->execute([':v'=>$webBase.'/'.$cid.'.svg', ':id'=>$cid]);
    $pdo->prepare("INSERT INTO category_activity_log(action,category_id,user_id,details,created_at) VALUES('icon.upload',?,?,?,datetime('now'))")
        ->execute([$cid,$u['id'], json_encode(['type'=>'svg'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    echo json_encode(['ok'=>true, 'path'=>$webBase.'/'.$cid.'.svg']); exit;
  }
}catch(Throwable $e){ http_response_code(500); echo json_encode(['ok'=>false]); }
