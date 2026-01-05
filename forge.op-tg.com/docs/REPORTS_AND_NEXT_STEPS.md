# ملخص التقارير والخطوات التالية (تاريخ: 2025-10-13)

## 1) ملخص تنفيذي
- تم إنجاز أساسات الاعتمادية في Sprint 0: Claim ذري بمحاولة/Lease، Backoff أُسّي + Jitter، Idempotency، تطبيع الهاتف، Dedup ببصمة فريدة، Telemetry (added/duplicates, dup ratio)، تحديثات في لوحة المراقبة، وأدوات أدلة CLI.
- الحِزم الطرفية جاهزة لتجربة Pilot صغيرة (≤ 5 عمال) بتركيب ذاتي (Node + Chromium مضمّنَين) مع تحديث مركزي للتهيئة.
- غير جاهز للتوسع العريض قبل إغلاق: قناة تحديثات مرحلية (canary → rollout → rollback)، أسرار لكل عامل + تدوير، Circuit Breaker + DLQ، وتنبيهات أساسية.

## 2) التقارير والأدلة المرتبطة
- خطة التحسين: docs/IMPROVEMENTS_PLAN.md (محدّثة لحالة Sprint 0)
- الجاهزية للإطلاق: docs/PRODUCTION_READINESS.md (Go/No-Go + الفجوات)
- أدلة التنفيذ: docs/EVIDENCE_CHECKLIST.md و docs/EVIDENCE_NOTES.md
- واجهات وخدمات: docs/API.md، docs/RUNBOOK.md، docs/SYSTEM_OVERVIEW.md
- سجّل التغييرات: docs/CHANGELOG.md (1.4.2)
- أدوات الأدلة:
  - tools/ops/ingest_probe.php → ينتج مخرجات added/duplicates
  - tools/ops/capture_ingestion_evidence.php → يحفظ ingestion_probe.json و usage_counters_today.json تحت storage/logs/validation

## 3) مراجعة وتحليل وتدقيق (نِقاط تحقق)
- Queue/Jobs
  - [مُتحقق] Claim ذري بمحاولة attempt_id وLease؛ سجلات job_attempts؛ فهارس retry
  - [مُتحقق] Backoff + Jitter + next_retry_at بإعدادات قابلة للتهيئة
  - [مُتحقق] Idempotency في report_results مع تمديد Lease عند التسليم
- Ingestion
  - [مُتحقق] تطبيع الهاتف (SA 966) + phone_norm
  - [مُتحقق] بصمة dedup (sha1) + leads_fingerprints UNIQUE; عداد duplicates
  - [مُتحقق] Telemetry: added/duplicates + dup ratio 24h + ترند 7 أيام (API + UI)
- Monitoring/UI
  - [مُتحقق] شارات dup ratio في admin/monitor و admin/monitor_workers
  - [مُتحقق] SSE المُوسّع في api/monitor_events.php
- Tooling/Docs
  - [مُتحقق] CLI probes + دليل تشغيل في RUNBOOK وAPI
  - [مُتحقق] CHANGELOG محدث؛ أدلة مضافة إلى EVIDENCE_*
- Workers
  - [مُتحقق] حِزمة ذاتية (Node + Chromium)؛ latest.json؛ download endpoint؛ worker_config API

ملاحظات: تم التحقق عبر php -l، تجارب CLI، ومخرجات التخزين تحت storage/logs/validation.

## 4) الخطوات التالية (بدقة مع معايير قبول)
1) أسرار لكل عامل + تدوير تدريجي
   - النواتج: أعمدة جديدة في internal_workers (secret, rotating_to, rotated_at, rate_limit_per_min)؛ التحقق في APIs (pull_job/report_results/heartbeat)؛ واجهة إدارة لتوليد/تدوير السر مع سجل تدقيق.
   - معايير القبول: عامل قديم يعمل خلال نافذة سماح؛ الطلبات ترفض بسر خاطئ؛ سجل تدقيق لكل عملية تدوير.
   - الأدلة: لقطة شاشة واجهة الإدارة؛ سجلات رفض/قبول؛ إدخالات audit.
2) قناة تحديثات مرحلية (Canary → Rollout → Rollback)
   - النواتج: admin/worker_updates.php (رفع → اختبار ذاتي → اختيار قناة/فئة → Canary 1–2 عمال → مراقبة → تعميم → تراجع)؛ latest.json لكل قناة؛ توثيق توقيع/Checksum.
   - معايير القبول: تحديث ناجح لعامل Canary مع إمكانية Rollback فوري؛ إحصاءات اعتماد عبر لوحة المراقبة.
   - الأدلة: transcripts، latest.json بالقنوات، لقطات.
3) Circuit Breaker + Dead Letter Queue (DLQ)
   - النواتج: مفاتيح CB لكل مزود/عامل؛ فتح تلقائي عند ذروة أخطاء؛ طي تلقائي بعد هدوء؛ جدول DLQ + UI لإعادة الجدولة/الحذف.
   - معايير القبول: حماية من العواصف؛ لا تتجاوز نسبة أخطاء >X% دون كبح؛ DLQ مرئي وقابل للتفريغ.
   - الأدلة: لقطات CB/DLQ؛ عدادات قبل/بعد.
4) تنبيهات أساسية
   - النواتج: تنبيه نبضات (heartbeat) مفقودة، ارتفاع أخطاء/إعادات المحاولة، نمو DLQ، وظائف عالقة؛ قناة بريد/Teams/Slack.
   - معايير القبول: تسليم تنبيه خلال ≤2 دقيقة من الحدث؛ إخماد/تجميع لتفادي الضجيج.
   - الأدلة: سجلات webhook؛ لقطات الإشعارات.
5) حوكمة البيانات والخصوصية
   - النواتج: سياسة احتفاظ؛ إخفاء PII في السجلات؛ خيار dedup عابر للأيام إن طُلب.
   - معايير القبول: اختبارات عينات تُظهر إخفاء PII؛ مهمة تنظيف مجدولة.
6) الضبط والسِعات
   - النواتج: Quotas/Throttling لكل مزود؛ خطة انتقال PostgreSQL (PoC + حدود التحول)؛ ضبط فواصل السحب والحمولات.
   - معايير القبول: عدم تجاوز المعدلات المسموحة؛ وثيقة قرار التحول إلى PostgreSQL.

## 5) جدول زمني مقترح (قابل للتعديل)
- الأسبوع 1: أسرار لكل عامل + تدوير (API + UI + Audit)
- الأسبوع 2–3: قناة تحديثات مرحلية + Canary + Rollback
- الأسبوع 3–4: Circuit Breaker + DLQ + تنبيهات أساسية
- الأسبوع 5: Quotas/Throttling + PostgreSQL PoC + سياسة الاحتفاظ/إخفاء PII

## 6) الاعتمادات والمتطلبات المسبقة
- إعداد بريد/Teams/Slack للويب هوكس
- مساحة تخزين releases/ وسير توقيع/Checksum
- اتفاق داخلي على سياسة الاحتفاظ والخصوصية

— عند إتمام كل عنصر، يُحدّث هذا المستند بحالة التنفيذ وروابط الأدلة ذات الصلة.