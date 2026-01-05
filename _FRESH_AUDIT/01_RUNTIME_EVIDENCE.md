# 01_RUNTIME_EVIDENCE - أدلة التشغيل

**تاريخ التدقيق:** 2026-01-03  
**البيئة:** Windows, Node.js, localhost

---

## 🔧 npm install

```
✅ Status: SUCCESS
Duration: ~2s
Packages: 219 audited
Vulnerabilities: 0
Funding: 39 packages looking for funding
```

**الأمر:**
```bash
npm install
```

**النتيجة:**
```
up to date, audited 219 packages in 2s
39 packages are looking for funding
found 0 vulnerabilities
```

---

## 🏗️ npm run build

```
✅ Status: SUCCESS
Duration: 7.23s
Modules: 2354 transformed
Output: dist/index.html + dist/assets/index-BeamBO1s.js
```

**الأمر:**
```bash
npm run build
```

**النتيجة:**
```
vite v6.4.1 building for production...
✓ 2354 modules transformed.
dist/index.html                  2.37 kB │ gzip:   0.99 kB
dist/assets/index-BeamBO1s.js  984.6 kB │ gzip: 282.71 kB
✓ built in 7.23s
```

**⚠️ تحذير:**
```
Some chunks are larger than 500 kB after minification.
Consider using dynamic import() to code-split the application
```

**التوصية:** Bundle size كبير (984KB). يحتاج code splitting.

---

## 🚀 npm run dev (Frontend فقط)

```
✅ Status: SUCCESS
Port: 3003 (3000-3002 were in use)
URL: http://localhost:3003/
```

**الأمر:**
```bash
npm run dev
```

**النتيجة:**
```
VITE v6.4.1  ready in 363 ms
➜  Local:   http://localhost:3003/
➜  Network: http://192.168.20.16:3003/
```

**⚠️ تحذير مهم:**
> `npm run dev` يشغل **Frontend فقط** (Vite).  
> الـ `/api/*` routes هي Vercel Serverless Functions ولن تعمل مع Vite.  
> للتشغيل المحلي الكامل، استخدم `vercel dev`.

---

## 🔧 vercel dev (التشغيل المحلي الكامل)

```
Port: 3000 (default)
URL: http://localhost:3000/
Frontend + API: ✅ يعمل
```

**الأمر:**
```powershell
# أول مرة - تسجيل الدخول
npx vercel login

# التشغيل
npx vercel dev
```

**ملاحظات:**
- يتطلب حساب Vercel (مجاني)
- يقرأ `.env` تلقائياً
- يشغل Frontend + API routes معاً
- Port الافتراضي: 3000

---

## 🌐 Frontend Loading

| البند | الحالة | ملاحظات |
|-------|--------|---------|
| HTML loads | ✅ | RTL Arabic layout |
| React mounts | ✅ | Login screen appears |
| TailwindCSS | ✅ | CDN loaded |
| Fonts (Tajawal) | ✅ | Google Fonts |

**الشاشة الافتراضية:** Login page (لأن لا يوجد session)

---

## 🔌 API Endpoints (من الكود)

| Endpoint | Method | File | Status |
|----------|--------|------|--------|
| `/api/auth` | POST | `api/auth.ts` | ✅ موجود |
| `/api/logout` | POST | `api/logout.ts` | ✅ موجود |
| `/api/me` | GET | `api/me.ts` | ✅ موجود |
| `/api/seed` | POST | `api/seed.ts` | ✅ موجود |
| `/api/leads` | GET/POST/DELETE | `api/leads.ts` | ✅ موجود |
| `/api/users` | GET/POST/DELETE | `api/users.ts` | ✅ موجود |
| `/api/reports` | GET/POST | `api/reports.ts` | ✅ موجود |
| `/api/tasks` | GET/POST/PUT | `api/tasks.ts` | ✅ موجود |
| `/api/activities` | GET/POST | `api/activities.ts` | ✅ موجود |
| `/api/analytics` | GET | `api/analytics.ts` | ✅ موجود |
| `/api/settings` | GET/POST | `api/settings.ts` | ✅ موجود |
| `/api/logs` | GET/POST | `api/logs.ts` | ✅ موجود |
| `/api/change-password` | POST | `api/change-password.ts` | ✅ موجود |
| `/api/reset-password` | POST | `api/reset-password.ts` | ✅ موجود |

**المجموع:** 16 API files

---

## ⚠️ متطلبات التشغيل

### Environment Variables المطلوبة

| Variable | Required | Purpose |
|----------|----------|---------|
| `DATABASE_URL` | ✅ Yes | Neon PostgreSQL connection |
| `JWT_SECRET` | ✅ Yes | JWT signing |
| `SEED_SECRET` | ✅ Yes | Protect seed endpoint |
| `ADMIN_EMAIL` | ✅ Yes | Initial admin email |
| `ADMIN_PASSWORD` | ✅ Yes | Initial admin password |
| `ENCRYPTION_SECRET` | ⚠️ For encryption | Encrypt sensitive data |
| `GEMINI_API_KEY` | Optional | AI provider |
| `NODE_ENV` | Optional | Environment mode |

**المصدر:** `.env.example`

---

## 🔄 خطوات إعادة الإنتاج

### لتشغيل المشروع محلياً (Windows PowerShell):

```powershell
# 1. Clone and install
cd OP-Target-Sales-Hub-1
npm install

# 2. Setup environment
Copy-Item .env.example .env
# Edit .env with your values

# 3. Create database schema (أول مرة فقط)
node database/run-migrations.js

# 4. Seed admin user (أول مرة فقط)
node database/seed-admin.js

# 5. Login to Vercel CLI (أول مرة فقط)
npx vercel login

# 6. Run full dev server (Frontend + API)
npx vercel dev
```

### Seed و Login عبر API (بعد تشغيل vercel dev):

```powershell
# Seed via API (بديل لـ seed-admin.js)
Invoke-RestMethod -Uri "http://localhost:3000/api/seed" `
  -Method POST `
  -ContentType "application/json" `
  -Body '{"secret":"YOUR_SEED_SECRET"}'

# Login
$response = Invoke-RestMethod -Uri "http://localhost:3000/api/auth" `
  -Method POST `
  -ContentType "application/json" `
  -Body '{"email":"admin@optarget.com","password":"Admin@123456"}' `
  -SessionVariable session

# Verify login (using session)
Invoke-RestMethod -Uri "http://localhost:3000/api/me" `
  -Method GET `
  -WebSession $session
```

---

## 📝 ملاحظات إضافية

1. **`npm run dev` vs `vercel dev`:**
   - `npm run dev` = Frontend فقط (Vite)
   - `vercel dev` = Frontend + API routes (التشغيل الكامل)

2. **Port conflicts:** Vite يختار port تلقائياً إذا 3000 مشغول

3. **No .env file:** التطبيق لن يعمل بدون DATABASE_URL (fail-closed)

4. **Build warning:** Bundle size كبير (984KB) يحتاج code splitting

5. **Database scripts:** تقرأ من `.env` فقط، لا hardcoded credentials
