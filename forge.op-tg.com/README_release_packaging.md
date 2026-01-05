# Release Packaging Instructions — Nexus v1.0 Final

This guide documents the final validation and packaging steps.

## Settings (production)
- force_https=1
- security_csrf_auto=1
- rate_limit_basic=1
- per_worker_secret_required=1
- internal_server_enabled=1
- csp_phase1_enforced=1 (after UI scripts verified)
- pagination_enabled=1
- export_max_rows=5000

## Validation suite (run in production over HTTPS)
- php tools/release/preflight.php → PASS
- php tools/monitor/synthetic.php (SYNTH_BASE_URL=https://your-host) → 200
- php tools/release/validate_post_deploy.php → GO
- php tools/tests/leads_acceptance.php (if running locally, disable force_https temporarily or use HTTPS terminator)

## Safe cleanup
- php tools/ops/cleanup_safe_reset.php → writes storage/logs/cleanup.log

## Packaging
Create a zip named nexus_final_build.zip that includes only:
- /api, /lib, /admin, /tools, /config
- Root files: bootstrap.php, config/.env.php, composer.json (if present)
Exclude runtime data:
- /storage, /tests, logs, caches

