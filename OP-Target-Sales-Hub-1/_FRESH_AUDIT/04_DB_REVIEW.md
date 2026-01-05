# 04_DB_REVIEW - مراجعة قاعدة البيانات

**تاريخ التدقيق:** 2026-01-03  
**المنهجية:** Code review لـ `api/_db.ts` + استنتاج Schema من الـ queries

---

## 🔌 1. اتصال Neon PostgreSQL

### Configuration الحالي

**المصدر:** `api/_db.ts:1-18`

```typescript
const connectionString = process.env.DATABASE_URL;
if (!connectionString) {
  throw new Error('DATABASE_URL environment variable is required');
}

const pool = new Pool({
  connectionString: connectionString,
  ssl: {
    rejectUnauthorized: false // مطلوب للاتصال بـ Neon
  }
});
```

### ✅ إيجابيات

| البند | الحالة | الدليل |
|-------|--------|--------|
| Fail-closed | ✅ | يرمي error إذا DATABASE_URL غير موجود |
| SSL enabled | ✅ | `ssl: { rejectUnauthorized: false }` |
| Connection pooling | ✅ | يستخدم `pg.Pool` |
| Parameterized queries | ✅ | كل الـ queries تستخدم `$1, $2, ...` |

### ⚠️ ملاحظات

| البند | الحالة | التوصية |
|-------|--------|---------|
| `rejectUnauthorized: false` | ⚠️ | مقبول لـ Neon، لكن يجب التحقق من الـ cert في production |
| Pool size | غير محدد | إضافة `max: 10` للتحكم |
| Connection timeout | غير محدد | إضافة `connectionTimeoutMillis` |

---

## 📊 2. Schema (مستنتج من الكود)

### 2.1 جدول `users`

**المصدر:** `api/auth.ts:90-92`, `api/seed.ts:46-49`

```sql
CREATE TABLE users (
    id VARCHAR(50) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255),
    role VARCHAR(20) DEFAULT 'SALES_REP',  -- SUPER_ADMIN, MANAGER, SALES_REP
    team_id VARCHAR(50),
    avatar TEXT,
    is_active BOOLEAN DEFAULT true,
    must_change_password BOOLEAN DEFAULT false,
    created_at TIMESTAMP DEFAULT NOW()
);
```

**Indexes المطلوبة:**
```sql
CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_users_team_id ON users(team_id);
CREATE INDEX idx_users_role ON users(role);
```

### 2.2 جدول `leads`

**المصدر:** `api/leads.ts`, `api/_auth.ts:117-119`

```sql
CREATE TABLE leads (
    id VARCHAR(50) PRIMARY KEY,
    company_name VARCHAR(255) NOT NULL,
    activity TEXT,
    city VARCHAR(100),
    size VARCHAR(50),
    website TEXT,
    notes TEXT,
    sector JSONB,
    status VARCHAR(20) DEFAULT 'NEW',
    owner_user_id VARCHAR(50) REFERENCES users(id),
    team_id VARCHAR(50),
    created_at TIMESTAMP DEFAULT NOW(),
    last_activity_at TIMESTAMP,
    created_by VARCHAR(50),
    phone VARCHAR(50),
    custom_fields JSONB,
    attachments JSONB,
    decision_maker_name VARCHAR(255),
    decision_maker_role VARCHAR(255),
    contact_email VARCHAR(255),
    budget_range VARCHAR(50),
    goal_primary TEXT,
    timeline VARCHAR(100),
    transcript TEXT,
    enrichment_signals JSONB
);
```

**Indexes المطلوبة:**
```sql
CREATE INDEX idx_leads_owner ON leads(owner_user_id);
CREATE INDEX idx_leads_team ON leads(team_id);
CREATE INDEX idx_leads_status ON leads(status);
CREATE INDEX idx_leads_created ON leads(created_at DESC);
```

### 2.3 جدول `reports`

**المصدر:** `api/reports.ts`

```sql
CREATE TABLE reports (
    id VARCHAR(50) PRIMARY KEY,
    lead_id VARCHAR(50) REFERENCES leads(id),
    version_number INTEGER,
    provider VARCHAR(20),  -- gemini, openai
    model VARCHAR(100),
    prompt_version VARCHAR(50),
    output JSONB,
    change_log TEXT,
    usage JSONB,
    created_at TIMESTAMP DEFAULT NOW()
);
```

