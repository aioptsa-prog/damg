# API Catalog (evidence-backed)

This document lists core endpoints with auth requirements and I/O, with pointers to source lines for verification.

- /api/heartbeat.php (POST)
  - Auth: Worker HMAC (X-Auth-TS, X-Auth-Sign, X-Worker-Id), replay guard
  - Output: { ok, time, stopped, pause{enabled,start,end} }
  - Evidence: api/heartbeat.php
- /api/pull_job.php (GET)
  - Auth: Worker HMAC; circuit breaker; replay guard
  - Input: lease_sec (query)
  - Output: { job|null, lease_expires_at, lease_sec }
  - Evidence: api/pull_job.php
- /api/report_results.php (POST)
  - Auth: Worker HMAC; replay guard; idempotency key supported
  - Input: { job_id, items[], cursor, done?, attempt_id?, idempotency_key?, extend_lease_sec?, error?, log? }
  - Output: { ok, added, duplicates, lease_expires_at, done, idempotent? }
  - Evidence: api/report_results.php
- /api/latest.php (GET)
  - Public; channel query; returns normalized latest.json with absolute url, ETag
  - Evidence: api/latest.php
- /api/download_worker.php (GET)
  - Public; kind=exe|zip; 412 on integrity mismatch; supports Range/304
  - Evidence: api/download_worker.php
- /api/worker_config.php (GET)
  - Guarded by code in settings; canary rollout by X-Worker-Id hash; per-worker override
  - Evidence: api/worker_config.php
- /api/health.php (GET)
  - DB liveness
  - Evidence: api/health.php
- /api/worker_metrics.php (POST)
  - Auth: Worker HMAC; updates internal_workers row; renews lease
  - Evidence: api/worker_metrics.php
- /api/worker_status.php (GET)
  - Auth: admin session; returns worker row and log tail
  - Evidence: api/worker_status.php
- /api/worker_stream.php (GET)
  - Auth: admin session; Server-Sent Events for live worker status/log tail
  - Evidence: api/worker_stream.php

More endpoints exist (classification, exports, geo, monitor, dev tools, admin operations). These will be appended with the same pattern.

## Classification and Reclassify
- /api/classify_explain.php (POST, admin + CSRF)
  - Input: { id, csrf }
  - Output: { ok, score, category_id?, category_name?, threshold, matched[] }
  - Evidence: api/classify_explain.php
- /api/classify_preview.php (POST, admin + CSRF)
  - Input: JSON fields { name, types, website, email, source_url, city, country, phone, csrf }
  - Output: same as explain
  - Evidence: api/classify_preview.php
- /api/reclassify.php (POST, admin + CSRF)
  - Input: { limit, only_empty, override, csrf }
  - Output: { ok, processed, updated, skipped, remaining? }
  - Evidence: api/reclassify.php
- /api/cron_reclassify.php (GET, maintenance secret)
  - Query: secret, limit, only_empty, override
  - Output: reclassify summary
  - Evidence: api/cron_reclassify.php

## Exports
- /api/export_classification.php (GET, admin + CSRF)
  - Output: JSON taxonomy
  - Evidence: api/export_classification.php
- /api/export_leads.php (GET, auth + CSRF)
  - Output: CSV stream with filters
  - Evidence: api/export_leads.php
- /api/export_leads_excel.php (GET, auth + CSRF)
  - Output: Excel XML
  - Evidence: api/export_leads_excel.php
- /api/export_leads_xlsx.php (GET, auth + CSRF)
  - Output: XLSX archive
  - Evidence: api/export_leads_xlsx.php

## Geo
- /api/geo_admin.php (POST, admin + CSRF)
  - Actions: init, fetch_cities, fetch_districts (Overpass API)
  - Evidence: api/geo_admin.php
- /api/geo_point_city.php (POST, auth + CSRF)
  - Input: { lat,lng | city_name | q+suggest }, csrf
  - Output: { ok, city, region?, source }
  - Evidence: api/geo_point_city.php

## Monitoring
- /api/monitor_stats.php (GET, admin)
  - Output: JSON snapshot
  - Evidence: api/monitor_stats.php
- /api/monitor_events.php (GET, admin)
  - Output: text/event-stream
  - Evidence: api/monitor_events.php

## Operations
- /api/opcache_reset.php (POST, maintenance.flag + X-Internal-Secret)
  - Output: { ok, supported, time }
  - Evidence: api/opcache_reset.php
- /api/self_update.php (POST, admin + CSRF + enabled)
  - Output: { ok } or error messages
  - Evidence: api/self_update.php
- /api/job_action.php (POST, admin + CSRF)
  - Actions: force_requeue, cancel
  - Evidence: api/job_action.php
- /api/import_classification.php (POST, admin + CSRF)
  - Input: json, replace?
  - Evidence: api/import_classification.php
- /api/import_classification_full.php (POST, admin + CSRF)
  - Input: replace?
  - Evidence: api/import_classification_full.php

## Worker tooling
- /api/worker_env.php (GET, admin)
  - Output: text/plain .env template
  - Evidence: api/worker_env.php

## Dev-only helpers
- /api/dev_add_job.php, /api/dev_add_job_noauth.php, /api/dev_enable_internal.php (GET, local-only)
  - Purpose: development convenience only; guarded by source checks for 127.0.0.1
  - Evidence: api/dev_*.php
