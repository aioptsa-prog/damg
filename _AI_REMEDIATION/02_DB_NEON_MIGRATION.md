# 02_DB_NEON_MIGRATION - تهيئة قاعدة البيانات

---

## 🔌 آلية الاتصال الحالية

### الملف: `api/_db.ts`

```typescript
// Fail-closed: يتطلب DATABASE_URL
const connectionString = process.env.DATABASE_URL;
if (!connectionString) {
  throw new Error('DATABASE_URL environment variable is required');
}

const pool = new Pool({
  connectionString,
  ssl: { rejectUnauthorized: false }
});
```

---

## 📊 متغيرات Neon

| المتغير | الاستخدام |
|---------|-----------|
| `DATABASE_URL` | Pooled - للتشغيل العادي |
| `DATABASE_URL_UNPOOLED` | Direct - للـ migrations |
| `PGHOST` | Host فقط |
| `PGUSER` | Username |
| `PGPASSWORD` | Password |
| `PGDATABASE` | Database name |

---

## 🗄️ Schema (مُستنتج من الكود)

```sql
-- Users
CREATE TABLE users (
    id VARCHAR(50) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255),
    role VARCHAR(20) DEFAULT 'SALES_REP',
    team_id VARCHAR(50),
    avatar TEXT,
    is_active BOOLEAN DEFAULT true,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Leads
CREATE TABLE leads (
    id VARCHAR(50) PRIMARY KEY,
    company_name VARCHAR(255) NOT NULL,
    activity TEXT,
    city VARCHAR(100),
    status VARCHAR(20) DEFAULT 'NEW',
    owner_user_id VARCHAR(50) REFERENCES users(id),
    team_id VARCHAR(50),
    sector JSONB,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Reports
CREATE TABLE reports (
    id VARCHAR(50) PRIMARY KEY,
    lead_id VARCHAR(50) REFERENCES leads(id),
    version_number INTEGER,
    provider VARCHAR(20),
    output JSONB,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Activities
CREATE TABLE activities (
    id VARCHAR(50) PRIMARY KEY,
    lead_id VARCHAR(50),
    user_id VARCHAR(50),
    type VARCHAR(50),
    payload JSONB,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Tasks
CREATE TABLE tasks (
    id VARCHAR(50) PRIMARY KEY,
    lead_id VARCHAR(50),
    assigned_to_user_id VARCHAR(50),
    status VARCHAR(20) DEFAULT 'OPEN',
    due_date TIMESTAMP
);

-- Settings
CREATE TABLE settings (
    key VARCHAR(100) PRIMARY KEY,
    value JSONB,
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Audit Logs
CREATE TABLE audit_logs (
    id VARCHAR(50) PRIMARY KEY,
    actor_user_id VARCHAR(50),
    action VARCHAR(100),
    entity_type VARCHAR(50),
    entity_id VARCHAR(50),
    before JSONB,
    after JSONB,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Teams
CREATE TABLE teams (
    id VARCHAR(50) PRIMARY KEY,
    name VARCHAR(255),
    manager_user_id VARCHAR(50)
);
```

---

## 🔄 إعداد Database جديدة

```bash
# 1. أنشئ database في Neon
# 2. انسخ connection string
# 3. أضفه في .env
DATABASE_URL=postgresql://...

# 4. شغّل الـ schema
psql $DATABASE_URL < database_schema.sql

# 5. أضف admin user
INSERT INTO users (id, name, email, password_hash, role, is_active)
VALUES ('admin-001', 'Admin', 'admin@example.com', 
        '$2b$10$...', 'SUPER_ADMIN', true);
```
