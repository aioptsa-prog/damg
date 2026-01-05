<?php
// One-time seeder for superadmin. Run manually, then delete the file.
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';

$pdo = db();
$username = 'admin';
$mobile = '590000000';
$pass = '@OpTarget20#30';
$hash = password_hash($pass, PASSWORD_BCRYPT, ['cost'=>12]);

// Upsert-like behavior for SQLite: try update then insert if not exists
$pdo->beginTransaction();
try{
  $st = $pdo->prepare("UPDATE users SET password_hash=:h, is_superadmin=1 WHERE username=:u");
  $st->execute([':h'=>$hash, ':u'=>$username]);
  if($st->rowCount()===0){
    $ins = $pdo->prepare("INSERT INTO users(username,mobile,name,role,password_hash,is_superadmin,active,created_at) VALUES(:u,:m,'Super Admin','admin',:h,1,1,datetime('now'))");
    $ins->execute([':u'=>$username, ':m'=>$mobile, ':h'=>$hash]);
  }
  $pdo->commit();
  echo "Superadmin seeded (username: admin). Change the password after first login.\n";
}catch(Throwable $e){
  $pdo->rollBack();
  fwrite(STDERR, 'Failed: '.$e->getMessage()."\n");
  exit(1);
}
