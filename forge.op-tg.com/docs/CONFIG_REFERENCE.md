# مرجع الإعدادات (Config Reference)

هذا المرجع يشرح أهم مفاتيح الإعدادات في جدول `settings`، قيمها الافتراضية، وأثرها على النظام. تُدار غالبية هذه الإعدادات من صفحة المشرف: إدارة ← الإعدادات.

ملاحظات عامة
- كل القيم محفوظة كنصوص (TEXT) في SQLite.
- القيم الثنائية تكون '1' للتشغيل و'0' للإيقاف.
- إذا لم تكن القيمة موجودة، تُستخدم القيم الافتراضية المُهيّأة داخل `config/db.php`.

## أساسية (عام)
- brand_name: اسم المنتج الظاهر في الواجهة (افتراضي: OptForge).
- brand_tagline_ar / brand_tagline_en: شعارات مختصرة بالعربية/الإنجليزية.
- default_ll: إحداثيات افتراضية بصيغة `lat,lng` (افتراضي: 24.638916,46.716010 الرياض).
- default_radius_km: نصف قطر البحث بالكيلومتر (افتراضي: 25).
- default_language / default_region: محددات اللغة والمنطقة لمزودي البيانات.
- google_api_key / foursquare_api_key / mapbox_api_key / radar_api_key: مفاتيح مزودات البيانات (اختياري).
- provider_order: ترتيب المزوّدات المفضّل (افتراضي: `osm,foursquare,mapbox,radar,google`).
- tile_ttl_days: عمر صلاحية البلاطات المخزّنة (إن وُجدت آلية تخزين) — افتراضي: 14.
- daily_details_cap: سقف يومي لاستهلاك تفاصيل Google — افتراضي: 1000.

## التحكم بالنظام والتوقيت
- system_global_stop: إيقاف شامل للنظام (1=إيقاف، 0=تشغيل).
- system_pause_enabled: تفعيل فترة إيقاف يومية.
- system_pause_start / system_pause_end: وقت البداية/النهاية بصيغة HH:MM (مثلاً 23:59 / 09:00).

## خادم داخلي (Internal Queue)
- internal_server_enabled: تحويل طلبات الجلب إلى طابور داخلي.
- internal_secret: سر توقيع HMAC بين الخادم والعامل (Worker).
- worker_pull_interval_sec: فترة سحب الوظائف من العامل (ثواني).
- job_pick_order: ترتيب اختيار الوظائف: fifo | newest | random | pow2 | rr_agent | fair_query.
- workers_online_window_sec: نافذة اعتبار العامل «متصل» (ثواني) — افتراضي: 90 (نطاق آمن 15–600).
 - cb_open_workers_json: قائمة JSON لمعرّفات العمال الذين فُتح لهم القاطع (Blocklist للـ pull_job). عندما يحتوي المعرف، يعيد الخادم 429 cb_open لهذا العامل.

## عامل الويندوز (Worker) — تشغيل
- worker_headless: تشغيل المتصفح دون واجهة (1/0).
- worker_max_pages: أقصى صفحات مفتوحة بالتوازي (افتراضي: 5).
- worker_lease_sec: مدة حجز الوظيفة قبل اعتبارها عالقة (افتراضي: 180).
- worker_report_batch_size: حجم دفعة التقارير المرحّلة (افتراضي: 10).
- worker_report_every_ms / worker_report_first_ms: تواتر الإبلاغ بالملّي ثانية (افتراضي: 15000/2000).
- worker_item_delay_ms: تأخير بين العناصر لتخفيف الضغط (افتراضي: 800).
- worker_base_url: عنوان الخادم الذي يتصل به العامل.
- worker_config_code: رمز اختياري لمشاركة الإعدادات مركزياً.
- worker_chrome_exe / worker_chrome_args: تخصيص مسار وإعدادات Chrome (اختياري).
 - worker_update_channel: القناة الافتراضية لتحديث العامل (stable/canary/beta).
 - worker_channel_overrides_json: خريطة JSON لتجاوز القناة لكل عامل { "wrk-123":"canary", ... }.

## الأمان والمعدلات
 force_https: عند تعيينها إلى '1'، يتم إعادة توجيه جميع طلبات HTTP إلى HTTPS (308). يُوصى بها في الإنتاج خلف TLS.
 security_csrf_auto: عند '1'، يتم التحقق من POSTs تلقائيًا عبر رمز CSRF.
 per_worker_secret_required: عند '1'، إذا كان لدى عامل سر مسجل، يجب تقديم `X-Worker-Secret` ومطابقته.
 workers_online_window_sec: نافذة تستخدم لاعتبار العامل "متصلًا" لصحة تأجير الوظائف ومنطق إعادة الطرح.
 rate_limit_basic: '1' يمكّن الحد الأساسي للمعدلات لكل IP + مسار.
 rate_limit_global_per_min: حد صحيح لكل نافذة 60 ثانية (افتراضي 600).
## التحديث الذاتي والإصدار
- enable_self_update: تمكين التحديث الذاتي (الخادم فقط).
- app_url / worker_base_url: روابط أساسية للتناسق بين الواجهة والعامل.
- app_version: رقم الإصدار الحالي (يُحدّث أثناء النشر).

## واجهة الخرائط (Leaflet)
- tile_sources_json: مصفوفة JSON لمصادر البلاطات البديلة بصيغة عناصر { url, att }؛ تُستخدم على صفحات الجلب.
  - مثال: `[{"url":"https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png","att":"© OpenStreetMap contributors"}]`
- ui_persist_filters: علم لتفعيل حفظ تفضيلات الفلاتر في الواجهة (1/0).
 - workers_admin_page_limit: حد النتائج الافتراضي لصفحة العمال (100–2000) لتحسين الأداء.

## أخرى
- export_max_rows: حد أقصى لصفوف التصدير (افتراضي 50000).
- maintenance_secret: سر لعمليات الصيانة اليدوية.
- reclassify_default_limit / reclassify_only_empty / reclassify_override: إعدادات مهام إعادة التصنيف السريعة.
- overpass_limit: حد استعلامات Overpass (OSM) بين 50–1000.
- alert_webhook_url: عنوان Webhook لتسليم التنبيهات (JSON: {ok,alerts,ts}). اتركه فارغًا لتعطيل الـ Webhook.
- alert_email: بريد إلكتروني لتسليم التنبيهات المختصرة. يتطلب تمكين mail() على الخادم. اتركه فارغًا لتعطيل البريد.
- alert_slack_token: رمز وصول لتطبيق Slack (xapp- أو xoxe-) لإرسال تنبيهات عبر chat.postMessage.
- alert_slack_channel: اسم القناة (مثل #alerts) أو معرّف القناة (مثل C0123456789) عند استخدام Slack Token.

ملاحظات تشغيلية
- معظم هذه القيم قابلة للتعديل من صفحة الإعدادات (عام/Providers). بعض القيم مُهيّأة تلقائيًا أثناء الهجرة الأولى ولا تظهر كحقول UI.
- لا تُلصق أسرارًا في الواجهة الأمامية أو سجلات المتصفح. استخدم الحقول الخاصة بالخادم فقط.
