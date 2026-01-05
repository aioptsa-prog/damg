# Operations

## Canary rollout policy
- Channel selection order:
  1) Per-worker override `worker_channel_overrides_json`
  2) Canary rollout percent `rollout_canary_percent`: compute bucket = sha1(worker_id)[0..7] mod 100. If bucket < percent → channel=canary.
  3) Default channel `worker_update_channel` (stable/canary/beta)

## Admin controls
- `admin/update_channel.php`: set default channel, canary percent, and per-worker overrides.
- `admin/rollback.php` (superadmin): revert to previous channel and reset canary.

## Synthetic monitoring
- Run `php tools/monitor/synthetic.php` periodically (cron). Logs JSON lines in `storage/logs/synthetic.log`.
- Dashboard reads last p95 and OK/FAIL state.

## Backup
- `php tools/backup/run_release_backup.php` creates a gzip snapshot under `storage/backups/` and prints JSON with path and sha256.

## Data retention and purge

Operational tables can grow quickly. Schedule a cleanup job:

- hmac_replay: 7 days (replay window + buffer)
- rate_limit: 2 days (rolling windows)
- job_attempts: 90 days (auditability)
- dead_letter_jobs: 90 days (triage horizon)

Script: `tools/ops/retention_purge.php`

Modes:
- Dry run: `php tools/ops/retention_purge.php --dry-run`
- Execute: `php tools/ops/retention_purge.php`

Environment overrides (days): `RET_TTL_REPLAY_DAYS`, `RET_TTL_RATELIMIT_DAYS`, `RET_TTL_JOB_ATTEMPTS_DAYS`, `RET_TTL_DLQ_DAYS`
Log rotation threshold (bytes): `RET_ROTATE_BYTES` (default 10MB)

Windows Task Scheduler (example):
- Program/script: php
- Arguments: `tools/ops/retention_purge.php`
- Start in: repo root
- Schedule: Daily at 03:15

Linux cron (example):
`15 3 * * * cd /srv/nexus && php tools/ops/retention_purge.php >> storage/logs/ops.log 2>&1`
