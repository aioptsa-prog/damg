# RUNBOOK.md
> دليل التشغيل والنشر
> تاريخ الإنشاء: 2026-01-05

---

## 1. المتطلبات الأساسية

### 1.1 البرمجيات المطلوبة

| البرنامج | الإصدار المطلوب | الإصدار الحالي | الحالة |
|----------|-----------------|----------------|--------|
| Node.js | 20.x+ | v22.20.0 | ✅ |
| PHP | 8.0+ | 8.4.14 | ✅ |
| Git | أي إصدار | غير مثبت | ⚠️ |

### 1.2 الخدمات الخارجية

| الخدمة | الاستخدام | مطلوب لـ |
|--------|----------|----------|
| Neon PostgreSQL | قاعدة بيانات OP-Target | OP-Target |
| Google Gemini API | تقارير AI | OP-Target (اختياري) |
| OpenAI API | تقارير AI (بديل) | OP-Target (اختياري) |
| Washeej API | إرسال WhatsApp | Forge |

---

## 2. تشغيل OP-Target-Sales-Hub-1

### 2.1 التثبيت الأولي

```powershell
# الانتقال للمجلد
cd d:\projects\دمج\OP-Target-Sales-Hub-1

# تثبيت الحزم
npm install

# نسخ ملف الإعدادات
copy .env.example .env
```

### 2.2 إعداد متغيرات البيئة

**ملف `.env` - الإعدادات المطلوبة:**

```env
# قاعدة البيانات (مطلوب)
DATABASE_URL=postgresql://user:password@host.neon.tech:5432/database?sslmode=require

# الأمان (مطلوب)
JWT_SECRET=your-jwt-secret-here-min-32-chars
ENCRYPTION_SECRET=your-encryption-secret-here-min-32-chars

# Seed (مطلوب للتشغيل الأول)
SEED_SECRET=your-seed-secret-here
ADMIN_EMAIL=admin@example.com
ADMIN_PASSWORD=your-strong-admin-password

# AI (اختياري)
GEMINI_API_KEY=AIza...
# أو
OPENAI_API_KEY=sk-...

# التكامل مع Forge (اختياري)
FORGE_API_BASE_URL=http://localhost:8081
INTEGRATION_SHARED_SECRET=...
```

### 2.3 تهيئة قاعدة البيانات

```powershell
# تشغيل Migrations
npm run db:migrate
```

### 2.4 أوامر التشغيل

| الأمر | الوظيفة | ملاحظات |
|-------|---------|---------|
| `npm run dev` | خادم التطوير | المنفذ 3000 |
| `npm run build` | بناء الإنتاج | يُخرج إلى `dist/` |
| `npm run preview` | معاينة البناء | بعد `build` |
| `npm run test` | اختبارات الوحدة | Vitest |
| `npm run test:e2e` | اختبارات E2E | Playwright |

### 2.5 التحقق من التشغيل

```powershell
# تشغيل خادم التطوير
npm run dev

# في نافذة أخرى، اختبار الاتصال
curl http://localhost:3000

# تشغيل الاختبارات
npm run test
```

**النتيجة المتوقعة:**
- خادم Vite يعمل على `http://localhost:3000`
- 62 اختبار ناجح

---

## 3. تشغيل forge.op-tg.com

### 3.1 التثبيت الأولي

```powershell
# الانتقال للمجلد
cd d:\projects\دمج\forge.op-tg.com

# لا يوجد تثبيت حزم مطلوب للـ PHP
# قاعدة البيانات SQLite تُنشأ تلقائياً
```

### 3.2 إعداد متغيرات البيئة

**ملف `.env` (موجود):**

```env
LEASE_SEC_DEFAULT=180
BACKOFF_BASE_SEC=30
BACKOFF_MAX_SEC=3600
MAX_ATTEMPTS_DEFAULT=5
INTERNAL_SECRET=forge-bf63d94131407eab74bec55436eda0f1
BASE_URL=http://localhost:8081
WORKER_ID=local-worker-1
PULL_INTERVAL_SEC=30
HEADLESS=false
```

### 3.3 أوامر التشغيل

| الأمر | الوظيفة | ملاحظات |
|-------|---------|---------|
| `php -S localhost:8081 router.php` | خادم التطوير | المنفذ 8081 |
| `php tools/check_worker_vendor.php` | فحص متطلبات Worker | قبل تشغيل Worker |
| `php tools/seed_dev.php` | إنشاء مستخدم تطوير | للتطوير فقط |

### 3.4 التحقق من التشغيل

```powershell
# تشغيل الخادم
php -S localhost:8081 router.php

# في نافذة أخرى، اختبار الاتصال
curl http://localhost:8081

# اختبار Bootstrap
php -r "require 'bootstrap.php'; echo 'OK';"
```

**النتيجة المتوقعة:**
- خادم PHP يعمل على `http://localhost:8081`
- صفحة HTML تظهر بشكل صحيح

---

## 4. تشغيل Worker (Playwright)

### 4.1 التثبيت

```powershell
cd d:\projects\دمج\forge.op-tg.com\worker

# تثبيت الحزم
npm install

# تثبيت Chromium
npx playwright install chromium
```

