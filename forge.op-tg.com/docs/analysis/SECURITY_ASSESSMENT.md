# تقييم الأمان

تاريخ: 2025-10-24

## ما هو مطبق
- CSRF لكل POST (csrf_input/verify)
- CSP nonce للسكربتات؛ HSTS عند HTTPS
- Rate limiting (429) بجدول rate_limit (UPSERT + نافذة 1m)
- HMAC + replay guard للـ workers (ingestion)
- Prepared statements وتعقيم المخرجات (XSS)

## نقاط تحتاج عمل
- 2FA للمشرفين
- Security headers إضافية (X-Frame-Options, Referrer-Policy)
- Session hardening (timeout, SameSite)
- Audit logging شامل (admin critical ops)
- رفع صرامة uploads (api/category_icon_upload.php)

## توصيات
- اعتماد سياسة كلمات مرور قوية + قفل مؤقت بعد محاولات فاشلة
- تقرير أمني ربع سنوي وPenTest خارجي
- توثيق security playbooks في docs/
