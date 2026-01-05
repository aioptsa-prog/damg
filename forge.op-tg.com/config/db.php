<?php
function ensure_storage(){ $dir=__DIR__.'/../storage'; if(!is_dir($dir)) @mkdir($dir,0777,true); @chmod($dir,0777); $log=$dir.'/logs'; if(!is_dir($log)) @mkdir($log,0777,true); @chmod($log,0777); }
function db(){ static $pdo=null; if($pdo) return $pdo; ensure_storage(); $env=require __DIR__.'/.env.php'; $p=$env['SQLITE_PATH']; if(!file_exists($p)){ @mkdir(dirname($p),0777,true); touch($p); @chmod($p,0666);} $pdo=new PDO('sqlite:'.$p); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC); try{ $pdo->exec('PRAGMA foreign_keys=ON;'); }catch(Throwable $e){} try{ $pdo->exec('PRAGMA journal_mode=WAL;'); }catch(Throwable $e){} try{ $pdo->exec('PRAGMA synchronous=NORMAL;'); }catch(Throwable $e){} try{ $pdo->exec('PRAGMA temp_store=MEMORY;'); }catch(Throwable $e){} try{ $pdo->exec('PRAGMA cache_size=-8000;'); }catch(Throwable $e){} return $pdo; }
function migrate(){
  $pdo=db();
  $pdo->exec("CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY AUTOINCREMENT,mobile TEXT UNIQUE NOT NULL,name TEXT NOT NULL,role TEXT NOT NULL CHECK(role IN ('admin','agent')),password_hash TEXT NOT NULL,active INTEGER NOT NULL DEFAULT 1,washeej_token TEXT,washeej_sender TEXT,whatsapp_message TEXT,created_at TEXT NOT NULL);");
  $pdo->exec("CREATE TABLE IF NOT EXISTS sessions(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,token_hash TEXT NOT NULL,expires_at TEXT NOT NULL,created_at TEXT NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE);");
  $pdo->exec("CREATE TABLE IF NOT EXISTS leads(id INTEGER PRIMARY KEY AUTOINCREMENT,phone TEXT NOT NULL UNIQUE,name TEXT,city TEXT,country TEXT,created_at TEXT NOT NULL,source TEXT,created_by_user_id INTEGER,FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE SET NULL);");
  $pdo->exec("CREATE TABLE IF NOT EXISTS assignments(id INTEGER PRIMARY KEY AUTOINCREMENT,lead_id INTEGER NOT NULL UNIQUE,agent_id INTEGER NOT NULL,status TEXT NOT NULL DEFAULT 'new',assigned_at TEXT NOT NULL,FOREIGN KEY(lead_id) REFERENCES leads(id) ON DELETE CASCADE,FOREIGN KEY(agent_id) REFERENCES users(id) ON DELETE CASCADE);");
  $pdo->exec("CREATE TABLE IF NOT EXISTS settings(key TEXT PRIMARY KEY,value TEXT);");
  $pdo->exec("CREATE TABLE IF NOT EXISTS washeej_logs(id INTEGER PRIMARY KEY AUTOINCREMENT,lead_id INTEGER,agent_id INTEGER,sent_by_user_id INTEGER,http_code INTEGER,response TEXT,created_at TEXT NOT NULL,FOREIGN KEY(lead_id) REFERENCES leads(id) ON DELETE SET NULL,FOREIGN KEY(agent_id) REFERENCES users(id) ON DELETE SET NULL,FOREIGN KEY(sent_by_user_id) REFERENCES users(id) ON DELETE SET NULL);");
  $pdo->exec("CREATE TABLE IF NOT EXISTS place_cache(id INTEGER PRIMARY KEY AUTOINCREMENT,provider TEXT NOT NULL,external_id TEXT NOT NULL,phone TEXT,name TEXT,city TEXT,country TEXT,updated_at TEXT NOT NULL,UNIQUE(provider,external_id));");
  $pdo->exec("CREATE TABLE IF NOT EXISTS search_tiles(tile_key TEXT PRIMARY KEY,q TEXT,ll TEXT,radius_km INTEGER,provider_order TEXT,preview_count INTEGER DEFAULT 0,leads_added INTEGER DEFAULT 0,updated_at TEXT NOT NULL);");
  $pdo->exec("CREATE TABLE IF NOT EXISTS usage_counters(id INTEGER PRIMARY KEY AUTOINCREMENT,day TEXT NOT NULL,kind TEXT NOT NULL,count INTEGER NOT NULL DEFAULT 0,UNIQUE(day,kind));");
  /* internal jobs (v3.11) */
  $pdo->exec("CREATE TABLE IF NOT EXISTS internal_jobs(id INTEGER PRIMARY KEY AUTOINCREMENT, requested_by_user_id INTEGER NOT NULL, role TEXT NOT NULL, agent_id INTEGER, query TEXT NOT NULL, ll TEXT NOT NULL, radius_km INTEGER NOT NULL, lang TEXT NOT NULL, region TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'queued', worker_id TEXT, claimed_at TEXT, finished_at TEXT, result_count INTEGER DEFAULT 0, error TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, FOREIGN KEY(requested_by_user_id) REFERENCES users(id) ON DELETE SET NULL, FOREIGN KEY(agent_id) REFERENCES users(id) ON DELETE SET NULL);");
    // Internal dispatch strategy setting will be seeded below in $defs
  // Resilient processing columns (idempotent): attempts, lease/cursor/progress, retry/error meta
  try{
    $cols = $pdo->query("PRAGMA table_info(internal_jobs)")->fetchAll(PDO::FETCH_ASSOC);
    $has = function($name) use ($cols){ foreach($cols as $c){ if(($c['name']??$c['Name']??'')===$name) return true; } return false; };
    $alters = [];
    if(!$has('attempts'))          $alters[] = "ALTER TABLE internal_jobs ADD COLUMN attempts INTEGER DEFAULT 0";
    if(!$has('lease_expires_at'))  $alters[] = "ALTER TABLE internal_jobs ADD COLUMN lease_expires_at TEXT";
    if(!$has('last_cursor'))       $alters[] = "ALTER TABLE internal_jobs ADD COLUMN last_cursor INTEGER DEFAULT 0";
    if(!$has('progress_count'))    $alters[] = "ALTER TABLE internal_jobs ADD COLUMN progress_count INTEGER DEFAULT 0";
    if(!$has('last_progress_at'))  $alters[] = "ALTER TABLE internal_jobs ADD COLUMN last_progress_at TEXT";
    if(!$has('next_retry_at'))     $alters[] = "ALTER TABLE internal_jobs ADD COLUMN next_retry_at TEXT";
    if(!$has('last_error'))        $alters[] = "ALTER TABLE internal_jobs ADD COLUMN last_error TEXT";
      if(!$has('target_count'))      $alters[] = "ALTER TABLE internal_jobs ADD COLUMN target_count INTEGER";
      if(!$has('done_reason'))       $alters[] = "ALTER TABLE internal_jobs ADD COLUMN done_reason TEXT";
      // Optional controls used by dispatcher
      if(!$has('max_attempts'))      $alters[] = "ALTER TABLE internal_jobs ADD COLUMN max_attempts INTEGER";
      if(!$has('priority'))          $alters[] = "ALTER TABLE internal_jobs ADD COLUMN priority INTEGER";
      if(!$has('locked_until'))      $alters[] = "ALTER TABLE internal_jobs ADD COLUMN locked_until TEXT";
      if(!$has('queued_at'))         $alters[] = "ALTER TABLE internal_jobs ADD COLUMN queued_at TEXT";
      if(!$has('attempt_id'))        $alters[] = "ALTER TABLE internal_jobs ADD COLUMN attempt_id TEXT";
    foreach($alters as $sql){ try{ $pdo->exec($sql); }catch(Throwable $e){} }
  }catch(Throwable $e){}
  // Removed default admin seeding for production safety. Use tools/seed_dev.php in development environments if needed.
  // Idempotent: add optional admin columns (username, is_superadmin)
  try{
    $colsU = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
    $hasU = function($name) use ($colsU){ foreach($colsU as $c){ if(($c['name']??$c['Name']??'')===$name) return true; } return false; };
    if(!$hasU('username')){ $pdo->exec("ALTER TABLE users ADD COLUMN username TEXT"); }
    if(!$hasU('is_superadmin')){ $pdo->exec("ALTER TABLE users ADD COLUMN is_superadmin INTEGER NOT NULL DEFAULT 0"); }
  }catch(Throwable $e){}
  // Removed forced superadmin seeding. Rely on explicit ops seeding in non-production only.
  // Unique index for username (allows multiple NULLs in SQLite)
  $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_users_username ON users(username);");

  // Lightweight login attempts table for basic rate limiting (per IP/key)
  $pdo->exec("CREATE TABLE IF NOT EXISTS auth_attempts(id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT NOT NULL, key TEXT, created_at TEXT NOT NULL);");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_auth_attempts_key_time ON auth_attempts(key, created_at);");
  // New: application-wide rate limiting windows (UPSERT-friendly)
  try{
    // If table doesn't exist, create with correct composite PK (ip, "key", window_start)
    $pdo->exec("CREATE TABLE IF NOT EXISTS rate_limit (
      ip TEXT NOT NULL,
      \"key\" TEXT NOT NULL,
      window_start INTEGER NOT NULL,
      count INTEGER NOT NULL DEFAULT 0,
      PRIMARY KEY (ip, \"key\", window_start)
    )");
    // Optional support index for recent-window analytics
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_rate_limit_recent ON rate_limit(window_start)");

    // Detect legacy schema (missing composite PK on window_start) and migrate in-place
    $cols = $pdo->query("PRAGMA table_info(rate_limit)")->fetchAll(PDO::FETCH_ASSOC);
    $pkCols = [];
    foreach($cols as $c){ if(((int)($c['pk'] ?? 0))>0){ $pkCols[] = $c['name']; } }
    sort($pkCols);
    $expected = ['ip','key','window_start']; sort($expected);
    $needsRebuild = (count($pkCols) !== 3) || ($pkCols !== $expected);
    if($needsRebuild){
      // Create new table with correct schema
      $pdo->exec("CREATE TABLE IF NOT EXISTS rate_limit_new (
        ip TEXT NOT NULL,
        \"key\" TEXT NOT NULL,
        window_start INTEGER NOT NULL,
        count INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (ip, \"key\", window_start)
      )");
      // Aggregate and copy if old table exists with similar columns
      try{
        $pdo->exec("INSERT INTO rate_limit_new(ip,\"key\",window_start,count)
          SELECT ip, \"key\", window_start, SUM(count) FROM rate_limit GROUP BY ip, \"key\", window_start");
      }catch(Throwable $e){}
      // Swap tables
      $pdo->exec("DROP TABLE rate_limit");
      $pdo->exec("ALTER TABLE rate_limit_new RENAME TO rate_limit");
      $pdo->exec("CREATE INDEX IF NOT EXISTS idx_rate_limit_recent ON rate_limit(window_start)");
    }
  }catch(Throwable $e){}
    // Lead extra fields (rating/website/email/types/source_url/social/category)
    try{
      $colsL = $pdo->query("PRAGMA table_info(leads)")->fetchAll(PDO::FETCH_ASSOC);
      $hasL = function($name) use ($colsL){ foreach($colsL as $c){ if(($c['name']??$c['Name']??'')===$name) return true; } return false; };
      $altersL = [];
      if(!$hasL('rating'))       $altersL[] = "ALTER TABLE leads ADD COLUMN rating REAL";
      if(!$hasL('website'))      $altersL[] = "ALTER TABLE leads ADD COLUMN website TEXT";
      if(!$hasL('email'))        $altersL[] = "ALTER TABLE leads ADD COLUMN email TEXT";
      if(!$hasL('gmap_types'))   $altersL[] = "ALTER TABLE leads ADD COLUMN gmap_types TEXT";
      if(!$hasL('source_url'))   $altersL[] = "ALTER TABLE leads ADD COLUMN source_url TEXT";
      if(!$hasL('social'))       $altersL[] = "ALTER TABLE leads ADD COLUMN social TEXT";
  if(!$hasL('phone_norm'))   $altersL[] = "ALTER TABLE leads ADD COLUMN phone_norm TEXT";
      if(!$hasL('category_id'))  $altersL[] = "ALTER TABLE leads ADD COLUMN category_id INTEGER REFERENCES categories(id)";
      // Coordinates
      if(!$hasL('lat'))          $altersL[] = "ALTER TABLE leads ADD COLUMN lat REAL";
      if(!$hasL('lon'))          $altersL[] = "ALTER TABLE leads ADD COLUMN lon REAL";
      // Geo classification outputs
      if(!$hasL('geo_country'))     $altersL[] = "ALTER TABLE leads ADD COLUMN geo_country TEXT";
      if(!$hasL('geo_region_code')) $altersL[] = "ALTER TABLE leads ADD COLUMN geo_region_code TEXT";
      if(!$hasL('geo_city_id'))     $altersL[] = "ALTER TABLE leads ADD COLUMN geo_city_id INTEGER";
      if(!$hasL('geo_district_id')) $altersL[] = "ALTER TABLE leads ADD COLUMN geo_district_id INTEGER";
      if(!$hasL('geo_confidence'))  $altersL[] = "ALTER TABLE leads ADD COLUMN geo_confidence REAL";
      foreach($altersL as $sql){ try{ $pdo->exec($sql); }catch(Throwable $e){} }
      // Helpful indexes
      try{ $pdo->exec("CREATE INDEX IF NOT EXISTS idx_leads_geo_city ON leads(geo_city_id)"); }catch(Throwable $e){}
      try{ $pdo->exec("CREATE INDEX IF NOT EXISTS idx_leads_geo_district ON leads(geo_district_id)"); }catch(Throwable $e){}
      try{ $pdo->exec("CREATE INDEX IF NOT EXISTS idx_leads_geo_region ON leads(geo_region_code)"); }catch(Throwable $e){}
  try{ $pdo->exec("CREATE INDEX IF NOT EXISTS idx_leads_phone_norm ON leads(phone_norm)"); }catch(Throwable $e){}
    }catch(Throwable $e){}

    // Categories/taxonomy
    // Categories: hierarchical with optional metadata
    $pdo->exec("CREATE TABLE IF NOT EXISTS categories(
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      parent_id INTEGER,
      name TEXT NOT NULL,
      slug TEXT,
      depth INTEGER NOT NULL DEFAULT 0,
      path TEXT,
      is_active INTEGER NOT NULL DEFAULT 1,
      icon_type TEXT,
      icon_value TEXT,
      created_by_user_id INTEGER,
      created_at TEXT NOT NULL,
      updated_at TEXT,
      FOREIGN KEY(parent_id) REFERENCES categories(id) ON DELETE SET NULL
    );");
    // Ensure new columns exist on older installs
    try{
      $colsC = $pdo->query("PRAGMA table_info(categories)")->fetchAll(PDO::FETCH_ASSOC);
      $hasC = function($name) use ($colsC){ foreach($colsC as $c){ if(($c['name']??$c['Name']??'')===$name) return true; } return false; };
      if(!$hasC('slug'))      { $pdo->exec("ALTER TABLE categories ADD COLUMN slug TEXT"); }
      if(!$hasC('depth'))     { $pdo->exec("ALTER TABLE categories ADD COLUMN depth INTEGER NOT NULL DEFAULT 0"); }
      if(!$hasC('path'))      { $pdo->exec("ALTER TABLE categories ADD COLUMN path TEXT"); }
      if(!$hasC('is_active')) { $pdo->exec("ALTER TABLE categories ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1"); }
      if(!$hasC('updated_at')){ $pdo->exec("ALTER TABLE categories ADD COLUMN updated_at TEXT"); }
      if(!$hasC('icon_type')) { $pdo->exec("ALTER TABLE categories ADD COLUMN icon_type TEXT"); }
      if(!$hasC('icon_value')){ $pdo->exec("ALTER TABLE categories ADD COLUMN icon_value TEXT"); }
      if(!$hasC('created_by_user_id')){ $pdo->exec("ALTER TABLE categories ADD COLUMN created_by_user_id INTEGER"); }
    }catch(Throwable $e){}
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_categories_slug ON categories(slug)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_categories_parent ON categories(parent_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_categories_active_parent ON categories(is_active, parent_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_categories_active ON categories(is_active)");
    // Category keywords
    $pdo->exec("CREATE TABLE IF NOT EXISTS category_keywords(id INTEGER PRIMARY KEY AUTOINCREMENT, category_id INTEGER NOT NULL, keyword TEXT NOT NULL, lang TEXT DEFAULT 'ar', created_at TEXT NOT NULL, FOREIGN KEY(category_id) REFERENCES categories(id) ON DELETE CASCADE);");
    // Ensure new columns exist on older installs
    try{
      $colsK = $pdo->query("PRAGMA table_info(category_keywords)")->fetchAll(PDO::FETCH_ASSOC);
      $hasK = function($name) use ($colsK){ foreach($colsK as $c){ if(($c['name']??$c['Name']??'')===$name) return true; } return false; };
      if(!$hasK('lang')){ $pdo->exec("ALTER TABLE category_keywords ADD COLUMN lang TEXT DEFAULT 'ar'"); }
    }catch(Throwable $e){}
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_category_keywords_cat ON category_keywords(category_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_category_keywords_lang ON category_keywords(lang)");
    try{ $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_category_keywords_unique ON category_keywords(category_id, keyword, lang)"); }catch(Throwable $e){}
    // Optional: category query templates
    $pdo->exec("CREATE TABLE IF NOT EXISTS category_query_templates(
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      category_id INTEGER NOT NULL REFERENCES categories(id) ON DELETE CASCADE,
      template TEXT NOT NULL,
      created_at TEXT NOT NULL DEFAULT (datetime('now')),
      UNIQUE(category_id, template)
    );");
    // Advanced classification rules (weights, regex, targets)
    $pdo->exec("CREATE TABLE IF NOT EXISTS category_rules(
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      category_id INTEGER NOT NULL,
      target TEXT NOT NULL, -- name, types, website, email, source_url, city, country, phone
      pattern TEXT NOT NULL,
      match_mode TEXT NOT NULL DEFAULT 'contains', -- contains | exact | regex
      weight REAL NOT NULL DEFAULT 1.0,
      note TEXT,
      enabled INTEGER NOT NULL DEFAULT 1,
      created_at TEXT NOT NULL,
      FOREIGN KEY(category_id) REFERENCES categories(id) ON DELETE CASCADE
    );");
    // Ensure new columns exist on older installs
    try{
      $colsR = $pdo->query("PRAGMA table_info(category_rules)")->fetchAll(PDO::FETCH_ASSOC);
      $hasR = function($name) use ($colsR){ foreach($colsR as $c){ if(($c['name']??$c['Name']??'')===$name) return true; } return false; };
      if(!$hasR('enabled')){ $pdo->exec("ALTER TABLE category_rules ADD COLUMN enabled INTEGER NOT NULL DEFAULT 1"); }
    }catch(Throwable $e){}
  // Indexes for speed
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_leads_category_id ON leads(category_id);");
  // Helpful compound index for vault/export filtering by category and date
  try{ $pdo->exec("CREATE INDEX IF NOT EXISTS idx_leads_category_created ON leads(category_id, created_at DESC)"); }catch(Throwable $e){}
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_category_rules_cat_target ON category_rules(category_id, target);");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_category_rules_enabled ON category_rules(enabled);");
  // moved above; retained for idempotency
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_category_keywords_cat ON category_keywords(category_id);");
  // Jobs index by category and status if category_id exists
  try{
    $colsIJ = $pdo->query("PRAGMA table_info(internal_jobs)")->fetchAll(PDO::FETCH_ASSOC);
    $hasIJ = function($name) use ($colsIJ){ foreach($colsIJ as $c){ if(($c['name']??$c['Name']??'')===$name) return true; } return false; };
    if(!$hasIJ('category_id')){ $pdo->exec("ALTER TABLE internal_jobs ADD COLUMN category_id INTEGER REFERENCES categories(id)"); }
  }catch(Throwable $e){}
  try{ $pdo->exec("CREATE INDEX IF NOT EXISTS idx_jobs_category_status ON internal_jobs(category_id, status)"); }catch(Throwable $e){}
  // Multi-location support: job_groups and job_group_id on jobs/leads (idempotent)
  try{
    // job_groups table
    $pdo->exec("CREATE TABLE IF NOT EXISTS job_groups(
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      created_by_user_id INTEGER NOT NULL,
      category_id INTEGER,
      base_query TEXT,
      note TEXT,
      created_at TEXT NOT NULL,
      FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
      FOREIGN KEY(category_id) REFERENCES categories(id) ON DELETE SET NULL
    );");
    // Helpful index for recent groups
    try{ $pdo->exec("CREATE INDEX IF NOT EXISTS idx_job_groups_created ON job_groups(created_at)"); }catch(Throwable $e){}
    // Add columns to internal_jobs if missing
    $colsIJ2 = $pdo->query("PRAGMA table_info(internal_jobs)")->fetchAll(PDO::FETCH_ASSOC);
    $hasIJ2 = function($name) use ($colsIJ2){ foreach($colsIJ2 as $c){ if(($c['name']??$c['Name']??'')===$name) return true; } return false; };
    if(!$hasIJ2('job_group_id')){ $pdo->exec("ALTER TABLE internal_jobs ADD COLUMN job_group_id INTEGER REFERENCES job_groups(id)"); }
    if(!$hasIJ2('city_hint')){ $pdo->exec("ALTER TABLE internal_jobs ADD COLUMN city_hint TEXT"); }
    try{ $pdo->exec("CREATE INDEX IF NOT EXISTS idx_internal_jobs_group ON internal_jobs(job_group_id)"); }catch(Throwable $e){}
    // Leads optional linkage to group
    $colsL2 = $pdo->query("PRAGMA table_info(leads)")->fetchAll(PDO::FETCH_ASSOC);
    $hasL2 = function($name) use ($colsL2){ foreach($colsL2 as $c){ if(($c['name']??$c['Name']??'')===$name) return true; } return false; };
    if(!$hasL2('job_group_id')){ $pdo->exec("ALTER TABLE leads ADD COLUMN job_group_id INTEGER REFERENCES job_groups(id)"); }
    try{ $pdo->exec("CREATE INDEX IF NOT EXISTS idx_leads_job_group ON leads(job_group_id)"); }catch(Throwable $e){}
  }catch(Throwable $e){}
  // Fingerprints (optional dedup/metrics)
  $pdo->exec("CREATE TABLE IF NOT EXISTS leads_fingerprints(id INTEGER PRIMARY KEY AUTOINCREMENT, lead_id INTEGER NOT NULL, fingerprint TEXT NOT NULL, created_at TEXT NOT NULL, UNIQUE(fingerprint), FOREIGN KEY(lead_id) REFERENCES leads(id) ON DELETE CASCADE);");
  // Category activity log
  $pdo->exec("CREATE TABLE IF NOT EXISTS category_activity_log(id INTEGER PRIMARY KEY AUTOINCREMENT, action TEXT NOT NULL, category_id INTEGER, user_id INTEGER, details TEXT, created_at TEXT NOT NULL DEFAULT (datetime('now')), FOREIGN KEY(category_id) REFERENCES categories(id) ON DELETE SET NULL);");
  try{ $pdo->exec("CREATE INDEX IF NOT EXISTS idx_category_activity_user ON category_activity_log(user_id, created_at DESC)"); }catch(Throwable $e){}
  // Dead letter queue for final failed jobs
  $pdo->exec("CREATE TABLE IF NOT EXISTS dead_letter_jobs(id INTEGER PRIMARY KEY AUTOINCREMENT, job_id INTEGER NOT NULL, worker_id TEXT, reason TEXT, payload TEXT, created_at TEXT NOT NULL, FOREIGN KEY(job_id) REFERENCES internal_jobs(id) ON DELETE CASCADE);");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_dlq_created ON dead_letter_jobs(created_at);");
  // Admin audit logs
  $pdo->exec("CREATE TABLE IF NOT EXISTS audit_logs(id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT NOT NULL, target TEXT, payload TEXT, created_at TEXT NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL);");
  // Ops alert events log
  $pdo->exec("CREATE TABLE IF NOT EXISTS alert_events(id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT NOT NULL, message TEXT, payload TEXT, created_at TEXT NOT NULL);");
  try{ $pdo->exec("CREATE INDEX IF NOT EXISTS idx_alert_events_kind_time ON alert_events(kind, created_at)"); }catch(Throwable $e){}
  // Internal jobs helpful indexes
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_internal_jobs_status_lease ON internal_jobs(status, lease_expires_at);");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_internal_jobs_status_updated ON internal_jobs(status, updated_at);");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_internal_jobs_status_created ON internal_jobs(status, created_at);");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_internal_jobs_status_retry ON internal_jobs(status, next_retry_at);");
  // New composite index for retry path including priority
  try{ $pdo->exec("CREATE INDEX IF NOT EXISTS idx_internal_jobs_status_retry2 ON internal_jobs(status, next_retry_at, priority)"); }catch(Throwable $e){}
  // Helpful queue ordering index for faster claim
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_internal_jobs_queue ON internal_jobs(status, queued_at, priority);");
  // Worker lookups by worker_id/time
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_internal_jobs_worker_updated ON internal_jobs(worker_id, updated_at);");
    // Internal workers registry (create before related indexes)
    $pdo->exec("CREATE TABLE IF NOT EXISTS internal_workers(id INTEGER PRIMARY KEY AUTOINCREMENT, worker_id TEXT UNIQUE NOT NULL, last_seen TEXT NOT NULL, info TEXT);");
      // Optional telemetry columns (add if missing)
      try{
        $colsIW = $pdo->query("PRAGMA table_info(internal_workers)")->fetchAll(PDO::FETCH_ASSOC);
        $hasIW = function($name) use ($colsIW){ foreach($colsIW as $c){ if(($c['name']??$c['Name']??'')===$name) return true; } return false; };
        if(!$hasIW('host'))         { $pdo->exec("ALTER TABLE internal_workers ADD COLUMN host TEXT"); }
        if(!$hasIW('version'))      { $pdo->exec("ALTER TABLE internal_workers ADD COLUMN version TEXT"); }
        if(!$hasIW('status'))       { $pdo->exec("ALTER TABLE internal_workers ADD COLUMN status TEXT"); }
        if(!$hasIW('active_job_id')){ $pdo->exec("ALTER TABLE internal_workers ADD COLUMN active_job_id INTEGER"); }
        // Per-worker auth and basic rate limiting
        if(!$hasIW('secret'))       { $pdo->exec("ALTER TABLE internal_workers ADD COLUMN secret TEXT"); }
        if(!$hasIW('rotating_to'))  { $pdo->exec("ALTER TABLE internal_workers ADD COLUMN rotating_to TEXT"); }
        if(!$hasIW('rotated_at'))   { $pdo->exec("ALTER TABLE internal_workers ADD COLUMN rotated_at TEXT"); }
        if(!$hasIW('rate_limit_per_min')){ $pdo->exec("ALTER TABLE internal_workers ADD COLUMN rate_limit_per_min INTEGER"); }
      }catch(Throwable $e){}

  // Workers last_seen for quick health checks
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_internal_workers_last_seen ON internal_workers(last_seen);");

      // Job attempt logs (for diagnostics and backoff analysis)
      $pdo->exec("CREATE TABLE IF NOT EXISTS job_attempts(id INTEGER PRIMARY KEY AUTOINCREMENT, job_id INTEGER NOT NULL, worker_id TEXT, started_at TEXT NOT NULL, finished_at TEXT NOT NULL, success INTEGER NOT NULL, log_excerpt TEXT, FOREIGN KEY(job_id) REFERENCES internal_jobs(id) ON DELETE CASCADE);");
      $pdo->exec("CREATE INDEX IF NOT EXISTS idx_job_attempts_job ON job_attempts(job_id);");
  // Idempotency keys per job to avoid double processing
  $pdo->exec("CREATE TABLE IF NOT EXISTS idempotency_keys(id INTEGER PRIMARY KEY AUTOINCREMENT, job_id INTEGER NOT NULL, ikey TEXT NOT NULL, created_at TEXT NOT NULL, UNIQUE(job_id, ikey), FOREIGN KEY(job_id) REFERENCES internal_jobs(id) ON DELETE CASCADE);");
      // Replay guard table indexes
      try{ $pdo->exec("CREATE INDEX IF NOT EXISTS idx_hmac_replay_tuple ON hmac_replay(worker_id, ts)"); }catch(Throwable $e){}
    $defs=[
  'google_api_key'=>'','default_ll'=>'24.638916,46.716010','default_radius_km'=>'25','default_language'=>'ar','default_region'=>'sa',
      'whatsapp_message'=>'مرحبًا {name}، راسلنا بخصوص خدمتك.',
      'washeej_url'=>'https://wa.washeej.com/api/qr/rest/send_message','washeej_token'=>'','washeej_sender'=>'','washeej_use_per_agent'=>'0','washeej_instance_id'=>'',
  'provider_order'=>'osm,foursquare,mapbox,radar,google','foursquare_api_key'=>'','mapbox_api_key'=>'','radar_api_key'=>'',
  // Wider defaults to reduce artificial stopping
  'tile_ttl_days'=>'14','daily_details_cap'=>'1000000','overpass_limit'=>'1000',
  // Exhaustive scan defaults
  'fetch_exhaustive'=>'1','exhaustive_grid_step_km'=>'2','exhaustive_max_points'=>'1000',
      'internal_server_enabled'=>'0','internal_secret'=>'','worker_pull_interval_sec'=>'30',
  'worker_headless'=>'1','worker_until_end'=>'1','worker_max_pages'=>'5','worker_lease_sec'=>'180','worker_report_batch_size'=>'10','worker_report_every_ms'=>'15000','worker_item_delay_ms'=>'800',
    // Retry/backoff and attempts defaults
    'BACKOFF_BASE_SEC' => '30',
    'BACKOFF_MAX_SEC'  => '3600',
    'MAX_ATTEMPTS_DEFAULT' => '5',
    // Worker online health window (sec) used for requeueing stuck jobs
    'workers_online_window_sec' => '90',
    // Health thresholds
    'stuck_processing_threshold_min'=>'10',
  // Worker external config
  'worker_base_url'=>'',
  'worker_config_code'=>'',
  'brand_name'=>'OptForge', 'brand_tagline_ar'=>'منصة استخراج ومعالجة البيانات — OptForge', 'brand_tagline_en'=>'OptForge — Data scraping and operations automation',
      // Internal dispatch strategy
      'job_pick_order' => 'fifo', // fifo | newest | random
      // System control settings
      'system_pause_enabled'=>'0',
      'system_pause_start'=>'23:59',
      'system_pause_end'=>'09:00',
      'system_global_stop'=>'0',
      // Classification settings
      'classify_enabled'=>'1',
      'classify_threshold'=>'1.0',
  // Classification weight tuning (defaults preserve current behavior)
  'classify_w_kw_name'=>'2.0',
  'classify_w_kw_types'=>'1.5',
  'classify_w_name'=>'1.0',
  'classify_w_types'=>'1.0',
  'classify_w_website'=>'1.0',
  'classify_w_email'=>'1.0',
  'classify_w_source_url'=>'1.0',
  'classify_w_city'=>'1.0',
  'classify_w_country'=>'1.0',
  'classify_w_phone'=>'1.0',
      // Maintenance/scheduler settings
      'maintenance_secret'=>'',
      'reclassify_default_limit'=>'200',
      'reclassify_only_empty'=>'1',
      'reclassify_override'=>'0',
      // Self-update gate (production-only)
      'enable_self_update'=>'0'
    ];
  // Control visibility of EXE installer option on Worker Setup page (default hidden)
  $defs['worker_exe_visible'] = '0';
  // Optional: require per-worker secret header if secret exists
  $defs['per_worker_secret_required'] = '0';
  // Default update channel for workers
  $defs['worker_update_channel'] = 'stable';
  // Per-worker update channel overrides (JSON object: {"workerId":"canary"|"stable"|"beta"})
  $defs['worker_channel_overrides_json'] = '{}';
  // Per-worker friendly names (JSON object: {"workerId":"اسم وصفي"})
  $defs['worker_name_overrides_json'] = '{}';
  // Per-worker config overrides (JSON object: {"workerId": { pull_interval_sec, headless, until_end, max_pages, lease_sec, report_*_ms, item_delay_ms, chrome_exe, chrome_args, update_channel }})
  $defs['worker_config_overrides_json'] = '{}';
  // Per-worker commands queue (JSON object: {"workerId": {"command":"pause|resume|restart|update-now|arm|disarm|heartbeat-now|sync-config", "rev": 123 }})
  $defs['worker_commands_json'] = '{}';
  // Circuit breaker: list of worker IDs under CB (JSON array)
  $defs['cb_open_workers_json'] = '[]';
  // Alerting endpoints (webhook/email)
  $defs['alert_webhook_url'] = '';
  $defs['alert_email'] = '';
  // Optional cap for exports
  $defs['export_max_rows'] = '50000';
  // Release canary rollout and SLO defaults
  $defs['rollout_canary_percent'] = '0';
  $defs['latest_previous_version'] = '';
  $defs['latest_previous_channel'] = '';
  $defs['slo_api_latency_ms_p95_internal'] = '300';
  $defs['slo_api_latency_ms_p95_public'] = '600';
  $defs['slo_worker_heartbeat_success'] = '99.9';
  $defs['slo_job_lease_stuck_rate'] = '0.5';
  // Security hardening toggles
  $defs['csp_phase1_enforced'] = '0';
  // Retention defaults (overridable by settings)
  $defs['ttl_hmac_replay_days'] = '7';
  $defs['ttl_rate_limit_days'] = '2';
  $defs['ttl_job_attempts_days'] = '90';
  $defs['ttl_dead_letter_jobs_days'] = '90';
  $defs['synthetic_log_keep'] = '5';
  $defs['ret_rotate_bytes'] = (string)(10 * 1024 * 1024);
  // Rate limit settings
  $defs['rate_limit_category_search_per_min'] = '30';
  $defs['rate_limit_admin_multiplier'] = '2';
  $defs['trusted_proxy_ips'] = '';
  // Multi-location defaults
  $defs['MAX_MULTI_LOCATIONS'] = '10';
  foreach($defs as $k=>$v){ $pdo->prepare("INSERT OR IGNORE INTO settings(key,value) VALUES(?,?)")->execute([$k,$v]); }
  // Seed default categories if none exist
  try{
    $countC = (int)$pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
    if($countC===0){
      // uncategorized legacy
      $pdo->prepare("INSERT INTO categories(name, slug, parent_id, depth, path, is_active, created_at, updated_at) VALUES(?,?,?,?,?,?,datetime('now'),datetime('now'))")
          ->execute(['غير مصنّف (Legacy)','uncategorized', null, 0, 'غير مصنّف (Legacy)', 1]);
      $rootId = (int)$pdo->lastInsertId();
      // medical root
      $pdo->prepare("INSERT INTO categories(name, slug, parent_id, depth, path, is_active, created_at, updated_at) VALUES(?,?,?,?,?,?,datetime('now'),datetime('now'))")
          ->execute(['طبي','medical', null, 0, 'طبي', 1]);
      $medicalId = (int)$pdo->lastInsertId();
      // beauty under medical
      $pdo->prepare("INSERT INTO categories(name, slug, parent_id, depth, path, is_active, created_at, updated_at) VALUES(?,?,?,?,?,?,datetime('now'),datetime('now'))")
          ->execute(['تجميل','beauty', $medicalId, 1, 'طبي > تجميل', 1]);
      $beautyId = (int)$pdo->lastInsertId();
      // dental clinics under medical
      $pdo->prepare("INSERT INTO categories(name, slug, parent_id, depth, path, is_active, created_at, updated_at) VALUES(?,?,?,?,?,?,datetime('now'),datetime('now'))")
          ->execute(['عيادات أسنان','dental-clinics', $medicalId, 1, 'طبي > عيادات أسنان', 1]);
      $dentalId = (int)$pdo->lastInsertId();
      // Keywords
      $insKw = $pdo->prepare("INSERT OR IGNORE INTO category_keywords(category_id, keyword, created_at) VALUES(?,?,datetime('now'))");
      foreach(['عيادة أسنان','مركز أسنان','طبيب أسنان','dental clinic','dentist'] as $kw){ $insKw->execute([$dentalId, $kw]); }
      foreach(['مركز تجميل','عيادة تجميل','beauty center','cosmetic clinic'] as $kw){ $insKw->execute([$beautyId, $kw]); }
      foreach(['مستشفى','عيادة','مركز طبي','hospital','medical center'] as $kw){ $insKw->execute([$medicalId, $kw]); }
    }
  }catch(Throwable $e){}
  // Production-like defaults moved to ops script. Use tools/deploy_apply_settings.php to set environment-specific values.
  // Apply additional migrations if present (idempotent)
  try{
    $mig = __DIR__ . '/../db/migrations/20251002_hardening.php';
    if(is_file($mig)) require_once $mig;
    $mig2 = __DIR__ . '/../db/migrations/20251002_places_pipeline.php';
    if(is_file($mig2)) require_once $mig2;
    $mig3 = __DIR__ . '/../db/migrations/20251012_attempt_id.php';
    if(is_file($mig3)) require_once $mig3;
    // Ensure composite index for places(batch_id, collected_at)
    try{ $pdo->exec("CREATE INDEX IF NOT EXISTS idx_places_batch_collected ON places(batch_id, collected_at)"); }catch(Throwable $e){}
  }catch(Throwable $e){}

  // Cleanup: numbers-only table (best-effort, safe/no-op if not present)
  try{
    $candidates = ['numbers','phones','phone_numbers'];
    $existing = [];
    // Detect existing tables (SQLite)
    try {
      $rs = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'");
      while($row = $rs->fetch(PDO::FETCH_ASSOC)){
        $n = $row['name'] ?? '';
        if(in_array($n, $candidates, true)) $existing[] = $n;
      }
    } catch(Throwable $e){ /* ignore */ }
    $target = $existing[0] ?? null;
    if($target){
      // Best-effort delete; TRUNCATE not supported in SQLite
      try{ $pdo->exec("DELETE FROM ".$target); }catch(Throwable $e){}
      try{ $pdo->exec("VACUUM"); }catch(Throwable $e){}
    }
  }catch(Throwable $e){}
}
migrate();

// Create replay guard table for HMAC requests (idempotent)
try{
  $pdo = db();
  $pdo->exec("CREATE TABLE IF NOT EXISTS hmac_replay(
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    worker_id TEXT,
    ts INTEGER NOT NULL,
    body_sha TEXT NOT NULL,
    method TEXT NOT NULL,
    path TEXT NOT NULL,
    created_at TEXT NOT NULL,
    UNIQUE(worker_id, ts, body_sha, method, path)
  )");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_hmac_replay_ts ON hmac_replay(ts)");
}catch(Throwable $e){}
