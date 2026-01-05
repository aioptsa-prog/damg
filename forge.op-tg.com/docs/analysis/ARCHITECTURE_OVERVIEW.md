# نظرة عامة على المعمارية

تاريخ: 2025-10-24

## الطبقات الرئيسية
- Frontend: صفحات Admin (PHP/HTML) + Vanilla JS + Leaflet (الخريطة) + FontAwesome.
- Application: PHP بدون إطار، وحدات lib/* (auth, categories, providers, system, limits) وواجهات API في api/*.
- Data: SQLite مع WAL + مؤشرات مركبة؛ ترحيلات في config/db.php ومجلد db/migrations.

## تدفق البيانات
- Fetch → (admin/fetch.php) → api/jobs_multi_create.php → internal_jobs → Workers → api/report_results.php → leads
- Export → api/export_leads(.php|_excel.php|_xlsx.php) مع فلترة category/job_group
- Category Search → api/category_search.php (rate limited, admin-only)

## وحدات رئيسية
- تصنيفات: lib/categories.php + api/category_search.php + assets/...
- أمان: lib/system.php, lib/security.php, lib/csrf.php, lib/limits.php
- جلب متعدد المواقع: admin/fetch.php + api/jobs_multi_create.php + propagation عبر ingestion
- إدخال: api/report_results.php (COALESCE لحفظ job_group_id)
- مراقبة/صحة: admin/health.php, admin/monitor*.php, api/health.php

## خرائط وتقنيات
- Leaflet مع طبقات tile بدائل (OSM, Carto) وتحمل فشل الشبكة.
- دبابيس ML: DivIcon + دوائر نصف قطر؛ تزامن ثنائي الاتجاه مع الحقول؛ وضع الإسقاط بالنقر.

## تحسينات مستقبلية مقترحة
- فصل concerns إلى طبقات خدمية: ingestion/export workers.
- إضافة Queue (Redis/RabbitMQ) وWebhooks لتحديثات فورية.
- OpenAPI للـ API ونشر وثائق عبر docs/API.md.
