# CHANGELOG

## 1.4.3 (2025-10-14)
- Worker UI: Live dashboard with SSR + SSE + polling fallback; local uptime ticking and visible last-refresh
- Logs: New SSE streaming for worker logs with lightweight keep-alives; polling fallback exposed
- Admin: Workers table column alignment fix (header/body count) to resolve DataTables warnings; added Live view per worker
- Circuit breaker: Admin UI toggle per worker (open/close) documented; server returns 429 {error: cb_open}
- Diagnostics: New admin endpoints `/api/worker_stream.php` (SSE) and `/api/worker_status.php` (JSON snapshot)
- UX: Persisted state/version filters on Admin Workers (feature flag `ui_persist_filters`)
 - Ops: `tools/rotate_logs.php` now rotates both app logs (storage/logs) and worker logs (worker/logs) by default; supports multiple --path args
 - Admin UX: Added visual "قاطع" badge next to Worker ID when circuit breaker is open

## 1.4.2 (2025-10-12)
- Maps: Restored Leaflet maps via local-first assets with CDN fallbacks; added CSS safeguards; resilient multi-source tile layer with diagnostics
- Settings: New `tile_sources_json` (optional) to allow institution-approved tile sources
- UI: Minor polish and stability on admin/agent fetch pages; status line shows active tile source/fallbacks
- Docs: Expanded INCIDENTS (full postmortem), DIAGNOSTICS (Leaflet troubleshooting), RUNBOOK (map smoke test), ARCH_DECISIONS (Leaflet ADRs), SYSTEM_OVERVIEW
 - Queue/Workers: Atomic job claim with leases and attempt_id; exponential backoff with jitter and next_retry_at; idempotency keys for report_results
 - Ingestion: Phone normalization (phone_norm + index), fingerprint-based dedup table (leads_fingerprints) with duplicates telemetry, response now includes duplicates
 - Monitoring: Admin monitor badges show 24h duplicates ratio; monitor APIs expose ingestion metrics and stuck jobs diagnostics
 - Docs: Added PRODUCTION_READINESS.md and REPORTS_AND_NEXT_STEPS.md; EVIDENCE_* updated with ingestion probes
 - Reliability: Introduced DLQ (admin/dlq.php) and basic circuit breaker per worker; added alerts_tick.php for offline/DLQ/stuck alerts; worker updates UI with channels

## 1.4.1 (2025-10-01)
- Deployment: SFTP WinSCP script with maintenance + rollback; cPanel UAPI fallback; orchestrator script
- Installer: Arabic UI, versioned EXE, published metadata + latest.json
- HTTP: latest.php (ETag/LM) and download_worker.php (HEAD/Range/206/304)
- Monitoring: Admin pages (Worker Setup, Monitoring w/ Top Cities, Geo)
- Geo: Runtime classification; importer scaffold; acceptance harness
- Ops: Maintenance mode, RUNBOOK, log rotation tool, health/opcache endpoints

Pending: Full SA dataset import + acceptance report, screenshots, live deploy transcripts# Changelog

## 2025-10-01 — v1.4.1
- Added central worker config API (api/worker_config.php)
- Hardened INTERNAL_SECRET defaults and added rotation test (401 old / 200 new)
- Added latest.json publishing and versioned installer filenames in build script
- Enhanced download endpoint with Range/HEAD and caching headers
- Admin Worker Setup now consumes latest.json and displays version/SHA
- Unit-test friendly API exits for heartbeat and pull_job
