# Architecture Document - OP Target Sales Hub

**الإصدار:** 1.0  
**تاريخ:** 2026-01-03

---

## 1. نظرة عامة

```
┌─────────────────────────────────────────────────────────────┐
│                        Client (Browser)                      │
│  ┌─────────────────────────────────────────────────────┐    │
│  │              React 19 + Vite 6 + Tailwind           │    │
│  │         (SPA - Single Page Application)             │    │
│  └─────────────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────────────┘
                              │
                              │ HTTPS
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                     Vercel Edge Network                      │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐      │
│  │  Static CDN  │  │   API Routes │  │  Serverless  │      │
│  │   (dist/)    │  │   (/api/*)   │  │  Functions   │      │
│  └──────────────┘  └──────────────┘  └──────────────┘      │
└─────────────────────────────────────────────────────────────┘
                              │
                              │ PostgreSQL Protocol
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                    Neon PostgreSQL                           │
│  ┌─────────────────────────────────────────────────────┐    │
│  │  users │ leads │ tasks │ reports │ activities │ ... │    │
│  └─────────────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────────────┘
```

---

## 2. Frontend Architecture

### 2.1 هيكل المجلدات
```
/
├── index.html          # Entry point
├── index.tsx           # React root
├── App.tsx             # Main app component
├── components/         # React components
│   ├── Dashboard.tsx
│   ├── LeadsList.tsx
│   ├── LeadDetails.tsx
│   ├── UserManagement.tsx
│   ├── ForceChangePassword.tsx
│   └── ...
├── services/           # Business logic
│   ├── authService.ts
│   ├── db.ts
│   └── rateLimitService.ts
├── types.ts            # TypeScript types
└── src/
    └── index.css       # Tailwind CSS
```

### 2.2 State Management
- **Local State:** React useState/useReducer
- **Auth State:** AuthService singleton
- **Server State:** Direct API calls (no caching layer yet)

### 2.3 Routing
- **Client-side:** Manual state-based routing in App.tsx
- **Pages:** dashboard, leads, users, settings, etc.

---

## 3. Backend Architecture

### 3.1 API Structure
```
/api/
├── _auth.ts            # Auth middleware (helper)
├── _db.ts              # Database connection (helper)
├── schemas.ts          # Zod validation schemas (helper)
├── auth.ts             # POST login, GET me, DELETE logout
├── password.ts         # POST change/reset password
├── users.ts            # CRUD users
├── leads.ts            # CRUD leads
├── tasks.ts            # CRUD tasks
├── reports.ts          # CRUD reports
├── activities.ts       # CRUD activities
├── analytics.ts        # GET analytics
├── settings.ts         # GET/POST settings
├── logs.ts             # Audit/usage logs
└── seed.ts             # Initial admin seed
```

### 3.2 Authentication Flow
```
1. POST /api/auth (login)
   ├── Validate credentials (Zod)
   ├── Check rate limit
   ├── Verify password (bcrypt)
   ├── Generate JWT
   ├── Set HttpOnly cookie
   └── Return user data

2. GET /api/auth (me)
   ├── Extract token from cookie
   ├── Verify JWT signature
   ├── Fetch user from DB
   └── Return user data

3. DELETE /api/auth (logout)
   ├── Clear auth cookie
   └── Log audit event
```

### 3.3 Authorization (RBAC)
```typescript
enum Role {
  SUPER_ADMIN = 'SUPER_ADMIN',  // Full access
  MANAGER = 'MANAGER',          // Team access
  SALES_REP = 'SALES_REP'       // Own data only
}

// Access Matrix
┌─────────────────┬─────────────┬─────────┬───────────┐
│ Resource        │ SUPER_ADMIN │ MANAGER │ SALES_REP │
├─────────────────┼─────────────┼─────────┼───────────┤
│ All Users       │ ✅          │ Team    │ Self      │
│ All Leads       │ ✅          │ Team    │ Own       │
│ All Tasks       │ ✅          │ Team    │ Own       │
│ Settings        │ ✅          │ ❌      │ ❌        │
│ Audit Logs      │ ✅          │ ❌      │ ❌        │
│ User Management │ ✅          │ ❌      │ ❌        │
└─────────────────┴─────────────┴─────────┴───────────┘
```

---

## 4. Database Schema

