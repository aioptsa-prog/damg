<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../bootstrap.php';
$ok = true; $notes = [];
// DB check
try {
  $pdo = db();
  $stmt = $pdo->query('SELECT 1');
  $stmt->fetch();
} catch (Throwable $e) {
  $ok = false; $notes[] = 'db_error';
}
echo json_encode(['ok'=>$ok,'time'=>gmdate('c'),'notes'=>$notes]);
exit;
