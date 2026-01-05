<?php
// tools/ops/retention_purge.php
// Purge old operational data based on TTLs. Supports --dry-run to preview deletions.
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../lib/system.php';

function env_or_default(string $key, $default) {
    $v = getenv($key);
    if ($v === false || $v === '') return $default;
    if (is_numeric($default)) return (int)$v;
    return $v;
}

// Allow dry-run via CLI flags or query string when invoked via web
$dryRun = (in_array('--dry-run', $argv ?? [], true) || in_array('-n', $argv ?? [], true) || (isset($_GET['dry-run']) && $_GET['dry-run']=='1'));

// TTLs (days), prefer settings then env
$ttlReplayDays = (int)env_or_default('RET_TTL_REPLAY_DAYS', (int)get_setting('ttl_hmac_replay_days','7'));
$ttlRateLimitDays = (int)env_or_default('RET_TTL_RATELIMIT_DAYS', (int)get_setting('ttl_rate_limit_days','2'));
$ttlJobAttemptsDays = (int)env_or_default('RET_TTL_JOB_ATTEMPTS_DAYS', (int)get_setting('ttl_job_attempts_days','90'));
$ttlDeadLetterDays = (int)env_or_default('RET_TTL_DLQ_DAYS', (int)get_setting('ttl_dead_letter_jobs_days','90'));

// Log rotation threshold (bytes) and keep count
$rotateBytes = (int)env_or_default('RET_ROTATE_BYTES', (int)get_setting('ret_rotate_bytes', (string)(10 * 1024 * 1024))); // 10MB default
$keepLogs = (int)env_or_default('RET_LOG_KEEP', (int)get_setting('synthetic_log_keep', '5'));

// Obtain PDO from app's db() helper
$db = db();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function ago_days(int $days): int { return time() - ($days * 86400); }

function table_exists($db, string $table): bool {
    try{
        $st = $db->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
    }catch(Throwable $e){ return false; }
}

function table_has_column($db, string $table, string $col): bool {
    try{
        $rs = $db->query("PRAGMA table_info('".$table."')");
        while($r = $rs->fetch(PDO::FETCH_ASSOC)){
            if(strcasecmp((string)$r['name'], $col)===0) return true;
        }
        return false;
    }catch(Throwable $e){ return false; }
}

function pick_column($db, string $table, array $candidates): ?string {
    foreach($candidates as $c){ if(table_has_column($db, $table, $c)) return $c; }
    return null;
}

function count_and_purge($db, string $table, array $candidateColumns, int $olderThanTs, bool $dryRun): array {
    if(!table_exists($db, $table)) return [0, 'table missing'];
    $col = pick_column($db, $table, $candidateColumns);
    if($col === null) return [0, 'no suitable timestamp column'];
    $countStmt = $db->prepare("SELECT COUNT(*) AS c FROM {$table} WHERE {$col} < :ts");
    $countStmt->execute([':ts' => $olderThanTs]);
    $count = (int)$countStmt->fetch(PDO::FETCH_ASSOC)['c'];
    if (!$dryRun && $count > 0) {
        $del = $db->prepare("DELETE FROM {$table} WHERE {$col} < :ts");
        $del->execute([':ts' => $olderThanTs]);
    }
    return [$count, (!$dryRun ? 'deleted' : 'would delete') . " from {$table} by {$col}"];
}

function rotate_log(string $path, int $rotateBytes, bool $dryRun): ?string {
    if (!file_exists($path)) return null;
    $size = filesize($path);
    if ($size === false || $size < $rotateBytes) return null;
    $dir = dirname($path);
    $base = basename($path);
    $stamp = date('Ymd_His');
    $rotated = $dir . DIRECTORY_SEPARATOR . $base . '.' . $stamp;
    if ($dryRun) return "would rotate {$path} -> {$rotated}.gz";
    // Move and gzip
    if (!rename($path, $rotated)) return "failed to rotate {$path}";
    $gz = gzopen($rotated . '.gz', 'wb9');
    $in = fopen($rotated, 'rb');
    if ($gz && $in) {
        while (!feof($in)) {
            $buf = fread($in, 8192);
            if ($buf === false) break;
            gzwrite($gz, $buf);
        }
    }
    if ($in) fclose($in);
    if ($gz) gzclose($gz);
    @unlink($rotated);
    // recreate empty file
    touch($path);
    return "rotated {$path} -> {$rotated}.gz";
}