**Indexes المطلوبة:**
```sql
CREATE INDEX idx_reports_lead ON reports(lead_id);
CREATE INDEX idx_reports_version ON reports(lead_id, version_number DESC);
```

### 2.4 جدول `tasks`

**المصدر:** `api/tasks.ts`

```sql
CREATE TABLE tasks (
    id VARCHAR(50) PRIMARY KEY,
    lead_id VARCHAR(50) REFERENCES leads(id),
    assigned_to_user_id VARCHAR(50) REFERENCES users(id),
    day_number INTEGER,
    channel VARCHAR(20),  -- call, whatsapp, email
    goal TEXT,
    action TEXT,
    status VARCHAR(20) DEFAULT 'OPEN',  -- OPEN, DONE, SKIPPED
    due_date TIMESTAMP
);
```

### 2.5 جدول `activities`

**المصدر:** `api/activities.ts`

```sql
CREATE TABLE activities (
    id VARCHAR(50) PRIMARY KEY,
    lead_id VARCHAR(50) REFERENCES leads(id),
    user_id VARCHAR(50) REFERENCES users(id),
    type VARCHAR(50),  -- status_change, note, call_result, etc.
    payload JSONB,
    created_at TIMESTAMP DEFAULT NOW()
);
```

### 2.6 جدول `audit_logs`

**المصدر:** `api/auth.ts:99-102`, `api/logs.ts`

```sql
CREATE TABLE audit_logs (
    id VARCHAR(50) PRIMARY KEY,
    actor_user_id VARCHAR(50),
    action VARCHAR(100),
    entity_type VARCHAR(50),
    entity_id VARCHAR(100),
    before JSONB,
    after JSONB,
    created_at TIMESTAMP DEFAULT NOW()
);
```

### 2.7 جدول `settings`

**المصدر:** `api/settings.ts`

```sql
CREATE TABLE settings (
    key VARCHAR(100) PRIMARY KEY,
    value JSONB,
    updated_at TIMESTAMP DEFAULT NOW()
);
```

### 2.8 جدول `usage_logs`

**المصدر:** `api/logs.ts:36`

```sql
CREATE TABLE usage_logs (
    id VARCHAR(50) PRIMARY KEY,
    model VARCHAR(100),
    provider VARCHAR(20),
    latency_ms INTEGER,
    input_tokens INTEGER,
    output_tokens INTEGER,
    cost DECIMAL(10, 6),
    status VARCHAR(20),
    error TEXT,
    created_at TIMESTAMP DEFAULT NOW()
);
```

### 2.9 جدول `teams`

**المصدر:** `types.ts:8-12`

```sql
CREATE TABLE teams (
    id VARCHAR(50) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    manager_user_id VARCHAR(50) REFERENCES users(id)
);
```

---

## ⚠️ 3. PgBouncer Limitations

عند استخدام Neon pooled connection:

| ❌ تجنب | ✅ استخدم |
|---------|----------|
| `PREPARE` / `EXECUTE` | Parameterized queries |
| Long transactions | Short transactions |
| Session-level settings | Connection-level only |
| `LISTEN` / `NOTIFY` | Polling or webhooks |

**الحالة الحالية:** ✅ الكود يستخدم parameterized queries فقط

---

## 🔍 4. Data Integrity Gaps

### 4.1 Foreign Keys

| Relation | Status | Risk |
|----------|--------|------|
| leads.owner_user_id → users.id | ⚠️ غير مؤكد | Orphan leads |
| reports.lead_id → leads.id | ⚠️ غير مؤكد | Orphan reports |
| tasks.lead_id → leads.id | ⚠️ غير مؤكد | Orphan tasks |
| activities.lead_id → leads.id | ⚠️ غير مؤكد | Orphan activities |

**التوصية:** التحقق من وجود FK constraints في الـ database

### 4.2 Constraints المفقودة

