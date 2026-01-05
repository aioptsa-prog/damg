# SYSTEM_MAP.md
> خريطة النظام الشاملة
> تاريخ الإنشاء: 2026-01-05

---

## 1. نظرة عامة على المشروع

### معلومات أساسية
| البند | القيمة |
|-------|--------|
| **اسم المشروع** | نظام دمج OP-Target + Forge |
| **مسار المشروع** | `d:\projects\دمج` |
| **بيئة التشغيل** | Windows |
| **قاعدة البيانات** | PostgreSQL (Neon) + SQLite |

### المشاريع الفرعية

```
d:\projects\دمج\
├── OP-Target-Sales-Hub-1/    # Frontend + API (React/TypeScript/Vite)
├── forge.op-tg.com/          # Backend PHP + Worker System
├── integration_docs/         # توثيق التكامل
└── worker.env                # إعدادات Worker
```

---

## 2. المشروع الأول: OP-Target-Sales-Hub-1

### 2.1 التقنيات المستخدمة

| المكون | التقنية | الدليل |
|--------|---------|--------|
| **Frontend Framework** | React 19.2.3 | `package.json:27` |
| **Build Tool** | Vite 6.4.1 | `package.json:41` |
| **Language** | TypeScript 5.9.3 | `package.json:40` |
| **Styling** | TailwindCSS 3.4.19 | `package.json:39` |
| **Database Driver** | pg 8.16.3 | `package.json:25` |
| **AI Integration** | @google/genai 1.34.0 | `package.json:21` |
| **Testing** | Vitest + Playwright | `package.json:33,42` |

### 2.2 بنية المجلدات

```
OP-Target-Sales-Hub-1/
├── api/                    # API Endpoints (Vercel Serverless)
│   ├── _auth.ts           # JWT Authentication middleware
│   ├── _db.ts             # PostgreSQL connection pool
│   ├── _flags.ts          # Feature flags
│   ├── auth.ts            # Login/Logout endpoints
│   ├── leads.ts           # Leads CRUD
│   ├── reports.ts         # AI Report generation
│   ├── users.ts           # User management
│   ├── settings.ts        # System settings
│   ├── tasks.ts           # Tasks management
│   └── integration/       # Cross-project integration APIs
├── components/            # React Components
│   ├── Dashboard.tsx      # Main dashboard
│   ├── LeadDetails.tsx    # Lead detail view
│   ├── LeadForm.tsx       # Lead creation/edit form
│   ├── ReportView.tsx     # AI Report display
│   ├── ForgeIntelTab.tsx  # Forge integration tab
│   ├── SettingsPanel.tsx  # Settings UI
│   └── UserManagement.tsx # User admin
├── services/              # Business Logic Services
│   ├── aiService.ts       # AI provider abstraction
│   ├── authService.ts     # Auth utilities
│   ├── db.ts              # DB service layer
│   ├── whatsappService.ts # WhatsApp integration
│   ├── integrationClient.ts # Forge API client
│   └── featureFlags.ts    # Feature flag service
├── database/              # Database migrations
├── tests/                 # Test files
└── dist/                  # Build output
```

### 2.3 قاعدة البيانات (PostgreSQL - Neon)

| الجدول | الوصف | الدليل |
|--------|-------|--------|
| `users` | المستخدمين والأدوار | `database/migrations/000_create_schema.sql` |
| `leads` | العملاء المحتملين | نفس الملف |
| `reports` | تقارير AI | نفس الملف |
| `tasks` | المهام | نفس الملف |
| `activities` | سجل النشاطات | نفس الملف |
| `audit_logs` | سجل التدقيق | نفس الملف |
| `settings` | الإعدادات | نفس الملف |
| `teams` | الفرق | نفس الملف |

### 2.4 نظام المصادقة

| البند | القيمة | الدليل |
|-------|--------|--------|
| **الآلية** | JWT HMAC-SHA256 | `api/_auth.ts:44-47` |
| **التخزين** | HttpOnly Cookie | `api/_auth.ts:64` |
| **الأدوار** | SUPER_ADMIN, MANAGER, SALES_REP | `api/_auth.ts:12` |
| **Secret** | JWT_SECRET env var | `api/_auth.ts:25` |

### 2.5 API Endpoints الرئيسية

