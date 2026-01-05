<?php
// Idempotent hardening migration: workers telemetry, attempts log, defaults, indexes
if (!function_exists('db')) { require_once __DIR__ . '/../../config/db.php'; }
$pdo = db();
$pdo->exec('PRAGMA foreign_keys=ON;');

// Enhance internal_workers with telemetry columns
try {
  $cols = $pdo->query("PRAGMA table_info(internal_workers)")->fetchAll(PDO::FETCH_ASSOC);
  $has = function($n) use ($cols){ foreach($cols as $c){ if(($c['name']??$c['Name']??'')===$n) return true; } return false; };
  $alters = [];
  if(!$has('host'))          $alters[] = "ALTER TABLE internal_workers ADD COLUMN host TEXT";
  if(!$has('version'))       $alters[] = "ALTER TABLE internal_workers ADD COLUMN version TEXT";
  if(!$has('status'))        $alters[] = "ALTER TABLE internal_workers ADD COLUMN status TEXT";
  if(!$has('active_job_id')) $alters[] = "ALTER TABLE internal_workers ADD COLUMN active_job_id INTEGER";
  foreach($alters as $sql){ try{ $pdo->exec($sql); }catch(Throwable $e){} }
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_internal_workers_last_seen ON internal_workers(last_seen)");
} catch(Throwable $e) {}

// Ensure internal_jobs has helpful columns for retry/backoff
try {
  $cols = $pdo->query("PRAGMA table_info(internal_jobs)")->fetchAll(PDO::FETCH_ASSOC);
  $has = function($n) use ($cols){ foreach($cols as $c){ if(($c['name']??$c['Name']??'')===$n) return true; } return false; };
  $alters = [];
  if(!$has('max_attempts'))   $alters[] = "ALTER TABLE internal_jobs ADD COLUMN max_attempts INTEGER DEFAULT 5";
  if(!$has('next_retry_at'))  $alters[] = "ALTER TABLE internal_jobs ADD COLUMN next_retry_at TEXT"; // may already exist from prior
  if(!$has('last_error'))     $alters[] = "ALTER TABLE internal_jobs ADD COLUMN last_error TEXT";
  if(!$has('lease_expires_at')) $alters[] = "ALTER TABLE internal_jobs ADD COLUMN lease_expires_at TEXT"; // idempotent guard
  foreach($alters as $sql){ try{ $pdo->exec($sql); }catch(Throwable $e){} }
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_internal_jobs_status_updated ON internal_jobs(status, updated_at)");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_internal_jobs_lease ON internal_jobs(lease_expires_at)");
} catch(Throwable $e) {}

// Attempts log per job
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS job_attempts(
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    job_id INTEGER NOT NULL,
    worker_id TEXT,
    started_at TEXT NOT NULL,
    finished_at TEXT,
    success INTEGER NOT NULL DEFAULT 0,
    log_excerpt TEXT,
    FOREIGN KEY(job_id) REFERENCES internal_jobs(id) ON DELETE CASCADE
  )");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_job_attempts_job_id ON job_attempts(job_id)");
} catch(Throwable $e) {}

// Default settings for lease/backoff (idempotent insert-or-ignore)
$defs = [
  'LEASE_SEC_DEFAULT'   => '180',
  'BACKOFF_BASE_SEC'    => '30',
  'BACKOFF_MAX_SEC'     => '3600',
  'MAX_ATTEMPTS_DEFAULT'=> '5'
];
foreach($defs as $k=>$v){ try{ $pdo->prepare("INSERT OR IGNORE INTO settings(key,value) VALUES(?,?)")->execute([$k,$v]); }catch(Throwable $e){} }
