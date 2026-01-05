# Release Readiness

## Status
- Health: OK
- Preflight: PASS
- Synthetic: OK
- Validate: GO
- Security: CSP Phase-1, HMAC+Replay, CSRF, RateLimit

## Checklist
1) force_https=1 + HSTS
2) security_csrf_auto=1
3) rate_limit_basic=1
4) per_worker_secret_required=1
5) ENV internal_secret + rotation
6) Admin wrappers only (no /tools)
7) Retention daily + logs
8) Backup daily + restore-tested
9) External monitoring + webhook
10) Rollback plan tested

SLOs
| Metric | Target |
|---|---|
| API p95 | < 600ms |
| Dashboard p95 | < 800ms |
| Worker Pull success | > 99.5% |
| Error budget | < 0.5% |

Evidence: `.htaccess`, `admin/*`, `tools/validate_post_deploy.php`.
