# تقرير جاهزية ما بعد الـMulti-Location — 2025-10-23

هذا التقرير يلخّص مراجعة تشغيلية كاملة بعد دمج التصنيفات والنوعية وميزة Multi-Location، مع تجربة حيّة آمنة، وأدلّة قابلة للتحقق.

## Matrix الجاهزية

- Schema: Ready
  - الجداول/الأعمدة موجودة: job_groups، internal_jobs.job_group_id، internal_jobs.city_hint، leads.job_group_id.
  - الفهارس موجودة: idx_job_groups_created، idx_internal_jobs_group، idx_leads_job_group.
- Security: Ready (Conditional)
  - HSTS لا يُرسل على HTTP (فقط عند HTTPS). CSP موجود.
  - CSRF مفعّل لواجهات POST الأدمن.
  - 429 يعمل على api/category_search.php بعد 30/دقيقة (اختُبر بضبط multiplier=1 مؤقتًا).
- Typeahead: Ready
  - يعمل بالعربية والإنجليزية؛ يُظهر المسار والأيقونة مع fallback مناسب.
- Multi-Location Fan-out: Ready
  - إنشاء مجموعة متعددة المواقع يُنتج وظائف لكل موقع مع ربط job_group_id.
- Ingest: Ready
  - ملء leads.job_group_id من internal_jobs.job_group_id باستخدام COALESCE؛ idempotency + dedup يعملان.
- Vault/Export: Ready
  - فلترة JobGroupID تعمل؛ التصدير (CSV/XLSX) يحتوي category_*، ويضيف job_group_id عند التصفية بالمجموعة.

ملاحظة الإعدادات: MAX_MULTI_LOCATIONS=10 موجودة. MAX_EXPANDED_TASKS غير مُعيّنة في settings لكنها فعّالة افتراضيًا (fallback = 30) من الكود. يُوصى بضبطها صراحة كتحسين طفيف (SQL آمن أدناه).

## أدلّة مختصرة (مستنِدة إلى تشغيل حيّ)

### 1) فحص الطبقة الأمنية على HTTP

- HSTS: غير موجود على HTTP.
- CSP: موجود.

Headers (HTTP): Strict-Transport-Security = false, Content-Security-Policy = true.

### 2) Typeahead على التصنيفات

- العربية: OK (مثال: "عيادات" يعرض path + icon)
- الإنجليزية: OK (مثال: "Dental" يعرض path + icon)

عينة عربية/إنجليزية (مقتطف مختصر):

```
ar_sample: { id: 6627, path: "جذر / طب وصحة / عيادات", icon: {type:"fa",value:"fa-hospital-user"} }
en_sample: { id: 6701, path: "جذر / طب وصحة / عيادات / Dental", icon: {type:"fa",value:"fa-hospital-user"} }
```

### 3) تجربة Multi-Location (مجموعة بموقعين)

الاستدعاء (POST) إلى `/api/jobs_multi_create.php` مع CSRF وموقعين (الرياض، جدة):

نتيجة مختصرة:

```
{"ok":true,"job_group_id":1,"jobs_created_total":4,
 "locations":[
   {"ll":"24.713600,46.675300","radius_km":3,"city":"الرياض","created":2,"trimmed":false,"projected":2},
   {"ll":"21.485800,39.192500","radius_km":3,"city":"جدة","created":2,"trimmed":false,"projected":2}
 ],
 "locations_trimmed":false}
```

لقطة من internal_jobs (عينة):

```
[{"id":427,"query":"عيادة أسنان","ll":"24.713600,46.675300","radius_km":3,"city_hint":"الرياض","job_group_id":1},
 {"id":428,"query":"مطاعم","ll":"24.713600,46.675300","radius_km":3,"city_hint":"الرياض","job_group_id":1}, ...]
```

### 4) تغذية نتائج تجريبية (Ingest)

- report_results#1: added=3, duplicates=0
- report_results#2 (idempotency): idempotent=true, added=0
- report_results#3 (duplicate): duplicates=1, added=0

