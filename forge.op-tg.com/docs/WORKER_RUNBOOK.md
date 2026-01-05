## تسجيل الدخول — إصلاح فوري وتبديل سياسة الوصول

وصف المشكلة: بعد إدخال بيانات الدخول تظهر رسالة النجاح لكن لا تُعرض لوحة التحكم إلا بعد تحديث يدوي. السبب غالبًا عدم تنفيذ إعادة توجيه نظيفة بعد تهيئة الجلسة، أو مشاكل cache/headers.

ما تم تطبيقه:
- منع الكاش على صفحة الدخول عبر Cache-Control/Pragma.
- إعادة توجيه 303 فور نجاح المصادقة بعد session_regenerate_id.
- دعم نمطين للدخول: السوبر أدمن (اسم مستخدم + كلمة سر) والموظفين (رقم جوال + كلمة سر).
- حقول قاعدة البيانات اختيارية: users.username و users.is_superadmin مع فهرس فريد.
- قفل محاولات فاشلة بسيط: 5 محاولات لكل IP/هوية خلال 10 دقائق.

SQL (هويّنة، آمنة للتكرار):
- تُطبق تلقائيًا عبر `config/db.php` على أول تشغيل؛ لا حاجة لأوامر يدوية.

تهيئة السوبر أدمن:
- شغّل مرة واحدة:

```powershell
php tools/seed_admin.php
```

- المستخدم/السر الافتراضي: admin / @OpTarget20#30 — غيّر كلمة السر بعد أول دخول.

قبول المهمة (Acceptance):
- بيانات صحيحة تؤدي لتحويل فوري إلى `/admin/` بدون تحديث يدوي.
- السوبر أدمن يدخل عبر تبويب "دخول السوبر أدمن" باسم المستخدم.
- الموظف يدخل كما كان برقم الجوال.
- صفحات الإدارة تبدأ بالتحقق من الجلسة.

# Worker Service Runbook — NSSM (Node + Playwright)

This runbook explains how to run the Windows worker as a stable service using NSSM, without relying on a packaged EXE. The service runs `node worker\index.js` and reads configuration from `worker/.env`.

## Prerequisites

- Windows 10/11 or Windows Server 2016+ with PowerShell.
- Node.js available via one of:
  - Bundled portable Node at `worker\node\node.exe` (preferred), or
  - System Node in PATH (`node --version`).
- NSSM available:
  - Put `nssm.exe` in PATH, or
  - Place it under `tools\nssm\win64\nssm.exe` (or `win32` accordingly). See https://nssm.cc/download.
- Playwright browsers pre-fetched (optional but recommended): the repo typically bundles `worker\ms-playwright` from build time. Otherwise first run may download them.

## Configuration

Edit `worker/.env` (create if missing) and set at minimum:

- BASE_URL=https://your-domain/LeadsMembershipPRO
- INTERNAL_SECRET=your-strong-secret
- WORKER_ID=pc-01
- PULL_INTERVAL_SEC=30
- HEADLESS=true

Other knobs (optional): MAX_PAGES, LEASE_SEC, REPORT_BATCH_SIZE, REPORT_EVERY_MS, ITEM_DELAY_MS, CHROME_EXE, CHROME_ARGS.

## Install / Update / Remove

All commands run from the repo root.

- Install (idempotent):
  powershell -ExecutionPolicy Bypass -File ops\install_worker.ps1 -ServiceName OptForgeWorker -Install

- Update service config (after editing .env or updating Node path):
  powershell -ExecutionPolicy Bypass -File ops\install_worker.ps1 -ServiceName OptForgeWorker -Update

- Remove service:
  powershell -ExecutionPolicy Bypass -File ops\install_worker.ps1 -ServiceName OptForgeWorker -Remove

Optional parameters:
- -InstallPath <path>  # default: repo root
- -NodeExe <path>      # default: worker\node\node.exe if present, else system node

## Logs and rotation

- Service logs: storage\logs\worker\service.log (rotates automatically at ~10MB, keeps ~7 archives).
- Worker app logs: worker\logs\worker-YYYY-MM-DD.log
- Worker stats: worker\logs\stats.json

If `service.log` grows large, the script rotates it on install/update. NSSM also rotates stdout/stderr by size.

## Verification

- After install, check service status in Services.msc or via:
  sc query OptForgeWorker

- Verify log is being written:
  Get-Content -Path storage\logs\worker\service.log -Tail 50 -Wait

- Local status UI (worker opens it automatically on first run):
  http://127.0.0.1:4499/status
  - الصفحة حية: تُحمِّل SSR أولية ثم `status.js` الذي يشترك في SSE ويعاود الاستطلاع عند الفشل. عداد زمن التشغيل (uptime) يزيد محليًا كل ثانية.
  - في حال انقطاع البث، تظهر رسالة وسيُعاد المحاولة تلقائيًا.

## Troubleshooting

- NSSM not found: install NSSM and ensure `nssm.exe` in PATH or under `tools\nssm\win64`.
- Node not found: either bundle portable Node in `worker\node\node.exe` or install Node and ensure `node` is in PATH. You can also pass `-NodeExe`.
- Playwright missing: run from repo root to cache browsers (optional):
  powershell -ExecutionPolicy Bypass -Command "Push-Location worker; npx playwright install chromium --with-deps; Pop-Location"
- INTERNAL_SECRET mismatch: confirm Admin → Settings has the same secret and that the Internal Server is enabled.
- BASE_URL incorrect: ensure it points to your deployed PHP app root.
 - Circuit breaker (قاطع): إذا كان سحب الوظائف يفشل بـ 429 `cb_open`، تحقق من صفحة «العمال» في الإدارة — زر «إغلاق القاطع» سيعيد السماح للعامل بسحب مهام جديدة.
 - بث السجلات لا يظهر: شبكة الشركة قد تحجب SSE. افتح صفحة البث الحي من الإدارة؛ إن لم يعمل، سيستخدم النظام الاستطلاع كل 5 ثوانٍ تلقائيًا.

## Notes

- The worker reads .env at startup. Use the `-Update` action after editing .env to refresh the service environment and restart the service.
- The service runs as LocalSystem by default in the script. Adjust if you need user profile access.
- On Server SKUs, SmartScreen prompts do not apply when running via Node (no EXE).
 - عند استخدام "إيقاف مؤقت" من واجهة العامل، يبقى الاتصال قائمًا وتستمر نبضات الصحة؛ لن تُسحب مهام جديدة حتى الاستئناف.
