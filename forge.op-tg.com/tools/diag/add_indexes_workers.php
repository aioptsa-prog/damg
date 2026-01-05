<?php
// Adds helpful indexes for internal_workers to speed up admin/monitor pages on large fleets.
// Safe to run multiple times (IF NOT EXISTS). Works with SQLite.
// Usage: php tools/diag/add_indexes_workers.php

require_once __DIR__ . '/../../bootstrap.php';

$pdo = db();
$created = [];
$errors = [];

function mkidx(PDO $pdo, string $sql, string $name){
  global $created, $errors;
  try {
    $pdo->exec($sql);
    $created[] = $name;
  } catch (Throwable $e) {
    $errors[] = $name . ': ' . $e->getMessage();
  }
}

// Core indexes for admin/workers.php queries
mkidx($pdo, "CREATE INDEX IF NOT EXISTS idx_internal_workers_last_seen ON internal_workers(last_seen)", 'idx_internal_workers_last_seen');
mkidx($pdo, "CREATE INDEX IF NOT EXISTS idx_internal_workers_worker_id ON internal_workers(worker_id)", 'idx_internal_workers_worker_id');

// Optional health queries often used in dashboards
mkidx($pdo, "CREATE INDEX IF NOT EXISTS idx_internal_jobs_status_worker ON internal_jobs(status, worker_id)", 'idx_internal_jobs_status_worker');
mkidx($pdo, "CREATE INDEX IF NOT EXISTS idx_internal_jobs_lease_expires ON internal_jobs(lease_expires_at)", 'idx_internal_jobs_lease_expires');

header('Content-Type: text/plain; charset=utf-8');
echo "ok\n";
echo "created: " . implode(', ', $created) . "\n";
if (!empty($errors)){
  echo "errors: \n - " . implode("\n - ", $errors) . "\n";
}