Counters اليوم: {ingest_added: 7, ingest_duplicates: 3}

### 5) Vault/Export

- `admin/leads.php?job_group_id=1` — HTTP 200.
- CSV من نفس الشاشة: Header يتضمن category_name, category_slug, category_path + يظهر job_group_id عند التصفية بالمجموعة.

مقتطف رأس CSV:

```
id,name,phone,city,country,created_at,rating,website,email,gmap_types,source_url,social,category_name,category_path,job_group_id,category_slug,agent_name,status,lat,lon,geo_country,geo_region,geo_city_id,geo_district_id,geo_confidence
```

### 6) Rate Limit 429 على typeahead

- بعد ضبط multiplier=1 مؤقتًا: 35 طلبًا خلال دقيقة → 2xx=28, 429=7.

عينة 429:

```
{"ok":false,"error":"rate_limited","limit":30,"window":"1m"}
```

## مخاطر وقائية وكيفية تغطيتها

- انفجار عدد المهام: حدِّد MAX_MULTI_LOCATIONS (مفعّل=10) + MAX_EXPANDED_TASKS (=30 fallback). يتم القص تلقائيًا مع تنبيه في الواجهة.
- تداخل جغرافي: استخدم أنصاف أقطار صغيرة (2–3 كم) للمجموعات الأولى؛ وزّع المواقع لتقليل التداخل.
- حصص مزوّد الخرائط: راقب usage_counters + حدود المزودين، وخفّض التوسّع أو عطّل multi_search مؤقتًا عند اقتراب النفاد.
- ازدواج الإدخال: المسار يطبق dedup + idempotency بالفعل؛ يُنصح بمراقبة ingest_duplicates يوميًا.

## توصيات قصيرة المدى (Ready-to-Apply)

1) صفحة Job Groups (قائمة + تفاصيل + تقدّم ومقاييس per-group).
2) خريطة دبابيس مصغّرة في fetch لإدخال مواقع المجموعة بصريًا.
3) تنبيه داخلي عند تجاوز مجموعةٍ عتبةَ عدد الوظائف/المواقع خلال نافذة زمنية.
4) تخصيص حد 429 حسب الدور/المجموعة (جعل multiplier ديناميكيًا لكل user أو path).
5) إشعار فشل ingest (DLQ/attempts) بتلخيص يومي على البريد/Slack.
6) تثبيت MAX_EXPANDED_TASKS صراحة في settings (30 كقيمة أولية) بدل الاعتماد على fallback، لتوحيد السلوك بين البيئات.
7) لوحة مؤشرات صغيرة لملخّص اليوم/الأسبوع (ingest_added/duplicates، ml_groups_created/ml_jobs_created).

## SQL آمن/Idempotent (اقتراح فقط — لا تنفيذ تلقائي)

```sql
-- تثبيت قيمة صريحة لـ MAX_EXPANDED_TASKS (يستخدم النظام fallback=30 حاليًا)
INSERT INTO settings(key,value) VALUES('MAX_EXPANDED_TASKS','30')
ON CONFLICT(key) DO UPDATE SET value=excluded.value;
```

## المسارات والملفات المستخدمة

- الواجهات: `/admin/fetch.php`, `/admin/leads.php`, `/api/jobs_multi_create.php`, `/api/category_search.php`, `/api/export_leads*.php`, `/api/report_results.php`.
- السكربت التشغيلي (داخلي): `tools/tests/post_ml_operational_check.php` (أنتج الأدلّة الرقمية أعلاه).
- الشيفرة الأمنية: `lib/system.php` (HSTS/CSP)، حماية CSRF عبر `lib/csrf.php`.

## الحكم النهائي

Ready للإطلاق المرحلي (Canary → Gradual Rollout) وفق الخطوات المقترحة في خطة الإطلاق؛ لا توجد كسور للواجهات القديمة؛ الأدلّة تُظهر الالتزام بالمتطلبات.
