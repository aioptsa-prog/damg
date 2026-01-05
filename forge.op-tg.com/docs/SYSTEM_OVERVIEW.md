# System Overview

Goal
- Arabic-first internal scraping/ingestion with Windows workers and an admin UI.

Main Components
- Admin/API (PHP 8.x + SQLite): authentication, admin pages, diagnostics, internal job queue, classification, exports, REST endpoints under `/api/*`.
- Worker (Windows, Node + Playwright): pulls jobs, processes them, reports progress, auto-updates via installer.
- Installer/Updates (Inno Setup + latest.json): versioned EXE, published metadata, download endpoint with Range/HEAD support.

Key Flows
1) Worker lifecycle
	- Heartbeat → Pull job → Process → Report results (batched) → Lease renew/complete → Self-update when newer available.
2) Admin operations
	- Queue management (requeue/cancel/extend), monitoring (SSE/live), diagnostics (read-only status, jobs, batches), settings.
3) Geo/Maps
	- Leaflet map on fetch pages, local-first assets under `assets/vendor/leaflet`, resilient tile sources with admin-configurable `tile_sources_json`.

Notable Docs
- RUNBOOK.md: deploy, rollback, validation, probes, smoke tests (including map smoke test).
- DIAGNOSTICS.md: admin diagnostics page, CLI probes, Leaflet troubleshooting.
- INCIDENTS.md: recorded incidents incl. Oct 2025 map outage with root cause and actions.
- ARCH_DECISIONS.md: ADRs for Leaflet local-first, tile_sources_json, CSS protection.
- CONFIG_REFERENCE.md: key settings and feature flags.

Security Baseline
- Non-breaking security headers (nosniff/referrer-policy/HSTS); CSP report-only initially.
- CSRF support (auto mode via flag), strict session cookies, minimal exposure of secrets.

Data Storage
- SQLite file under `storage/` with idempotent migrations in `config/db.php`.

UI/UX Highlights
- RTL Arabic-first, Tajawal font, light/dark theme, DataTables with column manager and density presets, filter persistence via `ui_persist_filters`.

See Also
- WORKER_RUNBOOK.md for worker operations
- UPDATE_FLOW.md for release/update pipeline
