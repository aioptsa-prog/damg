# Migrations System Guide

**تاريخ:** 2026-01-03

---

## 🏗️ نظرة عامة

نظام migrations بسيط ومتتبع:
- **Idempotent:** آمن للتشغيل عدة مرات
- **Tracked:** يسجل الـ migrations المنفذة في جدول `_migrations`
- **Direct Connection:** يستخدم `DATABASE_URL_UNPOOLED` للـ DDL

---

## 📁 هيكل الملفات

```
database/
├── run-migrations.js      # Migration runner الرئيسي
├── seed-admin.js          # (deprecated - use bootstrap-admin.js)
└── migrations/
    ├── 000_create_schema.sql
    ├── 001_add_indexes.sql
    └── 002_add_constraints.sql
```

---

## 🔧 كيف يعمل

### 1. جدول التتبع `_migrations`
```sql
CREATE TABLE IF NOT EXISTS _migrations (
  id SERIAL PRIMARY KEY,
  name VARCHAR(255) UNIQUE NOT NULL,
  executed_at TIMESTAMP DEFAULT NOW()
);
```

### 2. التنفيذ
- يتحقق من الجداول الموجودة
- إذا لم توجد جداول، ينشئ الـ schema كاملاً
- ينشئ الـ indexes
- يسجل الـ migration في `_migrations`

---

## 🚀 تشغيل Migrations

### المتطلبات
- Node.js 20+
- `DATABASE_URL_UNPOOLED` environment variable

### Windows (PowerShell)
```powershell
# 1. حدد connection string
$env:DATABASE_URL_UNPOOLED = "postgresql://user:pass@host/db?sslmode=require"

# 2. شغّل migrations
node database/run-migrations.js

# 3. امسح المتغير
Remove-Item Env:DATABASE_URL_UNPOOLED
```

### Linux/Mac (Bash)
```bash
DATABASE_URL_UNPOOLED="postgresql://..." node database/run-migrations.js
```

---

## 📊 الجداول المُنشأة

| الجدول | الوصف |
|--------|-------|
| `_migrations` | تتبع الـ migrations |
| `teams` | الفرق |
| `users` | المستخدمين |
| `leads` | العملاء المحتملين |
| `reports` | التقارير |
| `tasks` | المهام |
| `activities` | الأنشطة |
| `audit_logs` | سجل التدقيق |
| `usage_logs` | سجل الاستخدام |
| `settings` | الإعدادات |

---

## 🔍 الـ Indexes

```sql
-- Users
idx_users_email
idx_users_team_id
idx_users_role

-- Leads
idx_leads_owner
idx_leads_team
idx_leads_status
idx_leads_created

-- Reports
idx_reports_lead
idx_reports_version

-- Activities
idx_activities_lead
idx_activities_user
idx_activities_created

-- Tasks
idx_tasks_lead
idx_tasks_assigned
idx_tasks_status

-- Logs
idx_audit_logs_created
idx_audit_logs_actor
idx_usage_logs_created
```

---

## 🔄 Rollback Strategy

### الحالة الحالية
- لا يوجد rollback تلقائي
- الـ migrations idempotent (CREATE IF NOT EXISTS)

### في حالة الحاجة للـ rollback
1. **Backup أولاً:**
   ```sql
   -- من Neon Console
   -- أو استخدم pg_dump
   ```

2. **حذف يدوي:**
   ```sql
   -- حذف جدول معين
   DROP TABLE IF EXISTS table_name CASCADE;
   
   -- حذف migration من التتبع
   DELETE FROM _migrations WHERE name = 'migration_name';
   ```

3. **إعادة التشغيل:**
   ```bash
   node database/run-migrations.js
   ```

---

## ⚠️ ملاحظات مهمة

### استخدم UNPOOLED دائماً
```
❌ DATABASE_URL (pooled) - قد يفشل مع DDL
✅ DATABASE_URL_UNPOOLED - يعمل مع DDL
```

### لا تعدل migrations موجودة
- أنشئ migration جديد بدلاً من تعديل القديم
- هذا يضمن consistency عبر البيئات

### تحقق من النتيجة
```sql
-- عرض الـ migrations المنفذة
SELECT * FROM _migrations ORDER BY id;

-- عرض الجداول
SELECT table_name FROM information_schema.tables 
WHERE table_schema = 'public';

-- عرض الـ indexes
SELECT indexname FROM pg_indexes WHERE schemaname = 'public';
```

---

## 📝 إضافة Migration جديد

### 1. أنشئ ملف SQL
```sql
-- database/migrations/003_add_new_column.sql
ALTER TABLE leads ADD COLUMN IF NOT EXISTS priority VARCHAR(20);
```

### 2. عدّل run-migrations.js
```javascript
// أضف في نهاية الـ migrations
await client.query(`
  ALTER TABLE leads ADD COLUMN IF NOT EXISTS priority VARCHAR(20)
`);

// سجّل الـ migration
await client.query(`
  INSERT INTO _migrations (name) VALUES ('003_add_new_column')
  ON CONFLICT (name) DO NOTHING
`);
```

### 3. شغّل
```bash
node database/run-migrations.js
```
