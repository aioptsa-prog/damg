# Security Audit

## Layers
- HTTPS + (HSTS to enable)
- CSP (nonce-based) Phase-1; Phase-2 removes unsafe-inline fully
- CSRF for UI forms/actions
- HMAC + Replay Guard for worker API
- Rate limiting for public/sensitive endpoints
- .htaccess deny for /tools, /config, /storage

## Gaps & Fix Plan
| Item | Risk | Action | Priority | Effort | ETA |
|---|---|---|---|---|---|
| Secrets in DB | Medium | Move to ENV + rotation | Critical | S | 24h |
| HSTS disabled | Medium | Add HSTS header | High | S | today |
| CSP Phase-2 | Medium | Remove unsafe-inline | High | M | 5d |
| per-worker-secret | High | Enforce + rotate | Critical | S | today |
| External monitoring | Medium | Uptime+webhook | High | S | 24h |

## Snippets
- HSTS (.htaccess)
```
<IfModule mod_headers.c>
  Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains; preload"
</IfModule>
```
- ENV Secret (PHP)
```php
$secret = getenv('NEXUS_INTERNAL_SECRET') ?: '';
if(!defined('INTERNAL_SECRET')) define('INTERNAL_SECRET', $secret);
```
- per-worker-secret (PHP)
```php
if(get_setting('per_worker_secret_required','0')==='1'){
  $wid=$_SERVER['HTTP_X_WORKER_ID']??''; $sec=$_SERVER['HTTP_X_WORKER_SECRET']??'';
  if(!auth_worker_secret_ok($wid,$sec)) json_exit(401,['error'=>'unauthorized']);
}
```

Evidence: `.htaccess`, `lib/system.php`, `api/heartbeat.php`, `api/report_results.php`.
