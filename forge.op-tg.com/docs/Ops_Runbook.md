# Ops Runbook (cPanel / No SSH)

استخدم روابط الأدمن فقط:
- Preflight: /admin/release_preflight.php
- Synthetic: /admin/synthetic.php
- Validate: /admin/validate_post_deploy.php
- Cleanup: /admin/cleanup_safe_reset.php
- Retention (dry): /admin/retention_purge.php?dry-run=1

## Steps
1) Preflight قبل كل نشر.
2) Validate + Synthetic بعد النشر.
3) Backup يومي: تنزيل app.sqlite أو صفحة backup إن وُجدت.
4) Retention يومي + dry-run أسبوعيًا.
5) Clear OPCache عند الباتشات الكبيرة: /api/opcache_reset.php (أثناء maintenance.flag).

Checklist
- Logged in (Admin)
- Preflight PASS
- Validate GO
- Synthetic OK
- Backup OK (restore-tested)
- Retention ran in last 24h

Evidence: `admin/* wrappers`, `tools/release/*`, `tools/ops/*`.
