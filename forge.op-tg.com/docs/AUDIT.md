# Baseline Audit — OptForge (PHP + SQLite + Windows Worker)

## SUMMARY

This document captures the current state of the repository as of 2025-10-02. It maps the project layout, job/worker flows, packaging and release process, database schema, admin UI surface, configuration and secrets, identified risks, and a two‑week action plan split into small PRs.

Scope highlights:
- Backend: PHP 8, SQLite (PDO), shared‑hosting friendly.
- Worker: Windows (Node.js + Playwright + Express), portable ZIP or EXE installer, auto‑open status UI, central control.
- Distribution: Inno Setup (when available) → EXE; otherwise portable ZIP; normalized latest.json; download API with Range/ETag/HEAD; artifacts under releases/ and storage/releases/.
- Branding: OptForge with settings keys brand_name, brand_tagline_ar, brand_tagline_en (legacy fallback to product_name).

## MAP

Top‑level files and directories (key ones only):
- index.php, bootstrap.php, layout_header.php, layout_footer.php — PHP entry/layout.
- config/
  - db.php — SQLite connection + migrations; settings seeding.
- lib/
  - auth.php, csrf.php — sessions, CSRF helpers.
  - system.php — branding helpers, pause/stop helpers, worker tracking.
  - limits.php, providers.php, classify.php, geo.php — rate limits/providers/classification/geo helpers.
- api/
  - heartbeat.php — worker heartbeat + worker registry.
  - pull_job.php — claim/lease job selection (FIFO/newest/random/pow2/rr_agent/fair_query).
  - report_results.php — ingest results, classification, progress/lease update, auto‑done.
  - latest.php — serve normalized latest.json + metadata with absolute URLs.
  - download_worker.php — streams EXE/ZIP with Range/ETag/HEAD, metadata headers.
  - reclassify.php, cron_reclassify.php — bulk/cron reclassification.
  - export_*.php — export utilities.
  - worker_config.php — central config JSON for workers.
  - health.php, monitor_stats.php, debug_headers.php, opcache_reset.php — diagnostics.
  - dev_* — development helpers (guard behind INTERNAL server when relevant).
- admin/
  - dashboard.php, health.php, monitor.php — status/metrics.
  - settings.php — all system settings (internal server, job strategy, update gates, classification weights, etc.).
  - worker_setup.php — download/install instructions, newest EXE/ZIP discovery.
  - internal.php — create/manage internal jobs queue.
  - leads.php, users.php, assign.php — CRM basics.
  - classification.php, categories.php — taxonomy + rules UI.
  - geo.php — geo tools; logs.php — simple logs.
  - fetch.php — admin AJAX endpoints used by UI cards.
- worker/
  - index.js — main runtime (Express + Playwright), endpoints: /status, /metrics, /events, /control.
  - launcher.js — optional exe wrapper entry.
  - worker_run.bat, worker_service.bat, install_service.ps1 — run/service utilities.
  - build_installer.ps1 — EXE/ZIP builder; publishes artifacts + latest.json.
  - package.json — Node deps; ms-playwright/ browser cache; profile-data/ for Chromium profile.
- tools/ops/
  - make_site_zip.ps1 — packages site ZIP; robust staging (avoids recursion), optional worker build.
  - cleanup_releases.ps1, restart_worker.ps1, open_worker_setup.ps1, push_current_cpanel.ps1, cpanel_* — deployment/ops helpers.
- releases/ — public artifacts and latest.json when present.
- storage/
  - app.sqlite (via config/.env.php) — SQLite database.
  - logs/ — audit and processing logs.
  - releases/ — canonical artifacts and metadata.

