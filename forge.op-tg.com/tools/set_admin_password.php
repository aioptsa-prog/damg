<?php
// CLI: Set/rotate the superadmin ('admin') password safely.
// Usage: php tools/set_admin_password.php <new_password>
require_once __DIR__ . '/../config/db.php';
if (php_sapi_name() !== 'cli') { fwrite(STDERR, "[ERR] CLI only\n"); exit(1); }
$pwd = $argv[1] ?? '';
if ($pwd === '') { fwrite(STDERR, "Usage: php tools/set_admin_password.php <new_password>\n"); exit(1); }
try{
  $pdo = db();
  $ph = password_hash($pwd, PASSWORD_DEFAULT);
  $row = $pdo->query("SELECT id FROM users WHERE username='admin' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
  if($row){
    $pdo->prepare("UPDATE users SET password_hash=?, is_superadmin=1, role='admin', active=1 WHERE id=?")
        ->execute([$ph, (int)$row['id']]);
  } else {
    $pdo->prepare("INSERT INTO users(mobile,username,name,role,password_hash,is_superadmin,active,created_at) VALUES(?,?,?,?,?,1,1,datetime('now'))")
        ->execute(['0500000009','admin','Administrator','admin',$ph]);
  }
  fwrite(STDOUT, "OK: admin password updated.\n");
  exit(0);
}catch(Throwable $e){ fwrite(STDERR, "[ERR] ".$e->getMessage()."\n"); exit(1); }
