# Seed & Bootstrap Guide

**تاريخ:** 2026-01-03

---

## 🔐 السياسة الأمنية

| البيئة | /api/seed | Bootstrap Script |
|--------|-----------|------------------|
| **Production** | ❌ 404 دائماً | ✅ مسموح |
| **Preview** | ✅ مع SEED_SECRET | ✅ مسموح |
| **Development** | ✅ مع SEED_SECRET | ✅ مسموح |

---

## 📋 Preview/Dev: استخدام /api/seed

### المتطلبات
- `SEED_SECRET` في Environment Variables
- `ADMIN_EMAIL` (اختياري، default: admin@optarget.sa)
- `ADMIN_PASSWORD` (مطلوب)

### الخطوات

#### Windows (PowerShell)
```powershell
# 1. تأكد من وجود ENV variables
$env:SEED_SECRET = "your-secret-here"

# 2. استدعي الـ endpoint
$body = @{ secret = $env:SEED_SECRET } | ConvertTo-Json
Invoke-WebRequest -Uri "https://YOUR-PREVIEW-URL.vercel.app/api/seed" `
  -Method POST `
  -Body $body `
  -ContentType "application/json"
```

#### cURL (Linux/Mac)
```bash
curl -X POST https://YOUR-PREVIEW-URL.vercel.app/api/seed \
  -H "Content-Type: application/json" \
  -d '{"secret": "YOUR_SEED_SECRET"}'
```

### الاستجابات المتوقعة

**نجاح (أول مرة):**
```json
{
  "created": true,
  "message": "Admin user created: admin@optarget.sa"
}
```

**Admin موجود:**
```json
{
  "created": false,
  "message": "SUPER_ADMIN already exists, skipping seed"
}
```

**خطأ في Secret:**
```json
{
  "error": "Invalid seed secret"
}
```

---

## 🏭 Production: استخدام Bootstrap Script

### لماذا Script وليس Endpoint؟
- الـ endpoint `/api/seed` يرجع 404 في Production لأسباب أمنية
- الـ Script يتصل مباشرة بقاعدة البيانات
- يُنفذ مرة واحدة فقط (idempotent)

### المتطلبات
- Node.js 20+
- `DATABASE_URL_UNPOOLED` (اتصال مباشر بـ Neon)
- `ADMIN_EMAIL` (اختياري)
- `ADMIN_PASSWORD` (مطلوب)

### الخطوات

#### Windows (PowerShell)
```powershell
# 1. انتقل لمجلد المشروع
cd D:\projects\OP-Target-Sales-Hub-1

# 2. حدد المتغيرات (لا تحفظها في ملف!)
$env:DATABASE_URL_UNPOOLED = "postgresql://user:pass@host/db?sslmode=require"
$env:ADMIN_EMAIL = "admin@optarget.sa"
$env:ADMIN_PASSWORD = "SecurePassword123!"

# 3. شغّل الـ script
node scripts/bootstrap-admin.js

# 4. امسح المتغيرات بعد الانتهاء
Remove-Item Env:DATABASE_URL_UNPOOLED
Remove-Item Env:ADMIN_PASSWORD
```

#### Linux/Mac (Bash)
```bash
# 1. انتقل لمجلد المشروع
cd /path/to/OP-Target-Sales-Hub-1

# 2. شغّل مع المتغيرات (inline)
DATABASE_URL_UNPOOLED="postgresql://..." \
ADMIN_EMAIL="admin@optarget.sa" \
ADMIN_PASSWORD="SecurePassword123!" \
node scripts/bootstrap-admin.js
```

### الاستجابات المتوقعة

**نجاح:**
```
🔄 Connecting to database...
🔐 Hashing password...
📝 Creating admin: admin@optarget.sa
✅ Admin created successfully!
   Email: admin@optarget.sa
   Note: User must change password on first login.
```

**Admin موجود:**
```
🔄 Connecting to database...
✅ SUPER_ADMIN already exists: admin@optarget.sa
   No action needed.
```

---

## ⚠️ تحذيرات أمنية

1. **لا تحفظ كلمات المرور في ملفات**
   - استخدم environment variables فقط
   - امسحها بعد الانتهاء

2. **استخدم UNPOOLED للـ DDL**
   - `DATABASE_URL_UNPOOLED` للـ migrations و bootstrap
   - `DATABASE_URL` للـ queries العادية

3. **mustChangePassword**
   - الـ admin المُنشأ لديه `mustChangePassword=true`
   - سيُطلب منه تغيير كلمة المرور عند أول تسجيل دخول

4. **Audit Log**
   - كل عملية bootstrap تُسجل في `audit_logs`
   - يمكن تتبع من أنشأ الـ admin ومتى

---

## 🔄 البديل: GitHub Action (Manual Workflow)

يمكن إنشاء GitHub Action لتشغيل bootstrap:

```yaml
# .github/workflows/bootstrap-admin.yml
name: Bootstrap Admin
on:
  workflow_dispatch:
    inputs:
      admin_email:
        description: 'Admin email'
        required: true
        default: 'admin@optarget.sa'

jobs:
  bootstrap:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version: '20'
      - run: npm ci
      - run: node scripts/bootstrap-admin.js
        env:
          DATABASE_URL_UNPOOLED: ${{ secrets.DATABASE_URL_UNPOOLED }}
          ADMIN_EMAIL: ${{ github.event.inputs.admin_email }}
          ADMIN_PASSWORD: ${{ secrets.ADMIN_PASSWORD }}
```

**ملاحظة:** هذا البديل لم يُنفذ بعد. الطريقة الأساسية هي الـ script المحلي.
