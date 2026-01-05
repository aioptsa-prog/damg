<?php
require_once __DIR__ . '/../config/db.php';
function counter_today($k){ $st=db()->prepare("SELECT count FROM usage_counters WHERE day=? AND kind=?"); $st->execute([date('Y-m-d'),$k]); $r=$st->fetch(); return (int)($r['count']??0); }
function counter_inc($k,$d){ db()->prepare("INSERT INTO usage_counters(day,kind,count) VALUES(?,?,?) ON CONFLICT(day,kind) DO UPDATE SET count=count+excluded.count")->execute([date('Y-m-d'),$k,(int)$d]); }
function cap_remaining_google_details(){ $pdo=db(); $cap=(int)($pdo->query("SELECT value FROM settings WHERE key='daily_details_cap'")->fetch()['value'] ?? 1000); $used=counter_today('google_details'); return max(0,$cap-$used); }
