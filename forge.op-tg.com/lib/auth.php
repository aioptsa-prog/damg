<?php
require_once __DIR__ . '/../config/db.php';
// Ensure session cookie is safe
if (session_status() === PHP_SESSION_NONE) {
	$params = session_get_cookie_params();
	session_set_cookie_params([
		'lifetime' => $params['lifetime'],
		'path' => $params['path'] ?: '/',
		'domain' => $params['domain'] ?? '',
		'secure' => (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off'),
		'httponly' => true,
		'samesite' => 'Lax',
	]);
}
function get_setting($k, $f = '')
{
	$st = db()->prepare('SELECT value FROM settings WHERE key=?');
	$st->execute([$k]);
	$r = $st->fetch();
	return $r ? $r['value'] : $f;
}
function set_setting($k, $v)
{
	db()->prepare("INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value")->execute([$k, $v]);
}
function get_user_by_id($id)
{
	$st = db()->prepare("SELECT * FROM users WHERE id=? AND active=1");
	$st->execute([$id]);
	return $st->fetch();
}
function current_user()
{
	// First, check for Bearer token in Authorization header (for API requests)
	// This must be done BEFORE session_start() to avoid header already sent errors
	if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
		$auth = $_SERVER['HTTP_AUTHORIZATION'];
		if (preg_match('/Bearer\s+(.+)$/i', $auth, $matches)) {
			$token = $matches[1];
			$token_hash = hash('sha256', $token);
			$st = db()->prepare("SELECT user_id FROM sessions WHERE token_hash=? AND expires_at>?");
			$st->execute([$token_hash, date('Y-m-d H:i:s')]);
			$row = $st->fetch();
			if ($row) {
				return get_user_by_id($row['user_id']);
			}
		}
	}

	// Then check session (only if headers haven't been sent)
	if (!headers_sent() && session_status() === PHP_SESSION_NONE) {
		session_start();
	}
	if (isset($_SESSION['uid']))
		return get_user_by_id($_SESSION['uid']);

	// Finally check remember cookie
	$env = require __DIR__ . '/../config/.env.php';
	$cookie = $env['REMEMBER_COOKIE'];
	if (!empty($_COOKIE[$cookie])) {
		$tok = $_COOKIE[$cookie];
		$th = hash('sha256', $tok);
		$st = db()->prepare("SELECT user_id FROM sessions WHERE token_hash=? AND expires_at>?");
		$st->execute([$th, date('Y-m-d H:i:s')]);
		$row = $st->fetch();
		if ($row) {
			if (!headers_sent() && session_status() === PHP_SESSION_NONE) {
				session_start();
			}
			$_SESSION['uid'] = $row['user_id'];
			return get_user_by_id($row['user_id']);
		}
	}
	return null;
}
function login($mobile, $password, $remember)
{
	$st = db()->prepare("SELECT * FROM users WHERE mobile=? AND active=1");
	$st->execute([$mobile]);
	$u = $st->fetch();
	if (!$u || !password_verify($password, $u['password_hash']))
		return false;
	if (session_status() === PHP_SESSION_NONE)
		session_start();
	session_regenerate_id(true);
	$_SESSION['uid'] = $u['id'];

	// ALWAYS create a token for API authentication (Bearer token)
	$env = require __DIR__ . '/../config/.env.php';
	$token = bin2hex(random_bytes(32));
	$th = hash('sha256', $token);

	// Set expiration based on remember flag
	$days = $remember ? intval($env['REMEMBER_DAYS']) : 30; // Default 30 days for API tokens
	$exp = date('Y-m-d H:i:s', time() + 86400 * $days);

	// Save token to database
	db()->prepare("INSERT INTO sessions(user_id,token_hash,expires_at,created_at) VALUES(?,?,?,datetime('now'))")->execute([$u['id'], $th, $exp]);

	// Only set cookie if remember is true
	if ($remember) {
		$secure = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off');
		setcookie($env['REMEMBER_COOKIE'], $token, ['expires' => time() + 86400 * $days, 'path' => '/', 'domain' => '', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
	}

	// Store token in global for API to return it
	$GLOBALS['last_login_token'] = $token;

	return true;
}
function logout()
{
	if (session_status() === PHP_SESSION_NONE)
		session_start();
	$env = require __DIR__ . '/../config/.env.php';
	$cookie = $env['REMEMBER_COOKIE'];
	if (!empty($_COOKIE[$cookie])) {
		$th = hash('sha256', $_COOKIE[$cookie]);
		db()->prepare("DELETE FROM sessions WHERE token_hash=?")->execute([$th]);
		$secure = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off');
		setcookie($cookie, '', ['expires' => time() - 3600, 'path' => '/', 'domain' => '', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict']);
	}
	session_destroy();
}
function require_login()
{
	$u = current_user();
	if (!$u) {
		$to = linkTo('auth/login.php');
		header('Location: ' . $to, true, 302);
		echo '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8">'
			. '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($to, ENT_QUOTES, 'UTF-8') . '">'
			. '<body style="background:#0b1220;color:#eef3fb;font-family:system-ui;">'
			. '<div style="max-width:600px;margin:10% auto;text-align:center">'
			. 'يتم تحويلك إلى صفحة الدخول… إن لم يتم التحويل تلقائيًا، '
			. '<a href="' . htmlspecialchars($to, ENT_QUOTES, 'UTF-8') . '" style="color:#93c5fd">اضغط هنا</a>.'
			. '</div></body></html>';
		exit;
	}
	return $u;
}
function require_role($r)
{
	$u = require_login();
	if ($u['role'] !== $r) {
		$to = linkTo($u['role'] === 'admin' ? 'admin/dashboard.php' : 'agent/dashboard.php');
		header('Location: ' . $to, true, 303);
		echo '<!doctype html><meta charset="utf-8"><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($to, ENT_QUOTES, 'UTF-8') . '">'
			. 'الوصول غير مُصرّح — تحويل تلقائي…';
		exit;
	}
	return $u;
}
