<?php
// Initialize Saudi Arabia Geo DB with regions and schema.
if (php_sapi_name() !== 'cli') { echo "CLI only\n"; exit(1); }
require_once __DIR__ . '/../../bootstrap.php';

$dir = __DIR__ . '/../../storage/data/geo/sa';
@mkdir($dir, 0777, true);
$dbPath = $dir . '/sa_geo.db';

try{
  $pdo = new PDO('sqlite:'.$dbPath);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
  // Schema
  $pdo->exec("CREATE TABLE IF NOT EXISTS regions(
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT UNIQUE NOT NULL,
    name_ar TEXT NOT NULL,
    name_en TEXT,
    lat REAL,
    lon REAL
  );");
  $pdo->exec("CREATE TABLE IF NOT EXISTS cities(
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    region_code TEXT,
    name_ar TEXT,
    name_en TEXT,
    alt_names TEXT,
    lat REAL,
    lon REAL,
    population INTEGER,
    wikidata TEXT,
    FOREIGN KEY(region_code) REFERENCES regions(code)
  );");
  $pdo->exec("CREATE TABLE IF NOT EXISTS districts(
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    city_id INTEGER NOT NULL,
    name_ar TEXT,
    name_en TEXT,
    lat REAL,
    lon REAL,
    FOREIGN KEY(city_id) REFERENCES cities(id) ON DELETE CASCADE
  );");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_regions_code ON regions(code);");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cities_name_ar ON cities(name_ar);");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cities_region_code ON cities(region_code);");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_districts_city ON districts(city_id);");

  // Seed 13 regions (approximate centers)
  $regions = [
    ['RIY','الرياض','Riyadh',24.7136,46.6753],
    ['MEK','مكة المكرمة','Makkah',21.3891,39.8579],
    ['MED','المدينة المنورة','Madinah',24.5247,39.5692],
    ['QAS','القصيم','Al-Qassim',26.3587,43.9810],
    ['EPR','المنطقة الشرقية','Eastern Province',26.3927,49.9777],
    ['ASR','عسير','Asir',18.2465,42.5117],
    ['TAB','تبوك','Tabuk',28.3838,36.5662],
    ['HAI','حائل','Hail',27.5219,41.6907],
    ['NBR','الحدود الشمالية','Northern Borders',30.9753,41.0386],
    ['JZN','جازان','Jazan',16.8890,42.5510],
    ['NAJ','نجران','Najran',17.5650,44.2280],
    ['BAH','الباحة','Al Bahah',20.0120,41.4670],
    ['JWF','الجوف','Al Jawf',29.9697,40.2064],
  ];
  $ins = $pdo->prepare("INSERT INTO regions(code,name_ar,name_en,lat,lon) VALUES(?,?,?,?,?) ON CONFLICT(code) DO UPDATE SET name_ar=excluded.name_ar,name_en=excluded.name_en,lat=excluded.lat,lon=excluded.lon");
  foreach($regions as $r){ $ins->execute($r); }

  // Normalization/synonyms file
  $norm = [
    'synonyms' => [
      'مكة المكرمة' => ['مكة'],
      'المدينة المنورة' => ['المدينة'],
      'المنطقة الشرقية' => ['الشرقية'],
      'جازان' => ['جيزان'],
      'حائل' => ['حايل'],
      'الجوف' => ['الجوْف'],
    ]
  ];
  file_put_contents($dir.'/normalization.json', json_encode($norm, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));

  echo "OK: sa_geo.db initialized with regions.\n";
  exit(0);
}catch(Throwable $e){
  fwrite(STDERR, $e->getMessage()."\n");
  exit(2);
}
