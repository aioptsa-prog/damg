<?php
// Handle login before sending any output to prevent header/redirect issues
require_once __DIR__ . '/../bootstrap.php';

// DEBUG: Log all POST data to file
$debugLog = __DIR__ . '/../storage/logs/login_debug.log';
$debugData = [
    'time' => date('Y-m-d H:i:s'),
    'method' => $_SERVER['REQUEST_METHOD'],
    'post' => $_POST,
    'get' => $_GET,
];
@file_put_contents($debugLog, print_r($debugData, true) . "\n---\n", FILE_APPEND);

// Prevent caching of login page
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$err = null;
if($_SERVER['REQUEST_METHOD'] === 'POST'){
  // DEBUG: Log CSRF check
  $csrfToken = $_POST['csrf'] ?? '';
  $csrfValid = csrf_verify($csrfToken);
  @file_put_contents($debugLog, "CSRF Token: $csrfToken\nCSRF Valid: " . ($csrfValid ? 'YES' : 'NO') . "\n---\n", FILE_APPEND);
  
  if(!$csrfValid){
    $err = 'CSRF فشل التحقق';
  } else {
    $pdo = db();
    $remember = isset($_POST['remember']);
    $loginType = $_POST['login_type'] ?? 'employee';
    // Simple rate limit: 5 attempts/10min per IP + identity key
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $key = $loginType==='superadmin' ? (trim((string)($_POST['username'] ?? '')) ?: 'superadmin') : (preg_replace('/\D+/','', (string)($_POST['mobile'] ?? '')) ?: 'employee');
    try{
      $stRL = $pdo->prepare("SELECT COUNT(*) c FROM auth_attempts WHERE ip = ? AND key = ? AND created_at > datetime('now','-10 minutes')");
      $stRL->execute([$ip,$key]);
      if(((int)$stRL->fetch()['c']) >= 5){
        $err = 'محاولات كثيرة. جرّب لاحقًا.';
      }
    }catch(Throwable $e){}

    if($loginType === 'superadmin'){
      $username = trim((string)($_POST['username'] ?? ''));
      $password = (string)($_POST['password'] ?? '');
      $remember = isset($_POST['remember']);
      if($username === '' || $password === ''){
        $err = 'بيانات الدخول غير صحيحة.';
      } else {
        $st = $pdo->prepare("SELECT id, username, password_hash, is_superadmin, role FROM users WHERE username = :u LIMIT 1");
        $st->execute([':u'=>$username]);
        $u = $st->fetch();
        if(!$u || (int)($u['is_superadmin'] ?? 0) !== 1 || !password_verify($password, (string)$u['password_hash'])){
          $err = 'بيانات الدخول غير صحيحة.';
          try{ $pdo->prepare("INSERT INTO auth_attempts(ip,key,created_at) VALUES(?,?,datetime('now'))")->execute([$ip,$key]); }catch(Throwable $e){}
        } else {
          if(session_status()===PHP_SESSION_NONE) session_start();
          session_regenerate_id(true);
          $_SESSION['uid'] = (int)$u['id'];
          $_SESSION['role'] = 'admin'; // keep existing ACL (admin role drives UI)
          $_SESSION['is_superadmin'] = 1;
          $_SESSION['username'] = (string)$u['username'];
          if($remember){
            $env=require __DIR__ . '/../config/.env.php';
            $token=bin2hex(random_bytes(32));
            $th=hash('sha256',$token);
            $exp=date('Y-m-d H:i:s', time()+86400*intval($env['REMEMBER_DAYS']));
            $pdo->prepare("INSERT INTO sessions(user_id,token_hash,expires_at,created_at) VALUES(?,?,?,datetime('now'))")->execute([(int)$u['id'],$th,$exp]);
            $secure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS'])!=='off');
            setcookie($env['REMEMBER_COOKIE'],$token,[ 'expires'=> time()+86400*intval($env['REMEMBER_DAYS']), 'path'=>'/', 'domain'=>'', 'secure'=>$secure, 'httponly'=>true, 'samesite'=>'Strict' ]);
          }

          $to = linkTo('admin/dashboard.php');
          header('Location: '.$to, true, 303); exit;
        }
      }
    } else {
      // employee: mobile + password (existing behavior)
      $mobile = trim((string)($_POST['mobile'] ?? ''));
      $password = (string)($_POST['password'] ?? '');
      if($mobile === '' || $password === ''){
        $err = 'رقم الجوال أو كلمة السر غير صحيحة.';
      } else {
        if(login($mobile, $password, $remember)){
          $u = current_user();
          $to = linkTo($u && $u['role'] === 'admin' ? 'admin/dashboard.php' : 'agent/dashboard.php');
          header('Location: '.$to, true, 303); exit;
        } else {
          $err = 'رقم الجوال أو كلمة السر غير صحيحة.';
          try{ $pdo->prepare("INSERT INTO auth_attempts(ip,key,created_at) VALUES(?,?,datetime('now'))")->execute([$ip,$key]); }catch(Throwable $e){}
        }
      }
    }
  }
}

// After handling POST, render the login form
include __DIR__ . '/../layout_header.php';
?>
<div class="card" style="max-width:720px;margin-inline:auto">
  <h2 style="margin-bottom:8px">تسجيل الدخول</h2>
  <?php if($err): ?><p class="badge danger"><?php echo htmlspecialchars($err); ?></p><?php endif; ?>
  <div class="tabs" style="margin-bottom:1rem">
    <?php $mode = $_GET['mode'] ?? 'employee'; ?>
    <a class="btn <?php echo $mode==='employee'?'primary':''; ?>" href="<?php echo linkTo('auth/login.php?mode=employee'); ?>" title="الموظفون">دخول الموظفين</a>
    <a class="btn <?php echo $mode==='superadmin'?'primary':''; ?>" href="<?php echo linkTo('auth/login.php?mode=superadmin'); ?>" title="سوبر أدمن">دخول السوبر أدمن</a>
  </div>

  <?php if(($mode==='superadmin')): ?>
  <form method="post" class="grid-2">
    <?php echo csrf_input(); ?>
    <input type="hidden" name="login_type" value="superadmin">
    <div><label>اسم المستخدم</label><input name="username" autocomplete="username" required placeholder="admin"></div>
    <div><label>كلمة السر</label><input name="password" type="password" autocomplete="current-password" required></div>
    <div style="display:flex;align-items:center;gap:8px"><label style="margin:0"><input type="checkbox" name="remember" checked class="ui-switch"></label><span>تذكرني 30 يوم</span></div>
    <div style="display:flex;align-items:end;justify-content:flex-start"><button class="btn primary">دخول</button></div>
  </form>
  
  <?php else: ?>
  <form method="post" class="grid-2">
    <?php echo csrf_input(); ?>
    <input type="hidden" name="login_type" value="employee">
    <div><label>رقم الجوال</label><input name="mobile" required placeholder="05xxxxxxxx"></div>
    <div><label>كلمة المرور</label><input name="password" type="password" required></div>
    <div style="display:flex;align-items:center;gap:8px"><label style="margin:0"><input type="checkbox" name="remember" checked class="ui-switch"></label><span>تذكرني 30 يوم</span></div>
    <div style="display:flex;align-items:end;justify-content:flex-start"><button class="btn primary">دخول</button></div>
  </form>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/../layout_footer.php'; ?>
