# Release Runbook

This runbook covers patch application, configuration, validation, and go/no-go checks.

## Prereqs
- PHP 8+
- SQLite
- curl and openssl (for Linux/macOS tests)
- PowerShell (Windows tests)

## Apply patches

1) Apply patch bundles in order:

- git apply patches/0004-tests-linux-curl.patch
- git apply patches/0005-rate-limit-store.patch
- git apply patches/0006-docs-runbook.patch

2) Apply hardened settings

- php tools/deploy_apply_settings.php
  - Sets: force_https=1, security_csrf_auto=1, rate_limit_basic=1, per_worker_secret_required=1
  - Ensures internal_secret is present

## Seed dev (optional)

- APP_ENV=dev php tools/seed_dev.php
  - Creates an admin with a random password (printed only)
  - Seeds a small taxonomy, a sample lead, and a queued internal job

## Tests

- Linux/macOS E2E (expects 200/204, then 200, then 409 replay):
  - bash tools/tests/test_hmac_curl.sh
- Windows E2E:
  - powershell -File tools/tests/test_hmac_windows.ps1

## Integrity check

- Ensure releases/latest.json (or installer_meta.json) has correct sha256 and size.
- Hitting GET /api/download_worker.php should succeed; if mismatched, HTTP 412 is returned.

## Expected HTTP codes

- 200: OK (heartbeat, pull_job when job present, report_results first submission)
- 204: No Content (pull_job when no job is available)
- 409: replay_detected (duplicate signed request within window)
- 412: integrity check failed (download)
- 429: rate limited (per-IP per path, when enabled)

## SQL snapshots (examples)

- Before/After showing inserts into leads and updates to internal_jobs progress:
  - SELECT COUNT(*) FROM leads;
  - SELECT id, status, result_count, progress_count FROM internal_jobs ORDER BY id DESC LIMIT 3;

- Rate limit table created and updated:
  - SELECT name FROM sqlite_master WHERE type='table' AND name='rate_limit';
  - SELECT * FROM rate_limit ORDER BY window_start DESC LIMIT 5;

## Go/No-Go checklist

- [ ] Patches applied cleanly
- [ ] Hardened settings applied (force_https=1, csrf auto=1, rate_limit=1)
- [ ] HMAC tests pass (Linux/macOS and/or Windows)
- [ ] download_worker integrity OK (no 412)
- [ ] Rate limit can be triggered under load (sample 429 observed)
- [ ] Admin password not weak (banner absent)
- [ ] Monitoring tasks configured per docs/DIAGNOSTICS.md (optional)

## Canary rollout and validation

1) Preflight
- php tools/release/preflight.php

2) Backup
- php tools/backup/run_release_backup.php

3) Canary rollout via UI
- Open admin/update_channel.php
- Set canary percent to 10%; wait 15 minutes; watch dashboard P95 and DLQ
- Increase to 50%; wait 15 minutes
- Increase to 100% or promote default channel to stable

4) Post-deploy validation
- php tools/release/validate_post_deploy.php

5) If failure
- Use admin/rollback.php then re-run validate_post_deploy
