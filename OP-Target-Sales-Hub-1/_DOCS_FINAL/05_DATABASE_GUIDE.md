# 05_DATABASE_GUIDE - دليل قاعدة البيانات

---

## 🐘 Neon PostgreSQL

### Pooled vs Unpooled

| Use Case | Variable | When |
|----------|----------|------|
| Runtime API | `DATABASE_URL` (pooled) | Normal CRUD |
| Migrations | `DATABASE_URL_UNPOOLED` | DDL, long transactions |

**Source:** `api/_db.ts` uses `DATABASE_URL` with SSL.

---

## 📊 Schema (Inferred from Code)

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
    must_change_password BOOLEAN DEFAULT false,
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

-- Activities, Tasks, Settings, Audit_logs, Teams
-- (See _AI_AUDIT/05_DATABASE_AND_DATA.md for full schema)
```

---

## 🔌 Connection (Fail-Closed)

**Source:** `api/_db.ts:6-9`

```typescript
const connectionString = process.env.DATABASE_URL;
if (!connectionString) {
  throw new Error('DATABASE_URL environment variable is required');
}
```

---

## ⚠️ PgBouncer Limitations

When using Neon pooled connection:
- ❌ Avoid `PREPARE`/`EXECUTE`
- ❌ Avoid long transactions
- ❌ Avoid session-level settings
- ✅ Use parameterized queries

---

## 💾 Backup/Restore

Neon provides:
- Automatic point-in-time recovery
- Branch database for testing
- Export via `pg_dump`

```bash
pg_dump $DATABASE_URL > backup.sql
psql $DATABASE_URL < backup.sql
```
