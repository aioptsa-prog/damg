# Operations Runbook

This runbook documents the exact, repeatable steps for deploying releases, rolling back safely, enabling maintenance mode, verifying endpoints, and capturing operational evidence.

IMPORTANT: Two modes
- Local validation (safe preview): run on your workstation or a test VM; no production changes; generates evidence under `storage/logs/validation` and `storage/releases`.
- Production run (nexus.op-tg.com): performs the live swap under maintenance; verify before disabling maintenance.

## Deploy (Preferred: SFTP + WinSCP)

Prereqs
- WinSCP installed and `WinSCP.com` on PATH
- SFTP access to the server (host, user, password or SSH key)
- Server layout: `/home/USER/site/{releases,current}` with the webserver DocumentRoot pointing to `current`

Steps
1) Build a clean web release and regenerate latest.json (so it syncs)
2) Upload to `releases/<yyyyMMdd_HHmmss>` and swap to `current` with maintenance enabled
3) Validate site and endpoints; script automatically disables maintenance after swap

Commands (examples)

Local validation (safe):

```powershell
# Preview deploy actions without connecting (generates WinSCP script transcript)
& tools\deploy\deploy.ps1 -DryRun -Maintenance -LocalPath 'D:\LeadsMembershipPRO' -SshServer 'example.com' -User 'user' -RemoteBase '/home/user/site' -OutputScriptPath 'storage\logs\validation\winscp_dryrun.winscp'

# Start a local PHP server and run HTTP probes; stores results under storage\logs\validation
& tools\ops\validate_local.ps1 -Root 'D:\LeadsMembershipPRO' -BindHost '127.0.0.1' -Port 8091

# Bundle current validation evidence into storage\releases\evidence_*.zip
& tools\ops\make_evidence_bundle.ps1 -Root 'D:\LeadsMembershipPRO'
```

Production (SFTP preferred):

```powershell
# Build, generate latest.json, deploy under maintenance, then verify and auto-disable maintenance
& tools\deploy\release_and_deploy.ps1 -LocalPath 'D:\LeadsMembershipPRO' -SshServer 'nava3.mydnsway.com' -User 'optgccom' -RemoteBase '/home/optgccom/nexus.op-tg.com' -Maintenance

# Or deploy directly (advanced). Add -FixPerms if your host needs chmod after upload
& tools\deploy\deploy.ps1 -LocalPath 'D:\LeadsMembershipPRO' -SshServer 'nava3.mydnsway.com' -User 'optgccom' -RemoteBase '/home/optgccom/nexus.op-tg.com' -Maintenance -FixPerms

# After swap, from your operator machine, run probes against production
& tools\http_probe.ps1 -BaseUrl 'https://nexus.op-tg.com'

# If PHP serves stale code while maintenance is ON, reset OPcache (gated endpoint):
# Call https://nexus.op-tg.com/api/opcache_reset.php with header X-Internal-Secret=<SECRET>
# Only permitted while maintenance.flag exists; remove maintenance after caches are warm.

## Automated Places Queue

Goal: run the Google Places (lean) queue automatically without changing UI or URLs.

Manual run

```powershell
php tools/ops/run_places_queue.php --max 3
```

Flags
- --max: max jobs per run (default 5)
- --window-minutes: only consider jobs created in the last M minutes (optional)
- --dry-run: list candidate jobs and exit

Logs
- storage/logs/ops/places_queue_YYYYMMDD.log (per-run append)
- storage/logs/ops/cron_places.log (for cPanel cron wrapper)
- storage/logs/ops/places_task.log (for Windows Task Scheduler wrapper)

Linux/cPanel (cron)
- Add a cron job in cPanel pointing to the wrapper shell script, for example every 15 minutes:

```
*/15 * * * * /home/USER/site/current/tools/ops/cron_places.sh
```

Windows Task Scheduler
- Use the PowerShell wrapper to install/update/remove a scheduled task that runs every 15 minutes by default:

```powershell
# Install (every 15 minutes, default args)
powershell -ExecutionPolicy Bypass -File ops\schedule_places.ps1 -Action Install -EveryMinutes 15 -PhpPath 'php.exe' -Args 'tools/ops/run_places_queue.php --max 5'

# Update schedule/args later
powershell -ExecutionPolicy Bypass -File ops\schedule_places.ps1 -Action Update -EveryMinutes 10 -Args 'tools/ops/run_places_queue.php --max 3'

# Remove the task
powershell -ExecutionPolicy Bypass -File ops\schedule_places.ps1 -Action Remove

