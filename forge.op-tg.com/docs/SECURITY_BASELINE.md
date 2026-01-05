# Security Baseline

This deployment enables a hardened baseline:

- HTTPS + HSTS: Strict-Transport-Security sent when HTTPS is detected. Optional force redirect with `force_https=1`.
- Sessions: Secure, HttpOnly, SameSite=Lax; session ID rotates upon login.
- CSP: Enforced allowlist covering current CDNs (JSDelivr, CDNJS, DataTables, jQuery, Fonts).
- CSRF: Auto verification for form POSTs when `security_csrf_auto=1`.
- Worker Auth: HMAC-only via `X-Auth-TS` and `X-Auth-Sign` headers; optional `X-Worker-Secret` per worker when configured; legacy secret disabled.
- Replay Guard: Requests are deduplicated by (worker_id, ts, body_sha, method, path) within a time window; replays return HTTP 409.
- Rate Limiting: Basic per-IP+path using `rate_limit` table; returns HTTP 429 with `Retry-After: 60` when exceeded.
- Release Integrity: `api/download_worker.php` validates `size` and `sha256` from metadata; mismatches return HTTP 412.

See `docs/CONFIG_REFERENCE.md` for all related settings.