```sql
-- Email uniqueness
ALTER TABLE users ADD CONSTRAINT users_email_unique UNIQUE (email);

-- Status enum
ALTER TABLE leads ADD CONSTRAINT leads_status_check 
  CHECK (status IN ('NEW', 'CONTACTED', 'FOLLOW_UP', 'INTERESTED', 'WON', 'LOST'));

-- Role enum
ALTER TABLE users ADD CONSTRAINT users_role_check 
  CHECK (role IN ('SUPER_ADMIN', 'MANAGER', 'SALES_REP'));
```

### 4.3 Cascade Delete

**غير مؤكد:** هل حذف user يحذف leads الخاصة به؟

**التوصية:**
```sql
ALTER TABLE leads 
  ADD CONSTRAINT fk_leads_owner 
  FOREIGN KEY (owner_user_id) 
  REFERENCES users(id) 
  ON DELETE SET NULL;
```

---

## 📈 5. Performance Considerations

### 5.1 Missing Pagination

**المشكلة:** كل الـ queries تجلب كل البيانات

```typescript
// api/leads.ts - No LIMIT
leadsRes = await query('SELECT * FROM leads ORDER BY created_at DESC');
```

**التوصية:**
```typescript
const { limit = 50, offset = 0 } = queryParams;
leadsRes = await query(
  'SELECT * FROM leads ORDER BY created_at DESC LIMIT $1 OFFSET $2',
  [limit, offset]
);
```

### 5.2 N+1 Queries

**المشكلة:** `canAccessLead()` يعمل query لكل lead

**التوصية:** Batch check أو JOIN في الـ query الأصلي

### 5.3 Missing Indexes

| Table | Column | Query Pattern |
|-------|--------|---------------|
| leads | owner_user_id | WHERE owner_user_id = $1 |
| leads | team_id | WHERE team_id = $1 |
| leads | status | GROUP BY status |
| activities | lead_id | WHERE lead_id = $1 |
| tasks | lead_id | WHERE lead_id = $1 |
| audit_logs | created_at | ORDER BY created_at DESC |

---

## 🔐 6. Security Considerations

### 6.1 SQL Injection Protection

**الحالة:** ✅ محمي

كل الـ queries تستخدم parameterized queries:
```typescript
await query('SELECT * FROM users WHERE email = $1', [email]);
```

### 6.2 Sensitive Data

| Column | Table | Protection |
|--------|-------|------------|
| password_hash | users | ✅ Never returned in API |
| api keys | settings | ✅ Masked in response |

---

## 📋 7. توصيات عملية

### P0 - ضروري

1. **التحقق من FK constraints** في Neon dashboard
2. **إضافة indexes** للـ columns المستخدمة في WHERE/ORDER BY

### P1 - مهم

3. **إضافة pagination** لكل GET endpoints
4. **إضافة connection pool limits**:
```typescript
const pool = new Pool({
  connectionString,
  ssl: { rejectUnauthorized: false },
  max: 10,
  connectionTimeoutMillis: 5000,
  idleTimeoutMillis: 30000
});
```

### P2 - تحسينات

5. **إضافة database migrations** (Prisma أو Drizzle)
6. **إضافة health check endpoint** للـ database
7. **إضافة query logging** للـ debugging

---

## 🔄 8. Migration Script (مقترح)

```sql
-- 001_add_indexes.sql
CREATE INDEX IF NOT EXISTS idx_leads_owner ON leads(owner_user_id);
CREATE INDEX IF NOT EXISTS idx_leads_team ON leads(team_id);
CREATE INDEX IF NOT EXISTS idx_leads_status ON leads(status);
CREATE INDEX IF NOT EXISTS idx_activities_lead ON activities(lead_id);
CREATE INDEX IF NOT EXISTS idx_tasks_lead ON tasks(lead_id);
CREATE INDEX IF NOT EXISTS idx_audit_logs_created ON audit_logs(created_at DESC);

-- 002_add_constraints.sql
ALTER TABLE users ADD CONSTRAINT IF NOT EXISTS users_email_unique UNIQUE (email);
ALTER TABLE leads ADD CONSTRAINT IF NOT EXISTS leads_status_check 
  CHECK (status IN ('NEW', 'CONTACTED', 'FOLLOW_UP', 'INTERESTED', 'WON', 'LOST'));
```