# Verify
SCHTASKS /Query /TN "OptForge-PlacesQueue"
Get-ScheduledTask -TaskName 'OptForge-PlacesQueue' | Format-List *
```

Reading results and handling failures
- The queue runner prints a summary per job: pages, inserted, updated, deduped, batch_id.
- On failure, exit code is non-zero and errors are logged; the job remains queued for future retries as per attempts/backoff.
- For bad batches, use the rollback tool by batch_id:

```powershell
php tools/ops/rollback_batch.php --batch <batch_id> --dry-run
php tools/ops/rollback_batch.php --batch <batch_id> -y
```

```

One-shot production capture (recommended):

```powershell
# Runs SFTP deploy with BEFORE/AFTER dir listing, runs production probes, and bundles evidence.
& tools\ops\production_evidence.ps1 `
  -LocalPath 'D:\LeadsMembershipPRO' `
  -SshServer 'nava3.mydnsway.com' -Port 22 -User 'optgccom' `
  -RemoteBase '/home/optgccom/nexus.op-tg.com' `
  -BaseUrl 'https://nexus.op-tg.com' `
  -PrivateKeyPath 'C:\path\to\id_rsa.ppk' `
  -FixPerms `
  -LeaveMaintenance `
  -Label 'prod'
```

Flags reference (SFTP):
- -CaptureDirState: include BEFORE/AFTER listings for releases/ and current/
- -PrivateKeyPath: use key-based auth with WinSCP session
- -LeaveMaintenance: keep maintenance.flag present so you can probe/warm caches before serving traffic

Rollback (Production):

```powershell
& tools\deploy\deploy.ps1 -LocalPath 'D:\LeadsMembershipPRO' -SshServer 'nava3.mydnsway.com' -User 'optgccom' -RemoteBase '/home/optgccom/nexus.op-tg.com' -Rollback
```

Notes
- Use -FixPerms only if your host requires chmod post-deploy so the webserver can read new files.
- Only call /api/opcache_reset.php during maintenance and with X-Internal-Secret; never expose this secret in client-side code.
- Keep at least 2–3 releases in /releases; scripts prune older ones automatically.

Housekeeping performed before go-live
- تم تفريغ “جدول الأرقام فقط” (numbers/phones/phone_numbers إن وُجد) بشكل آمن بدون المساس بباقي الجداول.

## Deploy (Fallback: cPanel UAPI with API Token)

Use when SFTP is blocked.
- Create a cPanel API Token (cPanel Home → Security → Manage API Tokens)
- The script uses header: `Authorization: cpanel <USER>:<TOKEN>`
- Flow: upload release zip → extract to `releases/<ts>` → swap to `current` → remove maintenance
- Note: Some hosts restrict Terminal/execute_command; if so, we can perform the move via Fileman operations instead of a shell command.

Example (token created in cPanel → Security → Manage API Tokens):

```powershell
& tools\deploy\deploy_cpanel_uapi.ps1 -LocalPath 'D:\LeadsMembershipPRO' -CpanelHost 'nexus.op-tg.com' -CpanelUser 'optgccom' -ApiToken '<TOKEN>' -DocrootBase '/home/optgccom/nexus.op-tg.com' -Maintenance
```

## Maintenance mode

- Maintenance is controlled by a `maintenance.flag` file at the site root.
- `.htaccess` and `maintenance.html` are included in releases; when the flag exists, users see the friendly maintenance page.
- Both deploy paths toggle maintenance before swap and remove it after.

## Verify endpoints (HTTP probe)

Use `tools/http_probe.ps1` (adjust BaseUrl for production) to validate:
- `/api/latest.php`: ETag + Last-Modified + Cache-Control; returns 304 with If-None-Match
- `/api/download_worker.php`: HEAD and Range (206) support with correct headers

Production probe example:

```powershell
& tools\http_probe.ps1 -BaseUrl 'https://nexus.op-tg.com'
```

## Preflight & Scheduling

### Go-live preflight (CLI)
يشخّص إعدادات الجاهزية ويعدّ تقريرًا JSON:

```powershell
php tools\ops\go_live_preflight.php
```

### Fix stuck jobs (اختياري)
إن ظهرت وظائف عالقة، أعدها للطابور:

```powershell
php tools\ops\fix_stuck_jobs.php
```

### Scheduling alerts (Windows)
تثبيت مهمة مجدولة لتشغيل alerts_tick.php كل 5 دقائق:

