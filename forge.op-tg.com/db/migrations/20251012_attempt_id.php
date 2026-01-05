<?php
// Attempt ID support: add attempt_id to internal_jobs and job_attempts, with indexes
if (!function_exists('db')) { require_once __DIR__ . '/../../config/db.php'; }
$pdo = db();
$pdo->exec('PRAGMA foreign_keys=ON;');

try {
  $cols = $pdo->query("PRAGMA table_info(internal_jobs)")->fetchAll(PDO::FETCH_ASSOC);
  $has = function($n) use ($cols){ foreach($cols as $c){ if(($c['name']??$c['Name']??'')===$n) return true; } return false; };
  if(!$has('attempt_id')){ $pdo->exec("ALTER TABLE internal_jobs ADD COLUMN attempt_id TEXT"); }
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_internal_jobs_attempt ON internal_jobs(attempt_id)");
} catch(Throwable $e){}

try {
  $cols = $pdo->query("PRAGMA table_info(job_attempts)")->fetchAll(PDO::FETCH_ASSOC);
  $has = function($n) use ($cols){ foreach($cols as $c){ if(($c['name']??$c['Name']??'')===$n) return true; } return false; };
  if(!$has('attempt_id')){ $pdo->exec("ALTER TABLE job_attempts ADD COLUMN attempt_id TEXT"); }
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_job_attempts_attempt ON job_attempts(attempt_id)");
} catch(Throwable $e){}
