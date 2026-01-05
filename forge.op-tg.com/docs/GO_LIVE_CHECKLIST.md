# Go-Live Checklist — October 2025

This checklist captures P0/P1 readiness items, verification steps, and rollback plan for the live launch.

## P0 — Must pass before launch
- [ ] Protect internal diagnostics endpoints (restrict to admin session or local-only): /api/worker_stream.php, /api/worker_status.php (done: admin-gated), any worker /diag (local-only)
- [ ] Worker control buttons verified end-to-end (pause/resume, arm/disarm, reconnect/sync/update/restart) — reflected in /metrics and Admin Workers
- [ ] Portable ZIP packaging on server produces self-contained bundle (node runtime + node_modules) — `GET /api/download_worker.php?kind=zip`
  - Verify with `tools/check_worker_vendor.php` and by extracting the ZIP
- [ ] PHP ZipArchive enabled on the target server
- [ ] Storage permissions: storage/ and storage/releases/ are writable by PHP

## P1 — Should pass soon after launch
- [ ] Log rotation configured for worker and app logs
- [ ] Alerts for offline workers, DLQ growth, and stuck jobs
- [ ] DLQ/Retry flow tested on real data

## Pre-Launch Steps
1) Configure settings: `internal_server_enabled=1`, set strong `internal_secret`.
2) Upload `storage/vendor/` content (node-win64, node_modules, optional ms-playwright).
3) Publish site code and clear OPcache safely (if applicable).
4) Build worker ZIP via `/api/download_worker.php?kind=zip` and verify headers: X-Worker-Installer-*
5) Install a canary worker and run a small job; monitor Admin → Workers and Worker Live.

## Post-Launch Validation
- [ ] Admin Workers loads with no DataTables warnings; filters persist if enabled
- [ ] Worker Live shows SSE stream or polling fallback
- [ ] Circuit breaker behaves as expected (429 cb_open when open)
- [ ] Basic ingestion path: queued → processing → done with idempotency intact

## Rollback Plan
- Keep N-1 code snapshot and previous worker ZIP in `/releases/`.
- If issues arise, revert web code and disable canary worker; close CB for affected workers.
- Re-run smoke tests.
