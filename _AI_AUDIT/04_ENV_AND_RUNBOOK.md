# 04_ENV_AND_RUNBOOK - بيئة التشغيل ودليل التشغيل

## ما تم فحصه
- ✅ `package.json`, `vite.config.ts`
- ✅ `docker-compose.yml`, `nginx.conf`
- ✅ `.env.example` (فارغ!)

## ما لم يتم فحصه
- ⚠️ التشغيل الفعلي (npm install أُلغي)
- ⚠️ اتصال قاعدة البيانات

---

## 📋 متطلبات البيئة

### المتطلبات الأساسية

| المتطلب | الإصدار | الدليل |
|---------|---------|---------|
| Node.js | 20+ | `README.md:18` |
| npm/yarn | أحدث | - |
| PostgreSQL | 15+ | `docker-compose.yml:18` |

### التبعيات (package.json)

```json
{
  "dependencies": {
    "react": "^19.2.3",
    "react-dom": "^19.2.3",
    "recharts": "^3.6.0",       // رسوم بيانية
    "@google/genai": "^1.34.0", // Gemini AI
    "lucide-react": "^0.562.0", // أيقونات
    "vitest": "^4.0.16",        // اختبارات
    "pg": "^8.16.3"             // PostgreSQL
  },
  "devDependencies": {
    "@types/node": "^22.14.0",
    "@vitejs/plugin-react": "^5.0.0",
    "typescript": "~5.8.2",
    "vite": "^6.2.0"
  }
}
```

---

## ⚙️ متغيرات البيئة المطلوبة

> ⚠️ **تحذير:** ملف `.env.example` فارغ في المشروع!

### المتغيرات المستنتجة من الكود:

| المتغير | الوصف | الموقع في الكود |
|---------|-------|-----------------|
| `DATABASE_URL` | رابط PostgreSQL (Neon) | `api/_db.ts:8` |
| `GEMINI_API_KEY` | مفتاح Google Gemini | `vite.config.ts:14-15` |
| `API_KEY` | (بديل) مفتاح AI | `aiService.ts:202` |
| `JWT_SECRET` | مفتاح JWT (غير مستخدم فعلياً) | `docker-compose.yml:12` |

### مثال على `.env` صحيح:

```bash
# قاعدة البيانات (Neon PostgreSQL)
DATABASE_URL=postgresql://user:password@host.neon.tech:5432/database?sslmode=require

# الذكاء الاصطناعي
GEMINI_API_KEY=AIza...your-key-here
OPENAI_API_KEY=sk-...your-key-here  # اختياري

# أمان (غير مُطبق حالياً لكن موجود في Docker)
JWT_SECRET=your-secret-key-here
```

---

## 🚀 خطوات التشغيل المحلي

### الطريقة 1: التشغيل المباشر (Vite Dev Server)

```bash
# 1. الانتقال للمشروع
cd d:\projects\OP-Target-Sales-Hub-1

# 2. تثبيت التبعيات
npm install

# 3. إنشاء ملف البيئة
copy .env.example .env
# ثم تعديل .env بالقيم الصحيحة

# 4. التشغيل
npm run dev

# التطبيق يعمل على: http://localhost:3000
```

### الطريقة 2: Docker Compose

```bash
# 1. تعديل متغيرات البيئة في docker-compose.yml
# أو استخدام ملف .env

# 2. البناء والتشغيل
docker-compose up -d --build

# التطبيق على المنفذ 3000
# قاعدة البيانات على المنفذ 5432
```

---

## 📝 أوامر البناء والتطوير

| الأمر | الوصف |
|-------|-------|
| `npm run dev` | تشغيل خادم التطوير |
| `npm run build` | بناء الإصدار الإنتاجي |
| `npm run preview` | معاينة الإصدار الإنتاجي |
| `npm test` | تشغيل الاختبارات (vitest) |

---

## 🔧 تكوين Vite

```typescript
// vite.config.ts
export default defineConfig({
  server: {
    port: 3000,
    host: '0.0.0.0',  // يسمح بالوصول من الشبكة
  },
  define: {
    'process.env.API_KEY': JSON.stringify(env.GEMINI_API_KEY),
  }
});
```

> ⚠️ **مشكلة أمنية:** يتم حقن `GEMINI_API_KEY` في الـ Frontend bundle!

---

## 🐛 مشاكل التشغيل المتوقعة وحلولها

### المشكلة 1: فشل الاتصال بقاعدة البيانات

**الأعراض:**
```
Error: connect ECONNREFUSED
```

**الحل:**
1. تأكد من وجود `DATABASE_URL` في `.env`
2. تأكد من صحة الـ SSL settings في Neon
3. تحقق من whitelist IP addresses

---

### المشكلة 2: فشل توليد التقارير (AI)

**الأعراض:**
```
AI_CONFIG_ERROR: يرجى ضبط مفتاح الـ API
```

**الحل:**
1. تأكد من إضافة `GEMINI_API_KEY` في `.env`
2. أو ضبط المفتاح من "الإعدادات" في الواجهة
3. تحقق من صلاحية المفتاح في Google Cloud Console

---

### المشكلة 3: صفحة بيضاء بعد الدخول

**الأعراض:**
- تسجيل دخول ناجح لكن Dashboard فارغ

**الحلول المحتملة:**
1. تحقق من Console للأخطاء JavaScript
2. تأكد من وجود جداول قاعدة البيانات (لا يوجد migration!)
3. أنشئ الجداول يدوياً (راجع `05_DATABASE_AND_DATA.md`)

---

### المشكلة 4: Rate Limit Error

**الأعراض:**
```
AUTH_LOCKED: تم تجاوز محاولات الدخول
```

**الحل:**
```javascript
// في Browser Console:
localStorage.removeItem('rate_limit_LOGIN_ATTEMPT_email@example.com');
```

> ⚠️ هذا يثبت أن Rate Limiting قابل للتحايل!

---

## 🌐 تكوين Nginx (الإنتاج)

```nginx
# deployment/nginx.conf (مستنتج)
server {
    listen 80;
    server_name yourdomain.com;
    
    location / {
        proxy_pass http://localhost:3000;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection 'upgrade';
    }
    
    location /api {
        proxy_pass http://localhost:3000;
    }
}
```

---

## 📊 متطلبات الموارد (تقديرية)

| المورد | الحد الأدنى | المُوصى |
|--------|-------------|---------|
| RAM | 512MB | 1GB |
| CPU | 1 core | 2 cores |
| Storage | 200MB | 500MB |
| Database | PostgreSQL 15 | Neon Serverless |