```powershell
powershell -ExecutionPolicy Bypass -File tools\ops\schedule_alerts.ps1 -Action Install -EveryMinutes 5
```
المتكاملات المدعومة للويبهوك:
- Slack: ضع Incoming Webhook URL (يُرسل نصًا بسيطًا في الحقل text)
- Discord: Webhook URL (يُرسل في الحقل content)
- Microsoft Teams: Office 365 Connector Webhook (MessageCard)
وإلا فسيتم إرسال JSON عام: { ok:false, alerts:[..], ts:".." } إلى أي مستقبل مخصص.

بديل Slack (المنصة الجديدة):
- يمكنك استخدام صلاحية Slack App Token ونحدد القناة. فعّل من الإعدادات: Slack Token وSlack Channel.
- السكربت سيستدعي chat.postMessage تلقائيًا مع نص ملخص.

### Scheduling log rotation (Windows)
لضمان تدوير السجلات يوميًا (سجلات التطبيق والعامل)، ثبت مهمة مجدولة:

```powershell
powershell -ExecutionPolicy Bypass -File tools\ops\schedule_rotate_logs.ps1 -Action Install -EveryDays 1 -PhpPath 'php.exe' -PhpArgs 'tools/rotate_logs.php --max-size=25 --max-days=14'
```
يمكن تعديل الحد الأقصى للحجم بالأميغابايت (--max-size) ومدة الاحتفاظ بالأيام (--max-days). يدعم السكربت العمل على Windows PowerShell 5.1 باستخدام schtasks داخليًا.

## DLQ, Circuit Breaker, and Alerts

### Dead-letter Queue (DLQ)
- صفحة الإدارة: `admin/dlq.php` لعرض العناصر، إعادة الجدولة إلى الطابور، أو الحذف النهائي.
- تُضاف السجلات تلقائيًا عندما تستنفد المهمة محاولاتها (MAX_ATTEMPTS_DEFAULT/backoff).

### Circuit Breaker (قاطع للعامل)
- من `admin/workers.php` استخدم زر “فتح/إغلاق القاطع” لعامل محدد لمنع `pull_job` مؤقتًا.
- مفيد عند وجود أعطال متتابعة من عامل معيّن لحين إصلاحه.

### Alerts (تنبيهات)
- سكربت: `tools/ops/alerts_tick.php` يُشغَّل دوريًا لإرسال Webhook/Email عند:
  - عمال Offline
  - وجود عناصر في DLQ
  - وظائف عالقة (Lease منتهي)
- إعدادات: `alert_webhook_url`, `alert_email`.

## Worker update evidence

- Worker update logs (on worker machine):
  - `storage/logs/update-worker.log`
- Confirm `storage/releases/installer_meta.json` exists and `latest.json` fields (version, sha256, size) match the EXE.

## Geo acceptance (Saudi Arabia)

- Import the full dataset:
  - Run `python tools/geo/sa_import.py --regions storage/data/geo/sa/regions.csv --cities storage/data/geo/sa/cities.csv --districts storage/data/geo/sa/districts.csv`
- Generate hierarchy:
  - `php tools/geo/gen_hierarchy.php`
- Run acceptance:
  - `php tools/geo/acceptance_test.php` (requires ≥100 city points)
- Targets: ≥98% accuracy; p50 ≤ 50 ms
- Save the JSON output to `storage/data/geo/sa/acceptance_results.json` in production.

## Rollback decision tree

- Swap error during deploy: deploy script auto-restores previous `current` and exits non-zero
- Post-deploy validation fails: run `-Rollback` immediately, investigate, then redeploy a fixed build
- Keep maintenance on during critical incidents; disable once stable

## Monitoring & screenshots

- في لوحة التحكم (للمشرف):
  - إعداد عامل ويندوز: إصدار العامل وبياناته.
  - التشخيصات: ملخص الإعدادات، صحة الوحدات الطرفية، الوظائف الحديثة، دفعات الأماكن.
    - قراءة صحة الـWorkers: تحقق من “متصل/غير متصل”، آخر ظهور، الإصدار.
    - متابعة الوظائف: استخدم التصفية حسب الحالة وشاهد أوقات التحديث والإنهاء.
    - تصدير CSV للدُفعات الصغيرة مباشرة من جدول “دفعات الأماكن”.
    - ملاحظة: الدُفعات الكبيرة (>50k) ستُدعم عبر CLI لاحقًا (TODO).
  - مراقبة وGeo حسب الحاجة.
  - التقط لقطات شاشة للأدلة التشغيلية.
  - مراقبة جودة الإدراج: تحقق من شارة Dup ratio (24h) في لوحة المراقبة. مرّر مؤشر الفأرة لإظهار added/duplicates.

