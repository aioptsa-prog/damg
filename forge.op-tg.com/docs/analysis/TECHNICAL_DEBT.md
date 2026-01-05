# سجل الدين التقني

تاريخ: 2025-10-24

## عالي الأولوية
- نقص Unit/Integration tests
- لا يوجد Error tracking مركزي
- عدم وجود Queue للعمليات الثقيلة

## متوسط الأولوية
- نقص توثيق API (OpenAPI)
- إستراتيجية Caching
- CI/CD للنشر الآلي

## منخفض الأولوية
- تكرار أكواد في بعض endpoints
- Inline JS/CSS في صفحات Admin
- Magic numbers (limits) تحتاج ثوابت مركزية

## خطط العلاج
- PHPUnit + متكامل بسيط (HTTP client محلي)
- دمج Sentry/Rollbar
- إضافة Redis كـ Queue/Cache
