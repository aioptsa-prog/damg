# Executive Summary

الغرض: منصة جمع وتنظيم Leads عبر عمّال موزّعين بإدارة عربية RTL، وأمان طبقي (HMAC/Replay/CSRF/CSP/RateLimit). تعمل على استضافة مشتركة وتدار عبر أدوات ويب.

## ماذا يفعل النظام الآن؟ لمن؟
- يجمع نتائج من مزوّدات (Google/Foursquare/OSM/Radar/Mapbox) ضمن دائرة جغرافية قابلة للتحكم.
- يستخدم عمّال (Workers) يسحبون Jobs ويرفعون النتائج عبر API مؤمّن.
- يطبع البيانات (Normalize) ويكشف التكرار (Dedup/Idempotency) ثم يعرضها في Leads Vault.
- أدوات تشغيلية: Preflight/Synthetic/Validate/Retention/Cleanup/Export.

## أقوى ما فيه
- أمان طبقي فعّال (Headers + CSRF + HMAC + Replay + RateLimit + حجب /tools).
- تشغيل بدون SSH مناسب للاستضافة المشتركة عبر /admin/* wrappers.
- Dedup/Idempotency مع فهارس حرجة لضمان الصحة والأداء.

## المخاطر
- SQLite في الإنتاج (قفل/تزامن عند النمو).
- أسرار داخلية داخل DB؛ الأفضل ENV + Rotation.
- مراقبة خارجية محدودة.

## قرار الجاهزية
- 🟡 CONDITIONAL GO — جاهز بإتمام 7 بنود تشغيلية (أدناه) خلال 48 ساعة.

## قبل الإطلاق (≤10)
1. per_worker_secret_required=1
2. Secrets via ENV + internal_secret_next
3. Backup يومي + اختبار Restore
4. Retention يومي (Dry-run أسبوعي)
5. Uptime/Webhook خارجي
6. CSP Phase-2 (إزالة unsafe-inline)
7. Load test خفيف (p95<800ms)
8. تحديث كل الروابط إلى /admin/*
9. HSTS سنة + includeSubDomains
10. صفحة جدولة داخلية بسيطة

Evidence
- `.htaccess`: حجب tools/ (root/.htaccess)
- `admin/dashboard.php`: أزرار الأدوات
- `lib/providers.php`: orchestration/grid
- `api/heartbeat.php`: HMAC
- `api/report_results.php`: idempotency/replay