### Local ingestion probe (evidence)
لاختبار مسار الإدراج بسرعة بدون عامل أو خادم HTTP، استخدم أداة CLI:

```powershell
php tools\ops\ingest_probe.php
```

الناتج يُظهر added/duplicates لكل تشغيل، ويحدّث usage_counters لليوم. بعد التشغيل، يُفترض أن تظهر شارة Dup ratio (24h) في لوحة المراقبة.

## Security notes

- Prefer SSH keys for SFTP; if using passwords, pass via PSCredential
- For cPanel, prefer API Tokens over Basic auth
- Keep secrets out of git; never commit credentials

## Troubleshooting
### فحص العامل والنظام (CLI)
- نبضات: php tools/ops/probe_heartbeat.php — يطبع ok:true عند النجاح.
- سحب مهمة: php tools/diag/probe_pull_job.php — يطبع URL والشفرة والرد.
إن فشل الطلب:
- تأكد من internal_server_enabled=1، internal_secret مضبوط، وHMAC يعمل عند تزامن الوقت.
- افحص storage/logs و worker/logs.

## Smoke Test (End-to-End)

Use this after configuring INTERNAL_SECRET and ensuring time sync to validate a full worker ↔ server roundtrip.

Prereqs
- INTERNAL_SECRET set in Admin → Settings (and worker/.env matches)
- Worker running (service or console)
- System time roughly in sync (±5 minutes)

Run (Windows)

```powershell
powershell -ExecutionPolicy Bypass -File tools\smoke_test.ps1
```

Run (Linux/macOS)

```bash
chmod +x tools/smoke_test.sh
TIMEOUT_SEC=120 POLL_EVERY=5 ./tools/smoke_test.sh
```

Behavior
- Enqueues a small internal job via CLI only script `tools/ops/enqueue_sample.php`
- Waits up to 120s for status to become processing/done
- Prints PASS/FAIL and relevant log paths

Logs
- storage\logs\worker\service.log (if service is installed)
- job_attempts table rows for the sample job

If FAIL
- Check worker/.env: BASE_URL, INTERNAL_SECRET, WORKER_ID
- Confirm Admin → Settings: internal_server_enabled=1
- Verify HMAC time window: run `w32tm /query /status` then `w32tm /resync`
- See worker local UI on http://127.0.0.1:4499/status

### خريطة صفحات الجلب (Smoke Test)
هدف: التأكد من أن الخرائط تعمل بعد أي نشر.

خطوات:
1) افتح `/admin/fetch.php` و`/agent/fetch.php`.
2) تأكد من ظهور عنصر الخريطة بارتفاع مناسب (≥ 320px).
3) راقب السطر أسفل الخريطة:
  - يظهر: "يتم تحميل الخرائط من: …" ثم "تم التحميل من المصدر …".
  - إن ظهرت رسائل انتقال متكرر، حدّث `tile_sources_json` لمصدر مسموح.
4) اختبر التفاعل: تكبير/تصغير/سحب، إضافة Marker عبر نقرة.
5) أعد تحميل الصفحة وتأكد من الاستمرارية.

### تصدير دفعات كبيرة عبر CLI
عند تجاوز الدُفعة 50,000 صف، استخدم أداة CLI لتجنب حدود HTTP:

```powershell
php tools/export_batch.php --batch <batch_id> --out storage/exports/places_<batch_id>.csv
```

الملاحظات:
- UTF-8 مع BOM؛ تفريغ دوري لتقليل الذاكرة.
- الأعمدة: place_id,name,phone,address,lat,lng,website,types_json,source,source_url,collected_at,last_seen_at,batch_id.
- لا تطبع الأسرار أو الرموز.

- If pruning fails (missing `bash`), replace cleanup line with a host-supported approach (e.g., `find` or Fileman calls)
- Export big batches via CLI

When a Places batch exceeds 50,000 rows, export it via CLI to avoid HTTP 413 from the inline exporter.

```powershell
php tools/export_batch.php --batch <batch_id> --out storage/exports/places_<batch_id>.csv
```

Notes
- Streamed UTF-8 with BOM, periodic flush to keep memory low.
- Columns match the UI exporter: place_id,name,phone,address,lat,lng,website,types_json,source,source_url,collected_at,last_seen_at,batch_id.
- No secrets or tokens are printed in the output.
- If the swap path is not a symlink but a directory, the current commands still perform directory renames safely
- If `latest.json` looks stale after deploy, ensure you regenerated it before running the deploy sync
