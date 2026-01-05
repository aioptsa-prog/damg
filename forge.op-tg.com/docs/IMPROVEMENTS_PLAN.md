# خطة التحسين المرحلية — الجلب والوحدات الطرفية والبحث

هذا المستند يُستخدم لتتبع التحسينات الحرجة والمتوسطة وطويلة الأمد. يُحدّث في نهاية كل مرحلة (Sprint) مع حالة التنفيذ والدروس المستفادة وروابط الأدلة.

تاريخ الإصدار: 2025-10-12
الحالة: الإصدار الأول

## Sprint 0 — عاجل (أسبوع)
- [x] Claim ذري في pull_job (SQLite BEGIN IMMEDIATE + UPDATE مشروط) — Started (attempt_id + txn added)
- [x] Backoff أُسّي + jitter وتعبئة next_retry_at — Added في report_results (إعدادات defaults وindex)
- [x] Idempotency key في report_results + بصمة UNIQUE — Added idempotency_keys (UNIQUE(job_id,ikey))
- [x] فهارس أساسية (jobs/leads/attempts) — Added idx_internal_jobs_status_retry
- [x] تحقق المدخلات (LL/Radius) في admin/agent fetch + تطبيع LL
 - [x] تحقق/تطبيع Phone + بصمة UNIQUE لسجلات الجلب — phone_norm (SA 966) + leads_fingerprints(UNIQUE)

نتيجة السبرينت:
- تم تنفيذ القفل الذري والـ backoff وidempotency keys، والتحقق من LL/Radius.
- اكتمل تحقق/تطبيع الهاتف (E.164-like لـ 966) وبناء بصمة dedup فريدة، مع عدادات ingestion (added/duplicates) وDup Ratio 24h وترند 7 أيام معروضة في لوحة المراقبة.
- أدوات أدلة جاهزة: tools/ops/ingest_probe.php و tools/ops/capture_ingestion_evidence.php لإنتاج أدلة مضافة/مكررة ولقطات عدادات اليوم.

## Sprint 1 — وثوقية وتشخيص (أسبوعان)
- [ ] Circuit breaker للوحدات (قابل للتهيئة لكل مزوّد/عامل مع توقف مؤقت تلقائي)
- [ ] Dead-letter + واجهة متابعة (DLQ + إعادة جدولة/حذف)
- [ ] سجلات JSON موحّدة + عدادات (هيكل موحد لكل من العامل والخادم)
- [ ] Server-side DataTables للجداول الثقيلة (الوظائف والسجلات)

نتيجة السبرينت:
- To be filled بعد التنفيذ.

## Sprint 2 — توسّع ومراقبة (شهر)
- [ ] Throttling/Quotas للمزوّدين + Probe خارجي
- [ ] خطة هجرة PostgreSQL (PoC)
- [ ] مراقبة (Prometheus/Grafana) إذا أمكن

نتيجة السبرينت:
- To be filled بعد التنفيذ.

## ملاحظات التنفيذ والروابط
- راجع FETCH_WORKERS_AUDIT.md كمرجع شامل للحالة والخطة.
- راجع JOBS_LIFECYCLE.md للتفاصيل التشغيلية للـ Lease والـ Backoff.
- قم بتحديث INCIDENTS.md إذا ظهرت أي حوادث جديدة خلال التنفيذ.
- حدّث EVIDENCE_CHECKLIST.md بلقطات وقياسات لكل تحسين تمت تجربته.
- راجع PRODUCTION_READINESS.md لتقييم جاهزية الإطلاق المرحلي ومتطلبات الإغلاق قبل التوسّع.

— ملاحظة: بعد كل تقدم، يُحدّث هذا الملف بحالة المهام وما تم التحقق منه وبالخطوات التالية. 