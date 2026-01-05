# 01_RUNBOOK_AND_ENV - دليل التشغيل

---

## 🚀 خطوات التشغيل

### 1. التثبيت
```bash
cd d:\projects\OP-Target-Sales-Hub-1
npm install
```

### 2. إعداد البيئة
```bash
copy .env.example .env
# ثم عدّل .env بالقيم الصحيحة
```

### 3. التشغيل
```bash
# Development
npm run dev

# Production Build
npm run build
npm run preview
```

---

## ⚙️ متغيرات البيئة

| المتغير | مطلوب | الوصف |
|---------|-------|-------|
| `DATABASE_URL` | ✅ | Neon PostgreSQL (pooled) |
| `JWT_SECRET` | ✅ | لتوقيع JWT (min 32 chars) |
| `ENCRYPTION_SECRET` | ✅ | لتشفير البيانات (min 32 chars) |
| `GEMINI_API_KEY` | ❌ | يمكن ضبطه من UI |
| `NODE_ENV` | ❌ | development/production |

### مثال .env:
```bash
DATABASE_URL=postgresql://user:pass@ep-xxx.neon.tech/db?sslmode=require
JWT_SECRET=your-32-char-secret-here-minimum
ENCRYPTION_SECRET=another-32-char-secret-here
```

---

## 🐘 Neon Database

### Pooled vs Unpooled:

| الاستخدام | المتغير | متى؟ |
|-----------|---------|------|
| Runtime API | `DATABASE_URL` (pooled) | العمليات العادية |
| Migrations | `DATABASE_URL_UNPOOLED` | DDL/Long transactions |

### Connection String Format:
```
# Pooled (للتشغيل)
postgresql://user:pass@ep-xxx.pooler.neon.tech/db

# Unpooled (للـ migrations)
postgresql://user:pass@ep-xxx.neon.tech/db
```

---

## 📝 الأوامر المتاحة

| الأمر | الوصف |
|-------|-------|
| `npm run dev` | تشغيل خادم التطوير (port 3000) |
| `npm run build` | بناء الإنتاج |
| `npm run preview` | معاينة الإنتاج |
| `npm test` | تشغيل الاختبارات |
