# Internal API Security — HMAC + Legacy Secret

This document describes how internal worker endpoints are authenticated using a time-bound HMAC signature while keeping the legacy X-Internal-Secret header as a fallback for compatibility.

Highlights:
- Headers: X-Auth-Ts, X-Auth-Sign (primary) and X-Internal-Secret (fallback)
- Optional: X-Worker-Secret (secret per worker) — when configured, server accepts it as an additional guard; can be made required via per_worker_secret_required=1
- Signature: HMAC-SHA256(INTERNAL_SECRET, method|path|body_sha256|ts)
- Time window: ±300 seconds (5 minutes)
- Endpoints covered: /api/heartbeat.php, /api/pull_job.php, /api/report_results.php
- Rejection policy: 401 Unauthorized on invalid/expired signatures

## Headers

- X-Auth-Ts: Unix epoch seconds (string)
- X-Auth-Sign: lowercase hex SHA-256 HMAC of the canonical message
- X-Internal-Secret: legacy static secret gate (still accepted)
- X-Worker-Id: worker identifier (recommended)
- X-Worker-Info: optional JSON with runtime info (heartbeat only)

The server verifies HMAC if present; otherwise it falls back to comparing X-Internal-Secret against the configured INTERNAL_SECRET. Requests outside the ±300s window are rejected with 401.

## Canonical message

Let secret = INTERNAL_SECRET, method = uppercased HTTP method, path = URL path only (no scheme/host/query), body_sha256 = hex sha256 of the raw request body (empty for GET), ts = X-Auth-Ts.

Message: METHOD|PATH|BODY_SHA256|TS
Signature: hex(hmac_sha256(secret, Message))

Examples:
- GET /api/pull_job.php?lease_sec=180 → body is empty → BODY_SHA256 is sha256("")
- POST /api/report_results.php with JSON body → BODY_SHA256 is sha256(raw-json)

## Time window

Requests are allowed only when the absolute difference between server time and X-Auth-Ts is ≤ 300 seconds. Ensure your worker machines have time sync enabled (NTP). On Windows, use the built-in time service.

## Per-worker secret rollout

- Generate a secret in Admin → Workers → سر (worker_secret.php)
- Put the secret in the worker .env as WORKER_SECRET=<value>
- The worker will send X-Worker-Secret alongside HMAC/X-Internal-Secret
- To enforce it strictly per worker, set settings: per_worker_secret_required=1 (the server will require X-Worker-Secret when a secret exists for that worker)
- Rotation: use “بدء تدوير” لتعبئة rotating_to، ثم “ترقية السر” للتفعيل؛ أثناء التدوير، يقبل الخادوم كلا السرين مؤقتًا

Windows time sync quick checks:
- Query status: w32tm /query /status
- Resync now: w32tm /resync

## cURL examples

Heartbeat (GET):

```bash
# PowerShell: compute sha256("") once (e3b0...)
$emptySha = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'
$ts = [int][double]::Parse((Get-Date -Date (Get-Date).ToUniversalTime() -UFormat %s))
$method = 'GET'; $path = '/api/heartbeat.php'
$msg = "$method|$path|$emptySha|$ts"
$sign = (New-Object System.Security.Cryptography.HMACSHA256 ([Text.Encoding]::UTF8.GetBytes($env:INTERNAL_SECRET))).ComputeHash([Text.Encoding]::UTF8.GetBytes($msg)) | ForEach-Object { $_.ToString('x2') } | ForEach-Object -Join ''

curl -sS -H "X-Auth-Ts: $ts" -H "X-Auth-Sign: $sign" -H "X-Worker-Id: wrk-demo" "http://localhost/LeadsMembershipPRO/api/heartbeat.php"
```

Pull job (GET):

```bash
$emptySha = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'
$ts = [int][double]::Parse((Get-Date -Date (Get-Date).ToUniversalTime() -UFormat %s))
$method = 'GET'; $path = '/api/pull_job.php'
$msg = "$method|$path|$emptySha|$ts"
$sign = (New-Object System.Security.Cryptography.HMACSHA256 ([Text.Encoding]::UTF8.GetBytes($env:INTERNAL_SECRET))).ComputeHash([Text.Encoding]::UTF8.GetBytes($msg)) | ForEach-Object { $_.ToString('x2') } | ForEach-Object -Join ''

curl -sS -H "X-Auth-Ts: $ts" -H "X-Auth-Sign: $sign" -H "X-Worker-Id: wrk-demo" "http://localhost/LeadsMembershipPRO/api/pull_job.php?lease_sec=180"
```

Report results (POST):

```bash
$body = '{"job_id": 123, "items": [], "cursor": 0, "done": true, "extend_lease_sec": 180}'
$bytes = [Text.Encoding]::UTF8.GetBytes($body)
$sha256 = New-Object System.Security.Cryptography.SHA256Managed
$hashBytes = $sha256.ComputeHash($bytes)
$bodySha = ($hashBytes | ForEach-Object { $_.ToString('x2') }) -join ''
$ts = [int][double]::Parse((Get-Date -Date (Get-Date).ToUniversalTime() -UFormat %s))
$method = 'POST'; $path = '/api/report_results.php'
$msg = "$method|$path|$bodySha|$ts"
$hmac = New-Object System.Security.Cryptography.HMACSHA256 ([Text.Encoding]::UTF8.GetBytes($env:INTERNAL_SECRET))
$sign = ($hmac.ComputeHash([Text.Encoding]::UTF8.GetBytes($msg)) | ForEach-Object { $_.ToString('x2') }) -join ''

curl -sS -H "Content-Type: application/json" -H "X-Auth-Ts: $ts" -H "X-Auth-Sign: $sign" -H "X-Worker-Id: wrk-demo" -d "$body" "http://localhost/LeadsMembershipPRO/api/report_results.php"
```

Note: You can still add -H "X-Internal-Secret: <secret>" in migration periods.

## Node.js fetch example

```js
import crypto from 'crypto';
import fetch from 'node-fetch';

function sha256Hex(s){ return crypto.createHash('sha256').update(s || '', 'utf8').digest('hex'); }
function sign(secret, method, path, body){
  const ts = Math.floor(Date.now()/1000).toString();
  const bodySha = sha256Hex(body || '');
  const msg = `${method.toUpperCase()}|${path}|${bodySha}|${ts}`;
  const sig = crypto.createHmac('sha256', secret).update(msg).digest('hex');
  return { ts, sig };
}

const base = 'http://localhost/LeadsMembershipPRO';
const secret = process.env.INTERNAL_SECRET;
const { ts, sig } = sign(secret, 'GET', '/api/heartbeat.php', '');
fetch(`${base}/api/heartbeat.php`, {
  headers: { 'X-Auth-Ts': ts, 'X-Auth-Sign': sig, 'X-Worker-Id': 'wrk-demo' }
}).then(r=>r.json()).then(console.log);
```

## Rejection policy

- 401 Unauthorized when:
  - Signature missing or invalid
  - Timestamp missing or non-numeric
  - Request outside ±300s window
  - Legacy secret mismatch when HMAC not supplied

## Notes

- Use HTTPS in production.
- Do not log secrets. The server never returns the secret.
- Workers send both headers for smooth rollout; HMAC becomes the primary guard over time.
