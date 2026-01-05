# 📦 دليل نشر LeadHub على الاستضافة المشتركة
## Production Deployment Guide

---

## 📁 هيكل الملفات المطلوب رفعها

```
public_html/
├── app/                    ← Frontend Build (من saudi-lead-iq-main/dist/)
│   ├── assets/
│   ├── index.html
│   └── ...
├── v1/                     ← REST API
│   └── api/
├── lib/                    ← PHP Libraries
├── config/                 ← Configuration
├── storage/                ← Database & Files
│   └── database.sqlite
├── .htaccess               ← Apache Rewrite Rules
└── index.php               ← Router
```

---

## 🚀 خطوات النشر

### 1. تجهيز Frontend
```bash
cd saudi-lead-iq-main
npm run build
```
ثم انسخ محتويات `dist/` إلى مجلد `app/` على السيرفر.

### 2. رفع ملفات Backend
ارفع المجلدات التالية:
- `v1/` (API)
- `lib/` (المكتبات)
- `config/` (الإعدادات)
- `storage/` (قاعدة البيانات)

### 3. ضبط الإعدادات

#### ملف `.htaccess` الرئيسي:
```apache
RewriteEngine On
RewriteBase /

# API requests
RewriteRule ^v1/(.*)$ v1/$1 [L]

# Frontend SPA
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^app/(.*)$ app/index.html [L]

# Redirect root to app
RewriteRule ^$ app/ [L,R=301]
```

#### ملف `config/.env.php`:
```php
<?php
return [
    'APP_ENV' => 'production',
    'APP_DEBUG' => false,
    'APP_URL' => 'https://yourdomain.com',
    'API_URL' => 'https://yourdomain.com/v1/api',
    'DB_PATH' => __DIR__ . '/../storage/database.sqlite',
    'REMEMBER_COOKIE' => 'leadhub_remember',
    'SESSION_LIFETIME' => 43200, // 12 hours
];
```

### 4. صلاحيات الملفات
```bash
chmod 755 storage/
chmod 644 storage/database.sqlite
chmod 644 .htaccess
chmod -R 755 v1/
```

### 5. تحديث API Base URL في Frontend
قبل البناء، عدّل ملف `saudi-lead-iq-main/src/lib/api.ts`:
```typescript
const API_BASE = 'https://yourdomain.com/v1/api';
```

---

## ⚠️ ملاحظات هامة

1. **قاعدة البيانات**: تأكد من نسخ `storage/database.sqlite` مع البيانات
2. **HTTPS**: يجب استخدام HTTPS للأمان
3. **PHP Version**: يتطلب PHP 8.0+
4. **SQLite Extension**: تأكد من تفعيل `pdo_sqlite`

---

## 🔧 اختبار بعد النشر

1. افتح `https://yourdomain.com/app/`
2. جرب تسجيل الدخول
3. جرب إنشاء حملة
4. تحقق من صفحة التحليلات

---

## 📞 دعم

في حال وجود مشاكل، تحقق من:
- سجل أخطاء PHP
- Console في المتصفح
- Network tab للتحقق من API calls
