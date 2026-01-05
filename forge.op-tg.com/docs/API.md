# API Reference (Overview)

This document provides a human-readable overview of key API endpoints. For a structured specification, see OpenAPI: `docs/API.yaml`.

## Auth & Security
- Admin endpoints require an authenticated session (cookie) and CSRF token on state-changing requests.
- Worker endpoints use HMAC authentication (see ingestion docs).

## Endpoints (Selected)

### Category Search — `GET /api/category_search.php`
- Query params: `q` (string, required), `limit` (int, optional), `active_only` (0/1), `csrf` (required for admin-only enforcement)
- Response: JSON list of categories with path and icon metadata
- Notes: Rate-limited; admin-only

### Multi-Location Group Create — `POST /api/jobs_multi_create.php`
- Body (JSON):
  - `csrf` (string, required)
  - `category_id` (int, required)
  - `base_query` (string)
  - `multi_search` (bool)
  - `locations` (array of { ll: "lat,lng", radius_km: int, city: string })
  - `target_count` (int, optional)
- Response: `{ ok, job_group_id, jobs_created_total, per_location: [...] }`

### Ingestion Results — `POST /api/report_results.php`
- Headers: HMAC auth headers (X-Auth-TS, X-Auth-Sign)
- Body (JSON): `{ job_id, items[], cursor?, done?, extend_lease_sec?, idempotency_key?, attempt_id? }`
- Response: `{ ok, added, duplicates, lease_expires_at, done }`
- Notes: Idempotent updates when key repeats; replay detection guards enabled.

### Geo Point/City — `POST /api/geo_point_city.php`
- Body (JSON): One of
  - `{ suggest: true, q, csrf }` → city suggestions
  - `{ city_name, csrf }` → resolve name to lat/lng
  - `{ lat, lng, strict_city: true, csrf }` → reverse-geocode to city name
- Response: Suggest list or `{ ok, city }` or `{ ok, lat, lng }`

### Export Leads — `GET /api/export_leads.php`
- Query params: filters (e.g., `job_group_id`), optional others per implementation
- Response: CSV content; includes category columns and `job_group_id` when filtered

### Worker Control (selected)
- `GET /api/heartbeat.php`, `GET /api/pull_job.php`, `GET /api/worker_status.php`, `GET /api/worker_stream.php`, `GET /api/worker_config.php`, `GET /api/download_worker.php`, `GET /api/latest.php`

## HMAC Authentication (Workers)
- Required headers: X-Auth-TS (unix timestamp), X-Auth-Sign (hex HMAC)
- Signature: upper(METHOD) + '|' + PATH + '|' + sha256(body) + '|' + TS
- Key: settings('internal_secret'); during rotation, 'internal_secret_next' is accepted.

Examples: tools/tests/test_hmac_curl.sh, tools/tests/test_hmac_windows.ps1

## Admin Circuit Breaker
- POST /admin/workers_cb_toggle.php — form fields: csrf, id (worker_id), action (open|close)
- Effect: updates cb_open list; pull_job returns 429 `cb_open` when open for that worker.