function prune_rotated_logs(string $path, int $keep, bool $dryRun): ?string {
    $dir = dirname($path);
    $base = basename($path);
    $pattern = $dir . DIRECTORY_SEPARATOR . $base . '.*.gz';
    $files = glob($pattern) ?: [];
    if (count($files) <= $keep) return null;
    // Sort by mtime desc, keep newest N, delete the rest
    usort($files, function($a,$b){ return filemtime($b) <=> filemtime($a); });
    $toDelete = array_slice($files, $keep);
    $deleted = [];
    foreach($toDelete as $f){ if($dryRun){ $deleted[] = $f; } else { @unlink($f); $deleted[] = $f; } }
    return $deleted ? ('pruned '.count($deleted).' old logs') : null;
}

// Summary array
$summary = [];

// hmac_replay purge
[$c1, $a1] = count_and_purge($db, 'hmac_replay', ['ts','created_at'], ago_days($ttlReplayDays), $dryRun);
$summary[] = [ 'table' => 'hmac_replay', 'action' => $a1, 'count' => $c1, 'cutoff' => $ttlReplayDays . 'd' ];

// rate_limit purge — common columns: window_start, ts, created_at
[$c2, $a2] = count_and_purge($db, 'rate_limit', ['window_start','ts','created_at'], ago_days($ttlRateLimitDays), $dryRun);
$summary[] = [ 'table' => 'rate_limit', 'action' => $a2, 'count' => $c2, 'cutoff' => $ttlRateLimitDays . 'd' ];

// job_attempts purge — prefer finished_at, then started_at, then created_at/ts
[$c3, $a3] = count_and_purge($db, 'job_attempts', ['finished_at','started_at','created_at','ts'], ago_days($ttlJobAttemptsDays), $dryRun);
$summary[] = [ 'table' => 'job_attempts', 'action' => $a3, 'count' => $c3, 'cutoff' => $ttlJobAttemptsDays . 'd' ];

// dead_letter_jobs purge
[$c4, $a4] = count_and_purge($db, 'dead_letter_jobs', ['created_at','ts'], ago_days($ttlDeadLetterDays), $dryRun);
$summary[] = [ 'table' => 'dead_letter_jobs', 'action' => $a4, 'count' => $c4, 'cutoff' => $ttlDeadLetterDays . 'd' ];

// Rotate synthetic monitor log if present
$logPath = __DIR__ . '/../../storage/logs/synthetic.log';
$rotateMsg = rotate_log($logPath, $rotateBytes, $dryRun);
$pruneMsg = prune_rotated_logs($logPath, max(1,$keepLogs), $dryRun);

echo "RETENTION SUMMARY\n";
echo json_encode([
    'dry_run' => $dryRun,
    'ttls_days' => [
        'hmac_replay' => $ttlReplayDays,
        'rate_limit' => $ttlRateLimitDays,
        'job_attempts' => $ttlJobAttemptsDays,
        'dead_letter_jobs' => $ttlDeadLetterDays,
    ],
    'tables' => $summary,
    'log_rotation' => $rotateMsg,
    'log_prune' => $pruneMsg,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

// Also write a one-line retention log for ops audit
try{
    $logDir = __DIR__ . '/../../storage/logs';
    if(!is_dir($logDir)) @mkdir($logDir, 0777, true);
    $logFile = $logDir . '/retention.log';
    $line = '['.gmdate('c').'] ' . json_encode([
        'dry_run' => $dryRun,
        'tables' => $summary,
        'rotate' => $rotateMsg,
        'prune' => $pruneMsg,
    ], JSON_UNESCAPED_SLASHES);
    @file_put_contents($logFile, $line."\n", FILE_APPEND);
}catch(Throwable $e){ /* ignore logging errors */ }

exit(0);
