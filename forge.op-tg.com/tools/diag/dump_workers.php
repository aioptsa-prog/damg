<?php
// CLI helper: dump worker presence info for diagnostics
if (php_sapi_name() !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }
require_once __DIR__ . '/../../bootstrap.php';
$pdo = db();
$cut = date('Y-m-d H:i:s', time() - workers_online_window_sec());
$st = $pdo->query("SELECT worker_id, last_seen, status, active_job_id, host FROM internal_workers ORDER BY last_seen DESC");
while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    $flag = ($row['last_seen'] < $cut) ? 'offline' : 'online';
    $host = $row['host'] ?? '';
    printf("%s|%s|%s|%s|%s|%s\n",
        $row['worker_id'] ?? '',
        $row['last_seen'] ?? '',
        $row['status'] ?? '',
        $row['active_job_id'] ?? '',
        $host,
        $flag
    );
}
