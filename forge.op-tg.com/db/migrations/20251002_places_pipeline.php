<?php
// Idempotent migration for Google Places pipeline (lean mode)
if (!function_exists('db')) { require_once __DIR__ . '/../../config/db.php'; }
$pdo = db();
$pdo->exec('PRAGMA foreign_keys=ON;');

// Create places table if missing
$pdo->exec("CREATE TABLE IF NOT EXISTS places (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT,
  phone TEXT,
  address TEXT,
  country TEXT,
  city TEXT,
  lat REAL,
  lng REAL,
  place_id TEXT UNIQUE,
  website TEXT,
  types_json TEXT,
  source TEXT,
  source_url TEXT,
  raw_json TEXT,
  collected_at TEXT,
  last_seen_at TEXT,
  batch_id TEXT
);");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_places_place_id ON places(place_id);");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_places_name_phone_city ON places(name, phone, city);");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_places_batch_id ON places(batch_id);");

// Ensure new columns exist on internal_jobs: job_type, payload_json
try{
  $cols = $pdo->query("PRAGMA table_info(internal_jobs)")->fetchAll(PDO::FETCH_ASSOC);
  $has = function($n) use ($cols){ foreach($cols as $c){ if(($c['name']??$c['Name']??'')===$n) return true; } return false; };
  if(!$has('job_type')){ $pdo->exec("ALTER TABLE internal_jobs ADD COLUMN job_type TEXT"); }
  if(!$has('payload_json')){ $pdo->exec("ALTER TABLE internal_jobs ADD COLUMN payload_json TEXT"); }
}catch(Throwable $e){}
