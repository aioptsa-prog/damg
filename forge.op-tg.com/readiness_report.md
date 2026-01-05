# Forge SotwSora — Production Readiness Report

Date: 2025-10-28
Target domain: `https://forge.sotwsora.net`

## Platform Status

- **API & Workers** — ✅ HMAC auth, replay guard, and automatic lease recovery (`api/pull_job.php`, `api/report_results.php`, `lib/security.php`)
- **Admin/UI** — ✅ Role-gated dashboards with CSRF enforcement and HTTPS redirects (`lib/auth.php`, `lib/system.php`, `admin/*`)
- **Leads pipeline** — ✅ Idempotent ingest, dedupe, multi-location support (`api/report_results.php`, `api/jobs_multi_create.php`)
- **Monitoring & tooling** — ✅ Diagnostics and failover sims in `tools/diag/*`, `tools/tests/worker_failover_sim.php`

## Security & Configuration

| Setting | Value |
| --- | --- |
| force_https | 1 |
| security_csrf_auto | 1 |
| internal_server_enabled | 1 |
| internal_secret | forge-bf63d94131407eab74bec55436eda0f1 |
| worker_base_url | https://forge.sotwsora.net |
| per_worker_secret_required | 0 (HMAC-only) |
| workers_online_window_sec | 120 |
| maintenance_secret | set |

- Admin credentials: `mobile 590000000 / username forge-admin / password Forge@2025!`
- Agent credential: `mobile 590000001 / username forge-agent / password Forge@2025!`
- Internal worker secret is generated above; share securely with terminal units.

## Database Snapshot

- Schema rebuilt from `config/db.php`; fresh `storage/app.sqlite` (≈10 MB) with taxonomy seed applied `seed-20251028165433-9c2859`.
- Operational tables (`internal_jobs`, `job_attempts`, `leads`, `assignments`, `sessions`, `internal_workers`, `alert_events`, `rate_limit`, `hmac_replay`, etc.) emptied and vacuumed.
- Diagnostics/log storage cleared: `storage/logs/*`, `storage/tmp/*`, and legacy backups removed.

## Validation

- `php tools/tests/leads_acceptance.php` → PASS
- `php tools/diag/dump_job_state.php` → no queued/processing jobs
- `php tools/diag/dump_workers.php` → no worker rows
- `php tools/db_query_stuck.php` → empty

## Release Assets

- New automation script: `tools/ops/prepare_release.php` (idempotent release prep + credential output)
- User registry snapshot tool: `tools/diag/list_users.php`
- Diagnostics suite retained under `tools/diag/`

## Next Steps

1. Deploy codebase and `storage/app.sqlite` to the forge.sotwsora.net host (ensure directories under `storage/` are writable).
2. Update DNS / TLS so that the public origin responds at `https://forge.sotwsora.net`.
3. Distribute the credentials and `internal_secret` securely; rotate via `tools/ops/prepare_release.php` if regeneration is required.
4. Launch terminal units with BASE_URL=`https://forge.sotwsora.net` and INTERNAL_SECRET from above; run `tools/tests/worker_failover_sim.php` in staging before production automation.

✅ The project is clean, configured, and production-ready for immediate deployment.
