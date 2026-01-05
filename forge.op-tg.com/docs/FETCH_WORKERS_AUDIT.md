# تدقيق شامل — نظام الجلب والوحدات الطرفية والبحث

هذا المستند هو المرجع التشغيلي والتحليلي المستمر لنظام الجلب والوحدات الطرفية والبحث عبر الوحدات. يُحدّث بعد كل مرحلة عمل (Sprint) بتقدم التنفيذ، المشاكل، الحلول، والخطوات التالية.

تاريخ الإصدار: 2025-10-12
الحالة: الإصدار الأول (بناءً على التحليل الشامل الأخير)

## ملخص تنفيذي
- الجاهزية الحالية: صالحة للأحمال الصغيرة/المتوسطة مع حاجة لتحسينات حرجة في التزامن، الإعادة (Retry/Backoff)، الـ Idempotency، والفهرسة.
- المخاطر الحرجة: سباقات المطالبة بالمهام (claim)، تكرار النتائج، غياب Backoff منهجي، فهارس ناقصة، قيود SQLite للكتابة المتوازية.
- التحسينات العاجلة: Claim ذري بمعاملة واحدة، Backoff أُسّي مع jitter، مفاتيح Idempotency وبصمالسجل للتكرار، فهارس أساسية، تحقق المدخلات.

## البنية الحالية (مختصر)
- واجهة (PHP + DataTables + Leaflet)، خادم داخلي للوظائف (API: pull_job/report_results/heartbeat)، عامل ويندوز (Node + Playwright).
- قاعدة البيانات: SQLite (internal_jobs، internal_workers، leads، وغيرها) تُنشأ عبر `config/db.php`.
- التدفق: إنشاء مهمة → claim/lease → تقارير تقدّم + نتائج → إنهاء/إعادة محاولة → تصنيف وتخزين.

## فجوات ومشاكل مؤثرة
1) Claim سباقي في SQLite عند اختيار/تحديث السجل بدون ذَرّة.
2) Retry/Backoff محدودان؛ لا `next_retry_at` عملي ولا jitter.
3) Idempotency/Dedup ضعيفان لنتائج الجلب (احتمال تكرارات).
4) فهارس ناقصة على أعمدة البحث والحالة والتواريخ.
5) صحة المدخلات محدودة (LL/Radius/Phone).
6) مراقبة/مقاييس محدودة (لا success_rate، claim_latency، dead_letter).

## خطة التحسين على ثلاث مراحل

المرحلة 1 (أسبوع)
- Claim ذري (BEGIN IMMEDIATE + UPDATE بشرط status=pending AND claimed_by IS NULL).
- Backoff أُسّي: base=30s، cap=30m، jitter ±20%، وتعبئة `next_retry_at`.
- Idempotency: `idempotency_key` في report_results؛ fingerprint موحّد للسجلات (phone_norm|provider|bucket|city/latlng) مع UNIQUE.
- فهارس أساسية: internal_jobs(status,run_after/next_retry_at,priority,created_at)، leads(phone_norm)، وغيرها.
- تحقق المدخلات (LL/Radius/Phone) بقيود CHECK ومعالجات PHP.

المرحلة 2 (أسبوعان)
- Circuit Breaker للوحدات ذات معدل فشل مرتفع + نافذة صحة worker.
- Dead-letter للوظائف المستنزفة + صفحة متابعة.
- سجلات JSON موحّدة + عدادات أساسيات (jobs_failed، jobs_succeeded، claim_latency_ms).
- Server-side DataTables للجداول الكبيرة.

المرحلة 3 (شهر)
- Throttling/Quotas للمزوّدين الخارجيين + Probe للمصادر.
- خطة هجرة PostgreSQL (تجريبية) لاستخدام SKIP LOCKED وتحسين التزامن.
- مراقبة موسعة (Prometheus/Grafana) حسب الإمكان.

## عقود فنية (Contracts)
- pull_job: يعيد 200 مع JSON job أو 204 بلا مهام؛ يلتزم بعلم internal_server_enabled والسر HMAC.
- report_results: يقبل `{ job_id, items[], cursor?, done?, extend_lease_sec?, idempotency_key? }`؛ يضمن UPSERT وعدم التكرار.
- heartbeat: يحدّث internal_workers(worker_id,last_seen,info) ويعيد نافذة الصحة.

## مؤشرات الأداء المستهدفة (KPIs)
- Success rate ≥ 90%
- Mean claim latency ≤ 150ms
- Jobs failed ≤ 5% / ساعة
- Heartbeat lag P95 ≤ 30s
- Dedup drop accuracy ≥ 99%

## سجل التقدم (يُحدّث بعد كل مرحلة)
- 2025-10-12: إنشاء ملف التدقيق الأساسي وربطه بـ INCIDENTS/ADRs/DIAGNOSTICS/RUNBOOK.
- 2025-10-12: بدء Sprint 0 — تنفيذ claim ذري في pull_job (مع attempt_id)، إضافة جداول/إعدادات backoff وidempotency keys، وتوحيد فهارس retry.
- 2025-10-12: إضافة تحقق LL/Radius وتطبيع LL في admin/agent fetch قبل إدراج المهمة؛ تحديد نصف القطر إلى 1..100 كم.
- 2025-10-12: إضافة phone_norm في DB + استخدامه في الإدراج/التحديث داخل report_results؛ تحضير لفهرسة/بصمة منع التكرار.
- 2025-10-12: إضافة جدول leads_fingerprints (UNIQUE(fingerprint)) وحساب بصمة يومية مبسطة وتسجيلها عند الإدراج لتقليل التكرار.

## روابط مرجعية
- INCIDENTS.md — حادثة الخرائط (أكتوبر 2025) والمنهجيات المانعة.
- JOBS_LIFECYCLE.md — حالات الوظائف والـ Backoff/Lease.
- RUNBOOK.md — اختبارات Smoke وخطوات التشغيل.
- DIAGNOSTICS.md — تشخيص Leaflet وCLI probes.
- ARCH_DECISIONS.md — قرارات Local-first Leaflet وtile_sources_json.

---

تنويه: بعد كل تقدم، حدّث هذا الملف بقسم “سجل التقدم” وأرفق الروابط والنتائج وأي عقبات ظهرت وخطواتك التالية.