### 4.2 إعداد Worker

**ملف `worker.env` (في المجلد الرئيسي):**

```env
BASE_URL=http://localhost:8081
INTERNAL_SECRET=forge-bf63d94131407eab74bec55436eda0f1
WORKER_ID=local-worker-1
HEADLESS=false
```

### 4.3 تشغيل Worker

```powershell
cd d:\projects\دمج\forge.op-tg.com\worker
npm start
```

### 4.4 التحقق من صحة Worker

```powershell
# فحص حالة Worker
npm run health

# أو
curl http://127.0.0.1:4499/status
```

---

## 5. تشغيل النظام الكامل

### 5.1 ترتيب التشغيل

1. **Forge PHP Server** (أولاً)
2. **OP-Target Vite** (ثانياً)
3. **Worker** (اختياري - للـ Scraping)

### 5.2 سكريبت تشغيل شامل

```powershell
# start-all.ps1
# تشغيل Forge
Start-Process powershell -ArgumentList "-NoExit", "-Command", "cd 'd:\projects\دمج\forge.op-tg.com'; php -S localhost:8081 router.php"

# انتظار 2 ثانية
Start-Sleep -Seconds 2

# تشغيل OP-Target
Start-Process powershell -ArgumentList "-NoExit", "-Command", "cd 'd:\projects\دمج\OP-Target-Sales-Hub-1'; npm run dev"

Write-Host "✅ الخوادم تعمل:"
Write-Host "   - Forge: http://localhost:8081"
Write-Host "   - OP-Target: http://localhost:3000"
```

---

## 6. أخطاء شائعة وحلولها

### 6.1 OP-Target

| الخطأ | السبب | الحل |
|-------|-------|------|
| `DATABASE_URL environment variable is required` | متغير البيئة غير موجود | أضف DATABASE_URL في `.env` |
| `JWT_SECRET not configured` | متغير البيئة غير موجود | أضف JWT_SECRET في `.env` |
| `ECONNREFUSED` | قاعدة البيانات غير متاحة | تحقق من اتصال Neon |
| `Port 3000 already in use` | المنفذ مستخدم | أوقف العملية أو غيّر المنفذ |

### 6.2 Forge

| الخطأ | السبب | الحل |
|-------|-------|------|
| `Module "openssl" is already loaded` | تحذير PHP | تجاهل (لا يؤثر) |
| `SQLSTATE[HY000]: unable to open database` | مسار SQLite خاطئ | تحقق من `config/.env.php` |
| `Port 8081 already in use` | المنفذ مستخدم | أوقف العملية أو غيّر المنفذ |

### 6.3 Worker

| الخطأ | السبب | الحل |
|-------|-------|------|
| `Chromium not found` | لم يُثبت | `npx playwright install chromium` |
| `ECONNREFUSED to localhost:8081` | Forge غير يعمل | شغّل Forge أولاً |
| `401 Unauthorized` | INTERNAL_SECRET خاطئ | تحقق من تطابق السر |

---

## 7. النشر للإنتاج

### 7.1 OP-Target (Vercel)

```powershell
# بناء المشروع
npm run build

# النشر عبر Vercel CLI
npx vercel --prod
```

**متغيرات البيئة المطلوبة في Vercel:**
- `DATABASE_URL`
- `JWT_SECRET`
- `ENCRYPTION_SECRET`
- `SEED_SECRET`

### 7.2 Forge (VPS/Shared Hosting)

1. ارفع الملفات عبر FTP/SSH
2. تأكد من صلاحيات `storage/` (777)
3. أعد تسمية `.htaccess` إذا لزم
4. اختبر: `https://your-domain.com/`

### 7.3 Worker (VPS)

```bash
# على الخادم
cd /path/to/worker
npm install
npx playwright install chromium

# تشغيل مع PM2
pm2 start npm --name "forge-worker" -- start
```

---

## 8. الصيانة الدورية

### 8.1 مهام يومية

- [ ] فحص صحة Workers: `Admin → Health`
- [ ] مراجعة Dead Letter Queue
- [ ] فحص Logs: `storage/logs/`

### 8.2 مهام أسبوعية

- [ ] تدوير Logs: `php tools/rotate_logs.php`
- [ ] تنظيف Sessions منتهية الصلاحية
- [ ] مراجعة Audit Logs

### 8.3 مهام شهرية

- [ ] تحديث الحزم: `npm update`
- [ ] مراجعة الأمان
- [ ] نسخ احتياطي لقاعدة البيانات

---

## 9. أوامر مفيدة

### 9.1 تشخيص

```powershell
# فحص إصدارات
node --version
php --version

# فحص اتصال قاعدة البيانات (Forge)
php -r "require 'bootstrap.php'; echo db() ? 'DB OK' : 'DB FAIL';"

# فحص الاختبارات (OP-Target)
npm run test
```

### 9.2 تنظيف

```powershell
# تنظيف node_modules
rm -r node_modules
npm install

# تنظيف build cache
rm -r dist
npm run build
```

---

> **آخر تحديث**: 2026-01-05 19:56 UTC+3