| Endpoint | Method | الوظيفة |
|----------|--------|---------|
| `/api/auth` | POST | تسجيل الدخول/الخروج |
| `/api/leads` | GET/POST/PUT/DELETE | إدارة العملاء |
| `/api/reports` | GET/POST | تقارير AI |
| `/api/users` | GET/POST/PUT/DELETE | إدارة المستخدمين |
| `/api/settings` | GET/PUT | الإعدادات |
| `/api/tasks` | GET/POST/PUT/DELETE | المهام |

---

## 3. المشروع الثاني: forge.op-tg.com

### 3.1 التقنيات المستخدمة

| المكون | التقنية | الدليل |
|--------|---------|--------|
| **Backend** | PHP 8.4+ | `bootstrap.php:1` |
| **Database** | SQLite | `config/db.php:3` |
| **Worker** | Node.js + Playwright | `worker/package.json` |
| **Frontend (Admin)** | PHP Server-rendered | `admin/*.php` |

### 3.2 بنية المجلدات

```
forge.op-tg.com/
├── admin/                 # Admin Panel (PHP)
│   ├── dashboard.php      # لوحة التحكم
│   ├── leads.php          # إدارة العملاء
│   ├── workers.php        # إدارة Workers
│   ├── settings.php       # الإعدادات
│   └── health.php         # صحة النظام
├── api/                   # Internal APIs
│   ├── pull_job.php       # Worker job pulling
│   └── report_results.php # Worker results reporting
├── v1/api/                # Public REST API
│   ├── auth/              # Authentication
│   ├── leads/             # Leads API
│   ├── whatsapp/          # WhatsApp integration
│   ├── campaigns/         # Campaigns
│   └── public/            # Public endpoints
├── lib/                   # Core Libraries
│   ├── auth.php           # Session/Token auth
│   ├── security.php       # HMAC, worker auth
│   ├── classify.php       # Lead classification
│   ├── providers.php      # Data providers
│   └── system.php         # System utilities
├── config/                # Configuration
│   ├── db.php             # Database connection
│   └── .env.php           # Environment config
├── worker/                # Playwright Worker
│   ├── index.js           # Main worker script
│   └── package.json       # Worker dependencies
├── storage/               # Data storage
│   ├── app.sqlite         # SQLite database
│   └── logs/              # Log files
└── tools/                 # Utility scripts
```

### 3.3 قاعدة البيانات (SQLite)

| الجدول | الوصف | الدليل |
|--------|-------|--------|
| `users` | المستخدمين (admin/agent) | `config/db.php:6` |
| `sessions` | الجلسات | `config/db.php:7` |
| `leads` | العملاء المحتملين | `config/db.php:8` |
| `assignments` | توزيع العملاء | `config/db.php:9` |
| `settings` | الإعدادات | `config/db.php:10` |
| `internal_jobs` | مهام Scraping | `config/db.php:16` |
| `internal_workers` | سجل Workers | `config/db.php:264` |
| `categories` | التصنيفات | `config/db.php:127` |
| `washeej_logs` | سجل WhatsApp | `config/db.php:11` |
| `audit_logs` | سجل التدقيق | `config/db.php:248` |

### 3.4 نظام المصادقة

| البند | القيمة | الدليل |
|-------|--------|--------|
| **الآلية** | Session + Bearer Token | `lib/auth.php:34-48` |
| **Worker Auth** | HMAC-SHA256 | `lib/security.php` |
| **الأدوار** | admin, agent | `lib/auth.php:142-152` |
| **Remember Cookie** | SHA256 token | `lib/auth.php:60-73` |

### 3.5 نظام Workers

```
┌─────────────────┐     HTTP Poll      ┌─────────────────┐
│   Worker Node   │ ◄──────────────────│   PHP Server    │
│  (Playwright)   │                    │  (Job Queue)    │
│                 │ ──────────────────►│                 │
│  - Scraping     │    Report Results  │  - internal_jobs│
│  - Google Maps  │                    │  - Lease System │
└─────────────────┘                    └─────────────────┘
```

| البند | القيمة | الدليل |
|-------|--------|--------|
| **Pull Endpoint** | `/api/pull_job.php` | `worker/index.js` |
| **Lease Duration** | 180 seconds | `.env:LEASE_SEC_DEFAULT` |
| **Retry Attempts** | 5 max | `.env:MAX_ATTEMPTS_DEFAULT` |
| **Backoff** | 30-3600 seconds | `.env:BACKOFF_*` |

