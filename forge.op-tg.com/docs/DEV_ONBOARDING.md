# دليل انضمام المطوّرين (Developer Onboarding)

هدف هذا الدليل تسريع انضمام أي مطوّر جديد وتمكينه من فهم وتشغيل المشروع محليًا بسرعة.

## المتطلبات
- PHP ≥ 8.x مع SQLite (مضمن غالبًا)
- Windows PowerShell (بيئة التطوير المستهدفة) أو Bash
- Node.js (لاختبارات worker أو بناء المثبت عند الحاجة)

## تشغيل محلي سريع
1) استنسخ المستودع.
2) انسخ `config/.env.example.php` إلى `config/.env.php` واضبط المسارات (خاصة SQLITE_PATH و REMEMBER_COOKIE وغيرها).
3) شغّل الموقع محليًا عبر PHP Built-in Server:
   - مثال PowerShell: `php -S localhost:8080 -t d:\Projects\nexus.op-tg.com`
   - لاحظ أن السيرفر المدمج لا يصلح للإنتاج.
4) أول زيارة تُنشئ قواعد البيانات والجداول تلقائيًا (idempotent migrations داخل `config/db.php`).
5) ادخل عبر المستخدم الافتراضي (إذا لم يوجد، سيُضاف admin مع كلمة افتراضية أثناء الهجرة الأولى) ثم غيّرها فورًا.

## مكوّنات رئيسية
- Admin/API (PHP + SQLite): إدارة، تشخيصات، صف داخلي، مزودات بيانات، أدوات تصدير.
- Worker (Node + Playwright): خدمة الويندوز لسحب ومعالجة الوظائف؛ مُحدّث ذاتي.
- Releases: حزم الإصدارات و latest.json.

## مواضيع هامة سريعة
- الأمان: SECRET لا تُطبع في العميل. HMAC بين الخادم والعامل. رؤوس non-breaking.
- الخرائط: Leaflet محلي تحت `assets/vendor/leaflet` + fallback. البلاطات يمكن تخصيصها عبر `tile_sources_json`.
- الفلاتر: `ui_persist_filters` لحفظ تفضيلات النماذج والجداول.
- التصنيف: قواعد ووزنها، وإعادة تصنيف سريعة من الإعدادات.

## أين أبدأ؟
- اقرأ `docs/SYSTEM_OVERVIEW.md` ثم `docs/RUNBOOK.md` و `docs/DIAGNOSTICS.md`.
- استعرض `docs/ENGINEERING_JOURNAL.md` و `docs/INCIDENTS.md` لفهم التاريخ والسياق.
- راجع `docs/ARCH_DECISIONS.md` لفهم لماذا اتخذنا قرارات معيّنة.

## مهام أول أسبوع (اقتراح)
- تشغيل النظام محليًا، إنشاء مستخدم/مهمات بسيطة، التأكد من الخرائط.
- تجربة الإعدادات: تغيير default_ll، تفعيل internal_server_enabled، وضبط ui_persist_filters.
- قراءة جزء من الكود: `admin/fetch.php`، `agent/fetch.php`، `lib/system.php`، `layout_header.php`.
- اقتراح تحسينات صغيرة وتقديم أول PR.
