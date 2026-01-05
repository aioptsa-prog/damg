<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/system.php';

header('Content-Type: application/json; charset=utf-8');

// Admin-only + CSRF required
$u = current_user();
if(!$u || ($u['role'] ?? '') !== 'admin'){ http_response_code(403); echo json_encode([]); exit; }
$csrf = $_GET['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if(!csrf_verify($csrf)) { http_response_code(400); echo json_encode([]); exit; }

// Per-IP rate limit (UPSERT, 30/min)
try{
  $pdo = db();
  // Derive client IP with trusted proxy allowlist
  $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
  $trustedList = array_filter(array_map('trim', explode(',', get_setting('trusted_proxy_ips',''))));
  $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
  $ip = $remote;
  if($xff && $trustedList && in_array($remote, $trustedList, true)){
    $cand = trim(explode(',', $xff)[0]);
    if(filter_var($cand, FILTER_VALIDATE_IP)) $ip = $cand;
  }
  $key = 'category_search';
  $window = intdiv(time(), 60) * 60; // epoch minute
  $max = (int)get_setting('rate_limit_category_search_per_min','30'); if($max <= 0) $max = 30;
  // Optional: relax for admins (multiply limit)
  try{ $mult = max(1, (int)get_setting('rate_limit_admin_multiplier','2')); if(($u['role'] ?? '')==='admin'){ $max *= $mult; } }catch(Throwable $e){}
  // Occasional prune of old windows (default >2 days)
  try{
    $ttlDays = max(1, (int)get_setting('ttl_rate_limit_days','2'));
    if(($window % 17) === 0){ $pdo->prepare("DELETE FROM rate_limit WHERE window_start < ?")->execute([ time() - $ttlDays*86400 ]); }
  }catch(Throwable $e){}
  $cur = 0;
  try{
    $sql = "INSERT INTO rate_limit(ip,\"key\",window_start,count) VALUES(?,?,?,1)
            ON CONFLICT(ip,\"key\",window_start) DO UPDATE SET count = rate_limit.count + 1
            RETURNING count";
    $st = $pdo->prepare($sql);
    $st->execute([$ip,$key,$window]);
    $cur = (int)($st->fetchColumn() ?: 0);
  }catch(Throwable $e){
    // Fallback for older SQLite without RETURNING support
    try{
      $pdo->prepare("INSERT OR IGNORE INTO rate_limit(ip,\"key\",window_start,count) VALUES(?,?,?,0)")->execute([$ip,$key,$window]);
      $pdo->prepare("UPDATE rate_limit SET count = count + 1 WHERE ip=? AND \"key\"=? AND window_start=?")->execute([$ip,$key,$window]);
      $st = $pdo->prepare("SELECT count FROM rate_limit WHERE ip=? AND \"key\"=? AND window_start=?");
      $st->execute([$ip,$key,$window]);
      $cur = (int)($st->fetchColumn() ?: 0);
    }catch(Throwable $_){}
  }
  // Dev-only diagnostic header
  try{ if(get_setting('app_env','prod')==='dev'){ header('X-Rate-Count: '.$cur); } }catch(Throwable $e){}
  if($cur > $max){
    http_response_code(429);
    // Monitor counters and optional alert
    try{
      $day = date('Y-m-d');
      $inc = function($kind,$cnt) use ($pdo,$day){ if($cnt<=0) return; $pdo->prepare("INSERT INTO usage_counters(day,kind,count) VALUES(?,?,?) ON CONFLICT(day,kind) DO UPDATE SET count=count+excluded.count")->execute([$day,$kind,$cnt]); };
      $inc('rl_category_search_429', 1);
      // Alert every 300 occurrences per day as a coarse signal
      try{
        $st=$pdo->prepare("SELECT count FROM usage_counters WHERE day=? AND kind='rl_category_search_429' LIMIT 1");
        $st->execute([$day]);
        $tot=(int)($st->fetchColumn() ?: 0);
        if($tot>0 && ($tot % 300)===0){
          $pdo->prepare("INSERT INTO alert_events(kind,message,payload,created_at) VALUES(?,?,?,datetime('now'))")
              ->execute(['rate_limit_spike','429 spikes for category_search', json_encode(['total_today'=>$tot,'ip'=>$ip,'window'=>$window], JSON_UNESCAPED_UNICODE)]);
        }
      }catch(Throwable $_){}
    }catch(Throwable $_){}
    echo json_encode(['ok'=>false,'error'=>'rate_limited','limit'=>$max,'window'=>'1m'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
  }
}catch(Throwable $e){ /* fail open to avoid blocking admin during migration */ }

$q = trim($_GET['q'] ?? '');
$lim = max(1, min(25, intval($_GET['limit'] ?? 10)));
$activeOnly = ($_GET['active_only'] ?? '1') === '1';
if(mb_strlen($q) < 2){ echo json_encode([]); exit; }

try{
  $pdo = db();
  $like = '%'.$q.'%';
  $starts = $q.'%';
  $sql = "SELECT id, name, slug, COALESCE(path,name) as path, COALESCE(depth,0) as depth, icon_type, icon_value, COALESCE(is_active,1) as is_active
          FROM categories
          WHERE (".($activeOnly?"COALESCE(is_active,1)=1 AND ":"")."(
            name LIKE :like OR slug LIKE :like OR COALESCE(path,name) LIKE :like
          ))
          ORDER BY (
            CASE WHEN name LIKE :starts THEN 0 WHEN slug LIKE :starts THEN 1 ELSE 2 END
          ), depth ASC, name ASC
          LIMIT :lim";
  $st = $pdo->prepare($sql);
  $st->bindValue(':like', $like);
  $st->bindValue(':starts', $starts);
  $st->bindValue(':lim', $lim, PDO::PARAM_INT);
  $st->execute();
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  // Normalize icons with fallback
  foreach($rows as &$r){
    $t = $r['icon_type']; $v = $r['icon_value'];
    if($t!== 'fa' && $t!== 'img' && $t!=='none'){ $t = 'fa'; }
    if(!$v || $t==='none'){ $t='fa'; $v='fa-folder-tree'; }
    $r['icon'] = ['type'=>$t, 'value'=>$v];
    unset($r['icon_type'], $r['icon_value']);
  }
  echo json_encode($rows, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){ http_response_code(500); echo json_encode([]); }
