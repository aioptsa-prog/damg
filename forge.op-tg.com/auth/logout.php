<?php
require_once __DIR__ . '/../bootstrap.php';
// Start session to allow proper logout even if not started
if(session_status()===PHP_SESSION_NONE) session_start();
logout();
$to = linkTo('auth/login.php');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Location: ' . $to, true, 302);
?>
<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8">
<meta http-equiv="refresh" content="0;url=<?php echo htmlspecialchars($to, ENT_QUOTES, 'UTF-8'); ?>">
<body style="font-family:system-ui,-apple-system,Segoe UI,Roboto">
<div class="card" style="max-width:600px;margin:10% auto;text-align:center">
  <h2>تم تسجيل الخروج</h2>
  <p>سيتم تحويلك الآن… إن لم يتم التحويل تلقائيًا، <a href="<?php echo htmlspecialchars($to, ENT_QUOTES, 'UTF-8'); ?>">اضغط هنا</a>.</p>
</div>
</body></html>
<?php exit; ?>