### 4.1 Core Tables
```sql
-- Users
users (
  id UUID PRIMARY KEY,
  email VARCHAR UNIQUE NOT NULL,
  password_hash VARCHAR NOT NULL,
  name VARCHAR NOT NULL,
  role VARCHAR NOT NULL,  -- SUPER_ADMIN, MANAGER, SALES_REP
  team_id UUID,
  avatar VARCHAR,
  is_active BOOLEAN DEFAULT true,
  must_change_password BOOLEAN DEFAULT false,
  created_at TIMESTAMP,
  updated_at TIMESTAMP
)

-- Leads
leads (
  id UUID PRIMARY KEY,
  company_name VARCHAR NOT NULL,
  contact_name VARCHAR,
  email VARCHAR,
  phone VARCHAR,
  sector VARCHAR,
  status VARCHAR,  -- new, contacted, qualified, proposal, won, lost
  assigned_to UUID REFERENCES users(id),
  created_by UUID REFERENCES users(id),
  created_at TIMESTAMP,
  updated_at TIMESTAMP
)

-- Tasks
tasks (
  id UUID PRIMARY KEY,
  lead_id UUID REFERENCES leads(id),
  assigned_to UUID REFERENCES users(id),
  title VARCHAR NOT NULL,
  description TEXT,
  status VARCHAR,  -- pending, in_progress, completed
  channel VARCHAR,
  due_date TIMESTAMP,
  created_at TIMESTAMP
)

-- Reports
reports (
  id UUID PRIMARY KEY,
  lead_id UUID REFERENCES leads(id),
  generated_by UUID REFERENCES users(id),
  content JSONB,
  created_at TIMESTAMP
)

-- Activities
activities (
  id UUID PRIMARY KEY,
  lead_id UUID REFERENCES leads(id),
  user_id UUID REFERENCES users(id),
  type VARCHAR,
  description TEXT,
  created_at TIMESTAMP
)

-- Audit Logs
audit_logs (
  id UUID PRIMARY KEY,
  actor_user_id VARCHAR,
  action VARCHAR,
  entity_type VARCHAR,
  entity_id VARCHAR,
  details JSONB,
  created_at TIMESTAMP
)
```

### 4.2 Indexes
```sql
CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_leads_assigned_to ON leads(assigned_to);
CREATE INDEX idx_leads_status ON leads(status);
CREATE INDEX idx_tasks_lead_id ON tasks(lead_id);
CREATE INDEX idx_activities_lead_id ON activities(lead_id);
CREATE INDEX idx_audit_logs_actor ON audit_logs(actor_user_id);
```

---

## 5. Security Architecture

### 5.1 Authentication
- **Method:** JWT in HttpOnly cookie
- **Algorithm:** HMAC-SHA256
- **Expiry:** 24 hours
- **Refresh:** Not implemented (re-login required)

### 5.2 Password Security
- **Hashing:** bcrypt (10 rounds)
- **Policy:** Minimum 8 characters
- **Reset:** Admin-only, sets must_change_password flag

### 5.3 API Security
- **Rate Limiting:** In-memory (needs Redis for production scale)
- **Input Validation:** Zod schemas
- **SQL Injection:** Parameterized queries
- **CORS:** Vercel default (same-origin)

### 5.4 Secrets Management
```
Environment Variables (Vercel):
├── DATABASE_URL          # Neon connection string
├── JWT_SECRET            # JWT signing key
├── ENCRYPTION_SECRET     # Data encryption key
├── SEED_SECRET           # Seed endpoint protection
├── ADMIN_EMAIL           # Initial admin email
└── ADMIN_PASSWORD        # Initial admin password
```

---

## 6. Deployment Architecture

### 6.1 Vercel Configuration
```json
{
  "framework": "vite",
  "buildCommand": "npm run build",
  "outputDirectory": "dist"
}
```

### 6.2 Build Pipeline
```
1. npm install
2. npm run build (vite build)
3. Deploy static files to CDN
4. Deploy API functions to serverless
```

### 6.3 Environment Strategy
```
┌─────────────┬─────────────────────────────────────┐
│ Environment │ Purpose                             │
├─────────────┼─────────────────────────────────────┤
│ Development │ Local development (vercel dev)      │
│ Preview     │ PR previews, testing                │
│ Production  │ Live site                           │
└─────────────┴─────────────────────────────────────┘
```

---

## 7. Future Considerations

### 7.1 Scalability
- [ ] Redis for rate limiting and caching
- [ ] Database connection pooling
- [ ] CDN for static assets
- [ ] Code splitting for bundle size

### 7.2 Observability
- [ ] Structured logging (JSON)
- [ ] Error tracking (Sentry)
- [ ] Performance monitoring
- [ ] Health check endpoints

### 7.3 Testing
- [ ] Unit tests (Vitest)
- [ ] Integration tests
- [ ] E2E tests (Playwright)
- [ ] Load testing
