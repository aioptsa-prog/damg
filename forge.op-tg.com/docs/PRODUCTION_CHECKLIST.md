# قائمة إطلاق الإنتاج — OptForge (2025-10-13)

هذه القائمة تُستخدم قبل الإطلاق وأثناءه للتحقق من الجاهزية وتنفيذ الاختبارات والدلائل.

## 1) الأمن والمصادقة
- [ ] INTERNAL_SECRET مضبوط ومخزّن بأمان
- [ ] HMAC مُفعّل (X-Auth-*) والساعة متزامنة على الأجهزة
- [ ] أسرار لكل عامل (عينة كاناري): WORKER_SECRET وper_worker_secret_required (اختبار 401 و200)
- [ ] سياسات الوصول للوحة الإدارة (حسابات ومسؤول واحد على الأقل)

## 2) التحديثات الذاتية
- [ ] latest_{channel}.json موجودة للقنوات المطلوبة (stable/canary)
- [ ] admin/worker_updates.php يُنجز رفع artifact وحساب SHA256/size
- [ ] عامل Canary يُحدّث بنجاح ويمكن التراجع سريعًا إلى Stable

## 3) الاستقرار والمعالجة
- [ ] Claim ذري + Lease يعملان (probe)
- [ ] Backoff+jitter+max_attempts؛ عند الفشل النهائي تُسجل DLQ
- [ ] report_results يعيد {added, duplicates, done} صحيحة؛ counters تزداد

## 4) المراقبة والتنبيهات
- [ ] شارات Dup ratio 24h وترند 7 أيام تظهر
- [ ] alerts_tick.php يُرسل webhook/email عند Offline/DLQ/Stuck
- [ ] لوحة DLQ (admin/dlq.php) تُعيد الجدولة والحذف
- [ ] القاطع (CB) يمكن فتحه/إغلاقه لعامل محدد من workers
 - [ ] تشغيل فحص الجاهزية: `php tools/ops/go_live_preflight.php` (يجب أن يكون ok:true)

## 5) البيانات والخصوصية
- [ ] سياسة احتفاظ وPII Masking مُوثقة في RUNBOOK
- [ ] نسخ احتياطي لقاعدة SQLite قبل الإطلاق

## 6) أدلة الإطلاق
- [ ] tools/ops/capture_ingestion_evidence.php مُشغل؛ outputs تحت storage/logs/validation
- [ ] لقطات شاشة للوحة المراقبة/الخرائط/التحديثات
- [ ] evidence_*.zip مُجمّع

اختبار أخير: تشغيل عاملين (Canary + Stable) خلال 24 ساعة ومراجعة العدادات والأخطاء.
