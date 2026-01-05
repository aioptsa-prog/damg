<?php
define('PROJ_ROOT', __DIR__);
function linkTo($p){ $depth = substr_count(str_replace(PROJ_ROOT,'', realpath(dirname($_SERVER['SCRIPT_FILENAME']))), DIRECTORY_SEPARATOR); return str_repeat('../', $depth) . ltrim($p,'/'); }
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/csrf.php';
require_once __DIR__ . '/lib/system.php';
date_default_timezone_set('Asia/Riyadh');
// Disable debug display by default (can be enabled temporarily with ?debug=1 for local checks only)
if(isset($_GET['debug']) && $_GET['debug']=='1'){
	ini_set('display_errors',0); // keep off in production pages to avoid accidental leakage
	error_reporting(E_ALL);
} else {
	ini_set('display_errors',0);
	error_reporting(E_ALL);
}
function http_get_json($url,$headers=[]){ $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60,CURLOPT_SSL_VERIFYPEER=>false]); if($headers){ curl_setopt($ch,CURLOPT_HTTPHEADER,$headers);} $raw=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch); if($code!==200||!$raw) return null; return json_decode($raw,true); }

// Derive BASE_URL from request if not configured, to avoid stale .env hints
if(!function_exists('app_base_url')){
	function app_base_url(){
		$cfg = get_setting('worker_base_url','');
		if($cfg){ return rtrim($cfg,'/'); }
		$scheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS'])!=='off') ? 'https' : 'http';
		$host = $_SERVER['HTTP_HOST'] ?? '';
		if(!$host) return '';
		$script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
		$root = rtrim(str_replace('\\','/', dirname($script)), '/');
		// Our code lives at project root; if current script is in /api, go one level up
		if(substr($root,-4)==='/api') $root = rtrim(substr($root,0,-4), '/');
		return ($scheme.'://'.$host).$root;
	}
}

// Apply global non-breaking guards and headers (feature-gated for CSRF/rate limiting)
if(function_exists('system_auto_guard_request')){ system_auto_guard_request(); }
