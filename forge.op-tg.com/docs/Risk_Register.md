# Risk Register

| ID | Risk | Prob | Impact | Mitigation | Owner | ETA |
|---|---|---|---|---|---|---|
| R1 | SQLite lock contention | H | H | Batch jobs, tuned indexes, consider Postgres later | Backend | 4w |
| R2 | Secrets in DB | M | C | ENV + rotation + per-worker-secret | SecOps | 24h |
| R3 | External monitoring absent | H | M | Uptime + webhook | Ops | 24h |
| R4 | Wrong tool links (/tools/) | M | M | Use /admin wrappers only; update dashboard links | Ops | 1d |
| R5 | CSP Phase-1 only | M | M | Phase-2 migration; remove unsafe-inline | SecOps | 5d |
| R6 | Backup not automated | M | C | Daily backup + weekly restore test | Ops | 2d |
| R7 | Retention not scheduled | M | M | Daily purge + weekly dry-run report | Ops | 1d |

Evidence: `.htaccess`, `admin/*`, `tools/*`.
