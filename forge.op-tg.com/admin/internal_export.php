<?php
require __DIR__ . '/../bootstrap.php';
$u = require_role('admin');
$pdo = db();

// Build filters similar to admin/internal.php
$status = trim($_GET['status'] ?? '');
$q = trim($_GET['q'] ?? '');
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');
$w = [];
if($status!==''){ $w[] = "j.status=".$pdo->quote($status); }
if($q!==''){ $qq = '%'.str_replace(['%','_'], ['\\%','\\_'], $q).'%'; $w[] = "(j.query LIKE ".$pdo->quote($qq)." ESCAPE '\\' OR j.ll LIKE ".$pdo->quote($qq)." ESCAPE '\\')"; }
if($from!=='' && preg_match('/^\d{4}-\d{2}-\d{2}/',$from)){ $w[] = "j.created_at >= ".$pdo->quote($from.' 00:00:00'); }
if($to!=='' && preg_match('/^\d{4}-\d{2}-\d{2}/',$to)){ $w[] = "j.created_at <= ".$pdo->quote($to.' 23:59:59'); }
$where = $w ? ('WHERE '.implode(' AND ',$w)) : '';

// Fetch rows (higher limit for export)
$sql = "SELECT j.*, u.name as requested_by FROM internal_jobs j LEFT JOIN users u ON u.id=j.requested_by_user_id $where ORDER BY j.id DESC LIMIT 5000";
$stm = $pdo->query($sql);
$rows = $stm ? $stm->fetchAll(PDO::FETCH_ASSOC) : [];

// Prepare CSV headers
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="internal_jobs_'.date('Ymd_His').'.csv"');

$out = fopen('php://output', 'w');
// UTF-8 BOM for Excel compatibility
fwrite($out, "\xEF\xBB\xBF");

// Columns
$columns = [
  'id','status','done_reason','requested_by','role','agent_id','query','target_count','ll','radius_km','worker_id',
  'attempts','result_count','created_at','last_progress_at','finished_at','lease_expires_at','lease_expired','last_cursor','progress_count'
];
fputcsv($out, $columns);

foreach($rows as $r){
  $leaseExpired = ($r['status']==='processing' && $r['lease_expires_at'] && strtotime($r['lease_expires_at']) < time()) ? '1' : '0';
  $row = [
    $r['id'],
    $r['status'],
    $r['done_reason'],
    $r['requested_by'],
    $r['role'],
    $r['agent_id'],
    $r['query'],
    isset($r['target_count']) ? (int)$r['target_count'] : null,
    $r['ll'],
    isset($r['radius_km']) ? (int)$r['radius_km'] : null,
    $r['worker_id'],
    isset($r['attempts']) ? (int)$r['attempts'] : 0,
    isset($r['result_count']) ? (int)$r['result_count'] : 0,
    $r['created_at'],
    $r['last_progress_at'],
    $r['finished_at'],
    $r['lease_expires_at'],
    $leaseExpired,
    isset($r['last_cursor']) ? (int)$r['last_cursor'] : 0,
    isset($r['progress_count']) ? (int)$r['progress_count'] : 0,
  ];
  fputcsv($out, $row);
}

fclose($out);
exit;
?>
