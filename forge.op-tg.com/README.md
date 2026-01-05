# Nexus (OptForge) — Go-Live Playbook

A single-page guide to configure, verify, and launch the system safely.

## 1) Configure core settings (Admin → Settings)
- Internal server: enable; set a strong INTERNAL_SECRET
- Worker pull interval: 30s (or per your capacity)
- Alerts: set one or more of:
  - alert_email (e.g., ops@your-domain.com)
  - alert_webhook_url (Slack/Discord/Teams)
  - alert_slack_token and alert_slack_channel (Slack App v2)
- Optional: Worker Base URL, Worker Config Code

## 2) Prepare artifacts
- Check worker vendor prerequisites on server:
  - php tools/check_worker_vendor.php
- Publish latest metadata (stable/canary):
  - php tools/ops/publish_latest.php stable
  - php tools/ops/publish_latest.php canary
- Build or download portable worker ZIP:
  - GET /api/download_worker.php?kind=zip

## 3) Schedule operations (Windows)
- Alerts every 5 minutes:
  - powershell -ExecutionPolicy Bypass -File tools/ops/schedule_alerts.ps1 -Action Install -EveryMinutes 5
- Log rotation daily:
  - powershell -ExecutionPolicy Bypass -File tools/ops/schedule_rotate_logs.ps1 -Action Install -EveryDays 1 -PhpPath 'php.exe' -PhpArgs 'tools/rotate_logs.php --max-size=25 --max-days=14'

## 4) Preflight and smoke tests
- Preflight (CLI readiness):
  - php tools/ops/go_live_preflight.php
- Smoke test end-to-end (requires INTERNAL_SECRET + worker running):
  - powershell -ExecutionPolicy Bypass -File tools/smoke_test.ps1

## 5) Install a canary worker
- Admin → Worker Setup: download ZIP, download worker.env, run worker_run.bat
- Verify on Admin → Workers and Worker Live; then scale out

## 6) Rollback
- Keep previous site/worker artifacts under releases/
- Use Admin → Worker Channel and circuit breaker on specific workers if needed
- Requeue/cancel jobs from Worker Live or Health

## Documentation map
- docs/RUNBOOK.md — operations runbook and deployment flows
- docs/GO_LIVE_CHECKLIST.md — mandatory checks and verifications
- docs/CONFIG_REFERENCE.md — settings reference
- docs/API.md — endpoints
- docs/PRODUCTION_READINESS.md — readiness notes
- docs/DIAGNOSTICS.md — monitoring and health

## Troubleshooting
- Check storage/logs and worker/logs
- Admin → Health: requeue expired, send centralized commands
- Alerts should fire for offline workers, DLQ, stuck jobs