Entry points:
- Web UI: index.php → admin/* (after login), api/* for endpoints.
- Worker: worker/index.js (run via worker_run.bat or installed service).

Config locations:
- PHP settings table (settings key/value) seeded by config/db.php; accessed via get_setting/settings_get.
- Worker .env (BASE_URL, INTERNAL_SECRET, WORKER_ID, PULL_INTERVAL_SEC, HEADLESS, etc.).
- Branding keys: brand_name, brand_tagline_ar, brand_tagline_en (fallback product_name).

Build/release paths:
- Worker build: worker/build_installer.ps1 → worker/build/*.exe or portable ZIP; copies to releases/ and storage/releases/ with installer_meta.json & latest.json.
- Site zip: tools/ops/make_site_zip.ps1 → releases/site-*.zip (name selectable by OutName).

## FLOWS

Job lifecycle (internal jobs):
- Creation:
  - Admin UI (internal.php or seed actions in settings.php) inserts into internal_jobs with requested_by_user_id, query, ll, radius_km, lang, region; status='queued'.
- Claim/lease:
  - Worker calls api/pull_job.php with X-Internal-Secret and X-Worker-Id.
  - Server validates INTERNAL server toggle and secret, checks global stop/pause.
  - Selection strategy via setting job_pick_order:
    - fifo, newest, random, pow2 (random two → least attempts → oldest), rr_agent (round‑robin over agent_id), fair_query (lowest recent activity per query).
  - Upon selection, status='processing', attempts++, claimed_at set (if null), lease_expires_at=now+lease_sec.
- Progress & results:
  - Worker reports in batches to api/report_results.php with items, cursor, extend_lease_sec.
  - Server upserts leads (INSERT OR IGNORE), augments category via classify.php and geo classification via geo.php; updates assignments when role='agent'.
  - Progress fields updated: progress_count, result_count, last_cursor, last_progress_at, lease_expires_at.
  - Auto‑complete: if done flag sent, or target_count reached → status='done', finished_at, done_reason.
- Retry/lease expiration:
  - pull_job.php treats processing jobs with lease_expires_at < now as eligible; attempts increments on new claim. next_retry_at may be used by future logic.
- Logging/telemetry:
  - Selection log: storage/logs/selection.log lines with job_id, query, agent_id, pick strategy.
  - Worker registry: api/heartbeat.php uses workers_upsert_seen to update internal_workers(worker_id,last_seen,info).

Ops scripts of interest:
- tools/ops/make_site_zip.ps1 — builds worker (optional), stages site via robocopy with strong excludes, zips, cleans stage.
- tools/ops/cleanup_releases.ps1 — trims old artifacts under storage/releases (keep last N) — confirm presence before use.
- worker/build_installer.ps1 — builds EXE via Inno Setup (if ISCC available) else creates portable ZIP; publishes artifacts and metadata; keeps last 3 of each kind.

## RELEASE

Artifacts and tools:
- EXE installer (preferred when ISCC.exe present):
  - Name: OptForgeWorker_Setup_v<version>.exe; published to releases/ and storage/releases/.
  - Built by worker/build_installer.ps1; optional code‑sign using signtool; Inno Setup script generated on the fly.
  - Installer writes .env with BASE_URL, INTERNAL_SECRET, WORKER_ID, PULL_INTERVAL_SEC; can optionally install as a service (install_service.ps1) or user autostart.
- Portable ZIP:
  - Name: OptForgeWorker_Portable_v<version>.zip when EXE not produced.
  - Contains Node runtime (if bundled), node_modules, ms-playwright, scripts, README.
- Site ZIP:
  - Produced by tools/ops/make_site_zip.ps1; output in releases/ (name via -OutName); staging folder __site_stage is removed after zip.

Download/update channels:
- api/latest.php — normalized latest.json with absolute URL fields and installer_meta merge (sha256, size, last_modified, publisher, kind). Picks newest EXE by *Worker_Setup*.exe pattern across worker/build, releases, storage/releases.
- api/download_worker.php — streams EXE or ZIP (kind=zip) with Range/ETag/HEAD and metadata headers.
- Worker auto‑update: checks latest.json; prefers EXE when available; temp filename updated to OptForgeWorker_Setup.exe.

External tools used:
- Inno Setup (ISCC.exe) for installer.
- signtool for code signing (optional).
- NSSM or custom scripts for service install (install_service.ps1 references may use native service APIs or 3rd‑party; verify during ops). Note: worker_service.bat may rely on NSSM if chosen; ensure availability in production images.

Potential environment issues:
- Builder machines need Node.js, npx, pkg, Inno Setup (optional), signtool (optional), and internet to prefetch Playwright (or bundle ms-playwright offline).
- Target PCs need VC++ runtime if using pkg‑compiled exe (usually bundled), or a working portable Node if running via .bat.
- SmartScreen warnings if unsigned EXE.

## DB

SQLite schema (created/migrated in config/db.php):
- users(id, mobile UNIQUE, name, role ['admin','agent'], password_hash, active, washeej_token, washeej_sender, whatsapp_message, created_at)
- sessions(id, user_id FK users, token_hash, expires_at, created_at)
- leads(id, phone UNIQUE, name, city, country, created_at, source, created_by_user_id FK users, rating, website, email, gmap_types, source_url, social, category_id, geo_country, geo_region_code, geo_city_id, geo_district_id, geo_confidence)
- assignments(id, lead_id UNIQUE FK leads, agent_id FK users, status, assigned_at)
- settings(key PRIMARY KEY, value)
- washeej_logs(id, lead_id, agent_id, sent_by_user_id, http_code, response, created_at)
- place_cache(id, provider, external_id, phone, name, city, country, updated_at, UNIQUE(provider,external_id))
- search_tiles(tile_key PRIMARY KEY, q, ll, radius_km, provider_order, preview_count, leads_added, updated_at)
- usage_counters(id, day, kind, count, UNIQUE(day,kind))
- categories(id, parent_id, name, created_at)
- category_keywords(id, category_id, keyword, created_at)
- category_rules(id, category_id, target, pattern, match_mode, weight, note, enabled, created_at)
- internal_jobs(id, requested_by_user_id, role, agent_id, query, ll, radius_km, lang, region, status, worker_id, claimed_at, finished_at, result_count, error, created_at, updated_at,
  attempts, lease_expires_at, last_cursor, progress_count, last_progress_at, next_retry_at, last_error, target_count, done_reason)
- internal_workers(id, worker_id UNIQUE, last_seen, info JSON)

Indexes: category/leads, internal_jobs(status,lease_expires_at), etc.

Data stored:
- Leads and classification outputs; assignments when agent jobs; usage counters; cached places; system settings; internal job telemetry and worker registry.

## ADMIN

Admin UI pages (admin/*):
- dashboard.php — KPIs overview; quick glance.
- settings.php — comprehensive settings:
  - Internal server toggle, internal_secret, worker intervals and knobs.
  - Self‑update gate (enable_self_update) and site self‑update card.
  - Job pick order strategies.
  - Classification weights presets; quick reclassify.
  - Maintenance options and system pause/stop.
  - Branding note: brand_name, brand_tagline_ar, brand_tagline_en (fallback to product_name / defaults).
- worker_setup.php — worker download links; newest EXE/ZIP discovery; quick steps for ZIP.
- internal.php — queue management for internal jobs.
- users.php, leads.php, assign.php — CRUD and assignment flows.
- classification.php, categories.php — taxonomy and rules editors.
- health.php, monitor.php, logs.php, geo.php — diagnostics and utility pages.

Current status:
- Settings, worker setup, internal queue, classification rules all operational.
- Health/monitor/logs provide basic visibility; could be extended with charts and SSE.
- Some dev APIs exist (dev_add_job*, dev_enable_internal) meant for controlled environments.

## SECRETS

Where secrets are read:
- INTERNAL_SECRET: settings table (key internal_secret); used in api/pull_job.php, api/heartbeat.php, api/report_results.php.
- Google API keys: settings (google_api_key); used by providers/helpers.
- Washeej configs: settings (washeej_url, washeej_token, washeej_sender, washeej_use_per_agent, washeej_instance_id); used by lib/wh_sender.php and related actions.
- Maintenance Secret: settings(maintenance_secret) for protected ops.

Hardcoded values to consider moving to settings/.env:
- Default URLs in installer code (build_installer.ps1 Inno [Code]: defaults to https://nexus.op-tg.com; safe but should be sourced from latest.json or branding settings for white‑labeling).
- Default worker UI port (127.0.0.1:4499) is fine to keep, but expose override via .env (already available in worker runtime).
- Branding defaults in lib/system.php — already overrideable by settings keys.

Note: PHP reads DB path from config/.env.php (SQLITE_PATH). Ensure this file is populated on deploy.

## RISKS

- Packaging pitfalls:
  - Missing Inno Setup leads to ZIP only; fine, but installers preferred for auto‑service setup.
  - Unsigned EXE triggers SmartScreen; consider code signing for production trust.
  - Playwright browser cache missing on target can cause slow first run or fail offline; builder script attempts prefetch.
- Operational:
  - INTERNAL_SECRET exposure risk if served over HTTP; recommend HTTPS and IP allow‑lists.
  - Self‑update is guarded by enable_self_update; ensure file permissions for deploy.
  - Job starvation or skew if pick strategy misconfigured; fair_query/pow2 mitigate but need monitoring.
- Data quality:
  - Classification relies on rules/keywords; gaps can reduce accuracy until tuned.
  - Geo classification text‑based fallback might be low confidence; needs enrichment for point‑based when lat/lon exists.
- Compatibility:
  - Windows hosts without VC++/dependencies for pkg may need the portable Node path; ensure .bat paths are correct.
  - NSSM/service install availability — verify install_service.ps1 behavior on Server SKUs.
- Security/limits:
  - Rate limits for external providers must be respected; keys stored in settings — ensure rotation procedures.

## ACTION PLAN (2 weeks)

Proposed small PRs with goals, touchpoints, and success criteria.

1) PR: Worker Service & Install Hardening
- Goal: Ensure reliable service install across Win10/Server; bundle NSSM fallback and validate install_service.ps1.
- Files: worker/install_service.ps1, worker/worker_service.bat, worker/README.md.
- Success: Fresh VM can install service and start worker; survives reboot; logs captured.

2) PR: Queue/Endpoints Robustness
- Goal: Improve retry/backoff; fill next_retry_at logic; better error propagation.
- Files: api/pull_job.php, api/report_results.php, config/db.php (indexes/columns), admin/internal.php.
- Success: Jobs stuck get reclaimed; retries capped; metrics show attempts and durations.

3) PR: Diagnostics UI & Metrics
- Goal: Add richer health/monitor (charts, SSE tail of logs); worker list with last_seen and info.
- Files: admin/health.php, admin/monitor.php, api/monitor_stats.php; lib/system.php (worker registry helpers).
- Success: Admin can observe active workers, queue depth, throughput; live updates without manual refresh.

4) PR: Google API Integration Polishing
- Goal: Validate/rotate Google key; tighten daily_details_cap; add caching.
- Files: lib/providers.php, lib/limits.php, admin/settings.php.
- Success: Stable details lookups under cap; graceful errors; cache hits observed.

5) PR: Deploy Pipeline (cpanel) Streamline
- Goal: One‑click site zip → upload → extract/swap; validate self‑update card.
- Files: tools/ops/make_site_zip.ps1, tools/ops/cpanel_*.ps1, api/self_update.php.
- Success: Test instance updated via published ZIP without downtime; audit logs recorded.

6) PR: Rules & Classification Quality
- Goal: Curate categories/keywords/rules and presets; add test fixtures.
- Files: admin/classification.php, assets/classification_full.json, lib/classify.php.
- Success: Improved precision/recall on sample datasets; presets switch easily.

Optional follow‑ups:
- Branding UI to edit brand_name/taglines from settings page directly.
- Harden latest.json origin URLs and validator.

---

## CHANGES
- Added documentation only: `docs/AUDIT.md` (this file).

## TESTS
- N/A (documentation only). See also the Smoke Test in RUNBOOK.

## RUN
- Open the audit in the editor: `docs/AUDIT.md`.
- For security details, see `docs/SECURITY_HMAC.md`.
- For job state details, see `docs/JOBS_LIFECYCLE.md`.
- For smoke testing steps, see `docs/RUNBOOK.md` (Smoke Test).
 - For end-to-end fetch/workers audit and roadmap, see `docs/FETCH_WORKERS_AUDIT.md` and `docs/IMPROVEMENTS_PLAN.md`.

## ROLLBACK
- N/A (documentation only).

Note: After each phase (sprint), update `FETCH_WORKERS_AUDIT.md` (progress log) and `IMPROVEMENTS_PLAN.md` (status/checklists), and cross-link any new incidents in `INCIDENTS.md`.
