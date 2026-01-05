# 05_DATABASE_AND_DATA - قاعدة البيانات والبيانات

## ما تم فحصه
- ✅ `api/_db.ts` (اتصال PostgreSQL)
- ✅ جميع ملفات API (استنتاج الجداول)
- ✅ `types.ts` (نماذج البيانات)

## ما لم يتم فحصه
- ⚠️ قاعدة البيانات الفعلية (لا اتصال)
- ⚠️ `database_schema.sql` فارغ!

---

## 🚨 مشكلة حرجة

> **ملف `database_schema.sql` فارغ تماماً!**
> 
> لا توجد طريقة موثقة لإنشاء الجداول. 
> المخطط التالي مُستنتج من تحليل كود الـ API.

---

## 📊 مخطط قاعدة البيانات (ERD مُستنتج)

```
┌────────────────────────────────────────────────────────────────┐
│                         DATABASE SCHEMA                        │
│                    (Inferred from API code)                    │
└────────────────────────────────────────────────────────────────┘

┌─────────────────────┐       ┌─────────────────────┐
│       users         │       │       teams         │
├─────────────────────┤       ├─────────────────────┤
│ id (PK)            │───────│ id (PK)            │
│ name               │       │ name               │
│ email (UNIQUE)     │       │ manager_user_id FK │◄──┐
│ password_hash      │       └─────────────────────┘   │
│ role               │                                  │
│ team_id (FK)  ─────┼──────────────────────────────────┘
│ avatar             │
│ is_active          │
└─────────────────────┘
         │
         │ owner_user_id
         ▼
┌─────────────────────┐       ┌─────────────────────┐
│       leads         │       │      reports        │
├─────────────────────┤       ├─────────────────────┤
│ id (PK)            │───────▶│ id (PK)            │
│ company_name       │       │ lead_id (FK)       │
│ activity           │       │ version_number     │
│ city               │       │ provider           │
│ size               │       │ model              │
│ website            │       │ prompt_version     │
│ notes              │       │ output (JSONB)     │
│ sector (JSONB)     │       │ usage (JSONB)      │
│ status             │       │ created_at         │
│ owner_user_id (FK) │       └─────────────────────┘
│ team_id (FK)       │
│ created_at         │
│ last_activity_at   │
│ phone              │
│ custom_fields JSON │
│ attachments JSON   │
│ decision_maker_*   │
│ budget_range       │
│ enrichment_signals │
└─────────────────────┘
         │
         │ lead_id
         ▼
┌─────────────────────┐       ┌─────────────────────┐
│     activities      │       │       tasks         │
├─────────────────────┤       ├─────────────────────┤
│ id (PK)            │       │ id (PK)            │
│ lead_id (FK)       │       │ lead_id (FK)       │
│ user_id (FK)       │       │ assigned_to_user_id│
│ type               │       │ day_number         │
│ payload (JSONB)    │       │ channel            │
│ created_at         │       │ goal               │
└─────────────────────┘       │ action             │
                              │ status             │
                              │ due_date           │
                              └─────────────────────┘

┌─────────────────────┐       ┌─────────────────────┐
│     audit_logs      │       │      settings       │
├─────────────────────┤       ├─────────────────────┤
│ id (PK)            │       │ key (PK)           │
│ actor_user_id      │       │ value (JSONB)      │
│ action             │       │ updated_at         │
│ entity_type        │       └─────────────────────┘
│ entity_id          │
│ before (JSONB)     │
│ after (JSONB)      │
│ created_at         │
└─────────────────────┘
```

---

## 📝 SQL لإنشاء الجداول (مُقترح)

