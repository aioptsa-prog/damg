# Security, Secrets, and CSP Audit (initial)

Secrets inventory (settings):
- internal_secret (worker auth HMAC key)
- per-worker internal_workers.secret (optional)
- google_api_key, foursquare_api_key, mapbox_api_key, radar_api_key (3rd party)
- washeej_token (WhatsApp API)
- maintenance_secret (ops)
- alert_slack_token, alert_webhook_url, alert_email

Header security:
- HSTS when HTTPS detected (Strict-Transport-Security)
- X-Frame-Options: SAMEORIGIN
- CSP enforced via allowlist; some legacy pages under admin/diagnostics skip CSP
- CSP: Phase-0 nonce support added. Policy retains 'unsafe-inline' while allowing `nonce-<value>` in script-src. Two admin pages (dashboard, update_channel) now tag inline scripts with the nonce. Next phase will remove 'unsafe-inline' after wider adoption.

Auth models:
- Worker APIs: HMAC (X-Auth-TS, X-Auth-Sign, X-Worker-Id) with replay guard table; legacy X-Internal-Secret fallback gated by per_worker_secret_required
- Admin APIs/pages: Session cookie + CSRF token in body/form; role=admin enforced where required

Retention recommendations:
- hmac_replay: purge entries older than 7 days (cron)
- rate_limit, auth_attempts: purge >14 days
- job_attempts: purge >30 days; aggregate counters
- dead_letter_jobs: archive + purge >90 days
- storage/logs/workers/*.log: rotate weekly; size cap 1 MB per worker

Open items:
- Add cron script tools/ops/retention_purge.php implementing the above
- CSP nonce: Phase-0 implemented (nonce generator + two pages). Phase-1: convert/mark inline scripts across templates; Phase-2: drop 'unsafe-inline' from script-src.
- Secrets rotation runbook: document rotating internal_secret and per-worker secrets without downtime
