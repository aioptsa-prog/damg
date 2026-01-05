# 03_DB_CONNECTION_GUIDE - دليل اتصال قاعدة البيانات

**التاريخ:** 2026-01-03  
**قاعدة البيانات:** PostgreSQL via Neon

---

## 🔌 طريقة الاتصال

### المتغير الأساسي

```bash
DATABASE_URL=postgresql://user:password@host.neon.tech:5432/database?sslmode=require
```

### Fail-Closed Behavior

```typescript
// api/_db.ts
const connectionString = process.env.DATABASE_URL;
if (!connectionString) {
  throw new Error('DATABASE_URL environment variable is required');
}
```

إذا لم يتم ضبط `DATABASE_URL`:
- ❌ التطبيق لن يعمل
- ❌ رسالة خطأ واضحة
- ✅ لا تسريب أو سلوك غير متوقع

---

## 🏊 Pooled vs Unpooled Connections

### متى تستخدم Pooled (DATABASE_URL):
- ✅ عمليات API العادية (CRUD)
- ✅ Serverless functions (Vercel, etc.)
- ✅ Connections قصيرة

### متى تستخدم Unpooled (DATABASE_URL_UNPOOLED):
- ✅ Migrations وDDL operations
- ✅ Long-running transactions
- ✅ Prepared statements معقدة

```bash
# في .env
DATABASE_URL=postgresql://...@ep-xxx.pooler.neon.tech/db
DATABASE_URL_UNPOOLED=postgresql://...@ep-xxx.neon.tech/db
```

---

## ⚠️ قيود PgBouncer (Neon Pooler)

عند استخدام Pooled connection:

1. **تجنب PREPARE/EXECUTE المباشر:**
   ```sql
   -- ❌ لا تستخدم
   PREPARE stmt AS SELECT ...;
   EXECUTE stmt;
   
   -- ✅ استخدم parameterized queries
   SELECT * FROM leads WHERE id = $1
   ```

2. **تجنب Long Transactions:**
   ```typescript
   // ❌ لا تستخدم
   await pool.query('BEGIN');
   // ... عمليات طويلة
   await pool.query('COMMIT');
   
   // ✅ استخدم single queries
   await pool.query('INSERT ... RETURNING *');
   ```

3. **تجنب Session-level settings:**
   ```sql
   -- ❌ لا يعمل مع pooler
   SET timezone = 'UTC';
   ```

---

## 🔧 إعداد البيئة المحلية

### الخيار 1: Neon (Recommended)

1. أنشئ حساب على [neon.tech](https://neon.tech)
2. أنشئ database جديد
3. انسخ connection string
4. أضفه في `.env`:
   ```bash
   DATABASE_URL=postgresql://...
   ```

### الخيار 2: Docker (للتطوير المحلي)

```yaml
# docker-compose.yml
services:
  db:
    image: postgres:15
    environment:
      POSTGRES_USER: opt_user
      POSTGRES_PASSWORD: ${DB_PASSWORD:-dev_password}
      POSTGRES_DB: op_target
    ports:
      - "5432:5432"
```

```bash
# .env
DATABASE_URL=postgresql://opt_user:dev_password@localhost:5432/op_target
```

---

## 📝 تهيئة الجداول

> ⚠️ لا تزال `database_schema.sql` في المشروع فارغة.
> استخدم الـ schema المُستنتج من `_AI_AUDIT/05_DATABASE_AND_DATA.md`

```sql
-- إنشاء الجداول الأساسية
CREATE TABLE users (
    id VARCHAR(50) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255),
    role VARCHAR(20) NOT NULL DEFAULT 'SALES_REP',
    team_id VARCHAR(50),
    avatar TEXT,
    is_active BOOLEAN DEFAULT true,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- باقي الجداول: leads, reports, activities, tasks, audit_logs, settings
-- راجع 05_DATABASE_AND_DATA.md للـ schema الكامل
```

---

## ✅ التحقق من الاتصال

```bash
# اختبار الاتصال
npm run dev

# إذا نجح:
# - لا أخطاء في Console
# - Dashboard يعرض بيانات

# إذا فشل:
# Error: DATABASE_URL environment variable is required
# → أضف DATABASE_URL في .env
```

---

## 🔐 أمان الاتصال

1. **لا تضع DATABASE_URL في:**
   - الكود المصدري
   - Frontend/VITE_* variables
   - Logs أو output

2. **استخدم:**
   - Environment variables فقط
   - Secrets management للإنتاج
   - IP allowlisting في Neon
