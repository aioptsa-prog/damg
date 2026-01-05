# Category Search Rate Limiting

This document describes the per-IP rate limiter applied to `api/category_search.php`.

## Table and window

- Table: `rate_limit`
- Schema:
  ```sql
  CREATE TABLE IF NOT EXISTS rate_limit (
    ip TEXT NOT NULL,
    "key" TEXT NOT NULL,
    window_start INTEGER NOT NULL,   -- epoch minute (floor(time()/60)*60)
    count INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (ip, "key", window_start)
  );
  CREATE INDEX IF NOT EXISTS idx_rate_limit_recent ON rate_limit(window_start);
  ```
- Window: 1 minute (sliding by minute start), keyed by `(ip, "key", window_start)`.

## Limit

- Default limit: 30 requests per minute per IP for `key = 'category_search'`.
- Configurable via settings:
  - `rate_limit_category_search_per_min` (default `30`)
  - `rate_limit_admin_multiplier` (default `2`) — effective limit for admin users = base × multiplier
- On each request, the limiter performs an UPSERT with `RETURNING count`:
  ```sql
  INSERT INTO rate_limit(ip, "key", window_start, count)
  VALUES(:ip, :k, :w, 1)
  ON CONFLICT(ip, "key", window_start)
  DO UPDATE SET count = count + 1
  RETURNING count;
  ```
- If `count > <effective_limit>`, the API returns HTTP 429 with JSON:
  ```json
  {"ok":false, "error":"rate_limited", "limit":<effective_limit>, "window":"1m"}
  ```

## Dev diagnostics

- When `app_env=dev` (in `settings`), the API also emits `X-Rate-Count: <count>` header for easier local testing.

## Testing locally

- Start the local PHP server task (already configured).
- Use the probe script to issue 35 requests in one minute:
  ```powershell
  php tools/rate_limit_probe.php
  ```
- Expected summary (approximate):
  - `ok_2xx` ≈ 30
  - `n429` ≥ 5
  - Sample headers for 200 and 429 responses are included in the output.

## Changing the limit

- The limit is controlled by settings (seeded in `config/db.php`):
  - `rate_limit_category_search_per_min`
  - `rate_limit_admin_multiplier`
- Example (PowerShell):
  ```powershell
  php tools/set_setting.php rate_limit_category_search_per_min 10
  php tools/set_setting.php rate_limit_admin_multiplier 2
  ```

## Notes

- CSRF is still required and enforced before rate limiting.
- The limiter is IP-based with trusted proxy support. When `trusted_proxy_ips` contains the connecting proxy IP, the first IP from `X-Forwarded-For` is used as client IP.
- The migration in `config/db.php` is idempotent and also upgrades legacy `rate_limit` schema when missing the composite primary key.
- Retention: rows older than `ttl_rate_limit_days` (default `2`) are periodically pruned opportunistically; a dedicated retention tool also exists under `tools/ops/retention_purge.php`.

## Observability

- Each 429 increments `usage_counters` with key `rl_category_search_429` (per day).
- A coarse `alert_events` row is emitted every 300 cumulative 429s per day.
- Quick check of the 429 counter:
  ```powershell
  php tools/diag/read_counter.php rl_category_search_429
  ```