```sql
-- users table
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

-- teams table
CREATE TABLE teams (
    id VARCHAR(50) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    manager_user_id VARCHAR(50) REFERENCES users(id)
);

-- leads table
CREATE TABLE leads (
    id VARCHAR(50) PRIMARY KEY,
    company_name VARCHAR(255) NOT NULL,
    activity TEXT,
    city VARCHAR(100),
    size VARCHAR(50),
    website VARCHAR(500),
    notes TEXT,
    sector JSONB,
    status VARCHAR(20) DEFAULT 'NEW',
    owner_user_id VARCHAR(50) REFERENCES users(id),
    team_id VARCHAR(50) REFERENCES teams(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_activity_at TIMESTAMP,
    created_by VARCHAR(255),
    phone VARCHAR(50),
    custom_fields JSONB DEFAULT '[]',
    attachments JSONB DEFAULT '[]',
    decision_maker_name VARCHAR(255),
    decision_maker_role VARCHAR(255),
    contact_email VARCHAR(255),
    budget_range VARCHAR(50),
    goal_primary TEXT,
    timeline VARCHAR(100),
    transcript TEXT,
    enrichment_signals JSONB
);

-- reports table
CREATE TABLE reports (
    id VARCHAR(50) PRIMARY KEY,
    lead_id VARCHAR(50) REFERENCES leads(id) ON DELETE CASCADE,
    version_number INTEGER NOT NULL,
    provider VARCHAR(20) NOT NULL,
    model VARCHAR(100),
    prompt_version VARCHAR(50),
    output JSONB NOT NULL,
    change_log TEXT,
    usage JSONB,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- activities table
CREATE TABLE activities (
    id VARCHAR(50) PRIMARY KEY,
    lead_id VARCHAR(50) REFERENCES leads(id) ON DELETE CASCADE,
    user_id VARCHAR(50) REFERENCES users(id),
    type VARCHAR(50) NOT NULL,
    payload JSONB,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- tasks table
CREATE TABLE tasks (
    id VARCHAR(50) PRIMARY KEY,
    lead_id VARCHAR(50) REFERENCES leads(id) ON DELETE CASCADE,
    assigned_to_user_id VARCHAR(50) REFERENCES users(id),
    day_number INTEGER,
    channel VARCHAR(20),
    goal TEXT,
    action TEXT,
    status VARCHAR(20) DEFAULT 'OPEN',
    due_date TIMESTAMP
);

-- audit_logs table
CREATE TABLE audit_logs (
    id VARCHAR(50) PRIMARY KEY,
    actor_user_id VARCHAR(50),
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50),
    entity_id VARCHAR(50),
    before JSONB,
    after JSONB,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- settings table
CREATE TABLE settings (
    key VARCHAR(100) PRIMARY KEY,
    value JSONB NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Indexes for performance
CREATE INDEX idx_leads_owner ON leads(owner_user_id);
CREATE INDEX idx_leads_status ON leads(status);
CREATE INDEX idx_activities_lead ON activities(lead_id);
CREATE INDEX idx_tasks_lead ON tasks(lead_id);
CREATE INDEX idx_reports_lead ON reports(lead_id);
```

---

## 🔍 تحليل الاستعلامات الموجودة

### من `api/leads.ts`:
```sql
-- Get leads by owner
SELECT * FROM leads 
WHERE owner_user_id = $1 OR $1 IS NULL 
ORDER BY created_at DESC

-- Insert/Update lead
INSERT INTO leads (...) VALUES (...) 
ON CONFLICT (id) DO UPDATE SET ...

-- Delete lead
DELETE FROM leads WHERE id = $1
```

### من `api/analytics.ts`:
```sql
-- Sector distribution (يستخدم JSONB)
SELECT sector->>'primary' as name, COUNT(*) as value 
FROM leads 
WHERE owner_user_id = $1 OR $1 IS NULL 
GROUP BY sector->>'primary'

-- Funnel stats
SELECT status, COUNT(*) as count 
FROM leads 
WHERE owner_user_id = $1 OR $1 IS NULL 
GROUP BY status
```

---

## ⚠️ مشاكل جودة البيانات

| المشكلة | التأثير | المكان |
|---------|---------|--------|
| **لا تحقق من الصلاحيات** | أي مستخدم يمكنه الوصول لبيانات الآخرين | `api/leads.ts:12-14` |
| **ID عشوائي (Math.random)** | احتمال تضارب ضئيل | `LeadForm.tsx:77` |
| **لا Foreign Key enforcement** | يمكن حذف مستخدم وتبقى بياناته | غير مؤكد |
| **JSONB بدون validation** | بيانات غير متسقة ممكنة | `sector`, `output` fields |
| **لا constraints على status** | قيم غير صالحة ممكنة | `leads.status` |

---

## 📈 التوصيات

1. **إنشاء `database_schema.sql` رسمي** مع constraints و indexes
2. **استخدام UUID بدل Math.random** لتوليد IDs
3. **إضافة Row Level Security (RLS)** في PostgreSQL
4. **Migrations system** (مثل Prisma أو Drizzle)
5. **Seed data** لبيئة التطوير
