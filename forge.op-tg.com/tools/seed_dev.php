<?php
// Dev-only seeding script. Run with APP_ENV=dev or pass --yes-im-dev explicitly.
// php tools/seed_dev.php [--yes-im-dev]

require_once __DIR__ . '/../bootstrap.php';

$allow = (getenv('APP_ENV') === 'dev') || in_array('--yes-im-dev', $argv ?? [], true);
if(!$allow){
  fwrite(STDERR, "Refusing to run in non-dev environment. Set APP_ENV=dev or pass --yes-im-dev.\n");
  exit(2);
}

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "Seeding development data...\n";

// 1) Admin user with random password (printed to console only)
$admin = $pdo->query("SELECT id FROM users WHERE role='admin' LIMIT 1")->fetch();
if(!$admin){
  $plain = bin2hex(random_bytes(4)) . '!Aa'; // short but random for dev
  $hash = password_hash($plain, PASSWORD_DEFAULT);
  $stmt = $pdo->prepare("INSERT INTO users(mobile,name,role,password_hash,created_at) VALUES(?,?,?,?,datetime('now'))");
  $stmt->execute(['0590000000','Dev Admin','admin',$hash]);
  echo "Created admin user (mobile=0590000000, password=$plain)\n";
} else {
  echo "Admin already exists; skipping.\n";
}

// 2) Minimal taxonomy (categories + keywords)
$pdo->exec("CREATE TABLE IF NOT EXISTS categories(id INTEGER PRIMARY KEY AUTOINCREMENT, parent_id INTEGER, name TEXT NOT NULL, created_at TEXT NOT NULL)");
$pdo->exec("CREATE TABLE IF NOT EXISTS category_keywords(id INTEGER PRIMARY KEY AUTOINCREMENT, category_id INTEGER NOT NULL, keyword TEXT NOT NULL, created_at TEXT NOT NULL)");
$selC = $pdo->prepare('SELECT id FROM categories WHERE name=?');
$insC = $pdo->prepare("INSERT INTO categories(parent_id,name,created_at) VALUES(?,?,datetime('now'))");
$insK = $pdo->prepare("INSERT INTO category_keywords(category_id,keyword,created_at) VALUES(?,?,datetime('now'))");

$createCat = function($name, $kw) use ($selC,$insC,$insK,$pdo){
  $selC->execute([$name]); $row = $selC->fetch();
  $cid = $row ? (int)$row['id'] : null;
  if(!$cid){ $insC->execute([null,$name]); $cid = (int)$pdo->lastInsertId(); echo "Category: $name\n"; }
  foreach((array)$kw as $k){ $insK->execute([$cid, $k]); }
};

$createCat('مطعم', ['مطعم','restaurant']);
$createCat('كوفي', ['كوفي','cafe']);

// 3) Sample lead
$insL = $pdo->prepare("INSERT OR IGNORE INTO leads(phone,name,city,country,created_at,source,created_by_user_id) VALUES(?,?,?,?,datetime('now'),'demo',?)");
$adminId = (int)($pdo->query("SELECT id FROM users WHERE role='admin' LIMIT 1")->fetch()['id'] ?? 0);
$insL->execute(['0550000001','مطعم الاختبار','الرياض','السعودية',$adminId]);
echo "Inserted sample lead(s).\n";

// 4) Simple internal job
$pdo->exec("CREATE TABLE IF NOT EXISTS internal_jobs(id INTEGER PRIMARY KEY AUTOINCREMENT, requested_by_user_id INTEGER NOT NULL, role TEXT NOT NULL, agent_id INTEGER, query TEXT NOT NULL, ll TEXT NOT NULL, radius_km INTEGER NOT NULL, lang TEXT NOT NULL, region TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'queued', worker_id TEXT, claimed_at TEXT, finished_at TEXT, result_count INTEGER DEFAULT 0, error TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)");
$insJ = $pdo->prepare("INSERT INTO internal_jobs(requested_by_user_id, role, agent_id, query, ll, radius_km, lang, region, status, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,'queued',datetime('now'),datetime('now'))");
$insJ->execute([$adminId,'admin', null, 'مطعم', '24.7136,46.6753', 10, 'ar', 'sa']);
echo "Inserted a queued internal job.\n";

echo "Done.\n";