---

## 4. تدفق البيانات

### 4.1 تدفق المصادقة

```
┌──────────┐    Login     ┌──────────────┐    JWT Cookie    ┌──────────┐
│  Client  │ ────────────►│ OP-Target API│ ─────────────────►│  Client  │
└──────────┘              └──────────────┘                   └──────────┘

┌──────────┐    Login     ┌──────────────┐   Session/Token   ┌──────────┐
│  Client  │ ────────────►│  Forge PHP   │ ─────────────────►│  Client  │
└──────────┘              └──────────────┘                   └──────────┘
```

### 4.2 تدفق العملاء (Leads)

```
                    ┌─────────────────────────────────────────┐
                    │              OP-Target                   │
                    │  ┌─────────┐    ┌─────────────────┐     │
                    │  │ Leads   │───►│ AI Reports      │     │
                    │  │ (PG)    │    │ (Gemini/OpenAI) │     │
                    │  └─────────┘    └─────────────────┘     │
                    └─────────────────────────────────────────┘
                                        ▲
                                        │ Integration Bridge
                                        │ (Feature Flagged)
                                        ▼
                    ┌─────────────────────────────────────────┐
                    │                Forge                     │
                    │  ┌─────────┐    ┌─────────────────┐     │
                    │  │ Leads   │◄───│ Workers         │     │
                    │  │ (SQLite)│    │ (Playwright)    │     │
                    │  └─────────┘    └─────────────────┘     │
                    │        │              ▲                  │
                    │        ▼              │                  │
                    │  ┌─────────┐    ┌─────────────────┐     │
                    │  │WhatsApp │    │ Google Maps     │     │
                    │  │(Washeej)│    │ Scraping        │     │
                    │  └─────────┘    └─────────────────┘     │
                    └─────────────────────────────────────────┘
```

---

## 5. نقاط التكامل

### 5.1 Feature Flags للتكامل

| Flag | الوظيفة | الحالة |
|------|---------|--------|
| `INTEGRATION_AUTH_BRIDGE` | جسر المصادقة | معطل |
| `INTEGRATION_SURVEY_FROM_LEAD` | إنشاء استبيان من Forge | معطل |
| `INTEGRATION_SEND_FROM_REPORT` | إرسال WhatsApp من التقرير | معطل |
| `INTEGRATION_UNIFIED_LEAD_VIEW` | عرض موحد للعملاء | معطل |

**الدليل**: `integration_docs/FEATURE_FLAGS.md`

### 5.2 Shared Secrets

| Secret | الموقع | الاستخدام |
|--------|--------|----------|
| `INTEGRATION_SHARED_SECRET` | OP-Target `.env` | Token exchange |
| `internal_secret` | Forge settings | Worker auth |

---

## 6. المنافذ والخدمات

| الخدمة | المنفذ | الحالة |
|--------|--------|--------|
| OP-Target Vite Dev | 3000 | ✅ يعمل |
| Forge PHP Server | 8081 | ✅ يعمل |
| Worker Status | 4499 | غير مفعل حالياً |

---

## 7. ملفات الإعدادات الحساسة

| الملف | المحتوى | ملاحظة |
|-------|---------|--------|
| `OP-Target/.env` | أسرار JWT, DB URL | **يحتوي أسرار** |
| `OP-Target/.env.local` | إعدادات محلية | **يحتوي أسرار** |
| `forge/config/.env.php` | مسار SQLite | آمن |
| `forge/.env` | إعدادات Worker | يحتوي INTERNAL_SECRET |

---

## 8. حالة التشغيل الحالية

### OP-Target-Sales-Hub-1
- ✅ البناء: نجح (`npm run build`)
- ✅ الاختبارات: 62/62 نجحت (`npm run test`)
- ✅ خادم التطوير: يعمل على `localhost:3000`
- ⚠️ قاعدة البيانات: تتطلب DATABASE_URL (Neon)

### forge.op-tg.com
- ✅ Bootstrap: يعمل
- ✅ خادم PHP: يعمل على `localhost:8081`
- ✅ قاعدة البيانات: SQLite جاهزة
- ⚠️ Worker: يتطلب تشغيل منفصل

---

> **آخر تحديث**: 2026-01-05 19:56 UTC+3
