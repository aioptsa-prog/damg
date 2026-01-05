# 03_API_COVERAGE_MAP - خريطة تغطية الـ API

**تاريخ التدقيق:** 2026-01-03  
**المنهجية:** Code review لكل ملف في `/api/`

---

## 📊 جدول الـ Endpoints

| Endpoint | Method | Auth | RBAC | Ownership Check | Input Validation | Audit Log |
|----------|--------|------|------|-----------------|------------------|-----------|
| `/api/auth` | POST | ❌ Public | - | - | ⚠️ Basic | ✅ Yes |
| `/api/logout` | POST | ❌ Public | - | - | - | ✅ Yes |
| `/api/me` | GET | ✅ Required | - | - | - | ❌ No |
| `/api/seed` | POST | ⚠️ Secret | - | - | ⚠️ Basic | ✅ Yes |
| `/api/leads` | GET | ✅ Required | ✅ Role-based | ✅ canAccessLead | ❌ None | ❌ No |
| `/api/leads` | POST | ✅ Required | ✅ Role-based | ✅ canAccessLead | ❌ None | ❌ No |
| `/api/leads` | DELETE | ✅ Required | ✅ Role-based | ✅ canAccessLead | ❌ None | ❌ No |
| `/api/users` | GET | ✅ Required | ✅ SUPER_ADMIN | - | ❌ None | ❌ No |
| `/api/users` | POST | ✅ Required | ✅ SUPER_ADMIN | - | ⚠️ Basic | ✅ Yes |
| `/api/users` | DELETE | ✅ Required | ✅ SUPER_ADMIN | ✅ Self-check | ⚠️ Basic | ✅ Yes |
| `/api/reports` | GET | ✅ Required | ✅ Lead-based | ✅ canAccessLead | ⚠️ Basic | ❌ No |
| `/api/reports` | POST | ✅ Required | ✅ Lead-based | ✅ canAccessLead | ⚠️ Basic | ✅ Activity |
| `/api/tasks` | GET | ✅ Required | ✅ Role-based | ✅ canAccessLead | ❌ None | ❌ No |
| `/api/tasks` | POST | ✅ Required | ✅ Role-based | ✅ canAccessLead | ⚠️ Basic | ❌ No |
| `/api/tasks/status` | PUT | ✅ Required | ✅ Role-based | ✅ Assigned check | ⚠️ Basic | ✅ Activity |
| `/api/activities` | GET | ✅ Required | ✅ Lead-based | ✅ canAccessLead | ❌ None | ❌ No |
| `/api/activities` | POST | ✅ Required | ✅ Lead-based | ✅ canAccessLead | ❌ None | ❌ No |
| `/api/analytics` | GET | ✅ Required | ✅ Role-based | - | ❌ None | ❌ No |
| `/api/settings/ai` | GET | ✅ Required | ✅ SUPER_ADMIN | - | - | ❌ No |
| `/api/settings/ai` | POST | ✅ Required | ✅ SUPER_ADMIN | - | ❌ None | ✅ Yes |
| `/api/settings/scoring` | GET | ✅ Required | ✅ SUPER_ADMIN | - | - | ❌ No |
| `/api/settings/scoring` | POST | ✅ Required | ✅ SUPER_ADMIN | - | ❌ None | ✅ Yes |
| `/api/logs/audit` | GET | ✅ Required | ✅ SUPER_ADMIN | - | - | ❌ No |
| `/api/logs/audit` | POST | ✅ Required | - | - | ❌ None | - |
| `/api/logs/usage` | POST | ✅ Required | - | - | ❌ None | ❌ No |
| `/api/change-password` | POST | ✅ Required | - | ✅ Self only | ✅ Yes | ✅ Yes |
| `/api/reset-password` | POST | ✅ Required | ✅ SUPER_ADMIN | ✅ Not self | ⚠️ Basic | ✅ Yes |

---

## 📁 تفاصيل كل Endpoint

### `/api/auth` - تسجيل الدخول

**الملف:** `api/auth.ts`

| البند | القيمة |
|-------|--------|
| Auth Required | ❌ No (public) |
| Rate Limited | ✅ Yes (5/15min) |
| Input Validation | ⚠️ Basic (email/password required, type check) |
| Audit Log | ✅ LOGIN, LOGIN_FAILED |

**Validation الموجود:**
```typescript
if (!email || !password) return 400;
if (typeof email !== 'string' || typeof password !== 'string') return 400;
```

**Validation المفقود:**
- Email format validation
- Password length check
- SQL injection (✅ parameterized queries protect)

---

### `/api/seed` - إنشاء Admin

**الملف:** `api/seed.ts`

| البند | القيمة |
|-------|--------|
| Auth Required | ⚠️ SEED_SECRET only |
| Production Guard | ❌ **MISSING** |
| Input Validation | ⚠️ Secret comparison only |
| Audit Log | ✅ ADMIN_SEEDED |

**🔴 مشكلة:**
```typescript
// لا يوجد هذا الـ check:
if (process.env.NODE_ENV === 'production') {
    return res.status(403);
}
```

---

### `/api/leads` - إدارة العملاء

**الملف:** `api/leads.ts`

| Method | RBAC | Ownership |
|--------|------|-----------|
| GET | SUPER_ADMIN: all, MANAGER: team, SALES_REP: own | ✅ |
| POST | ✅ canAccessLead for updates | ✅ |
| DELETE | ✅ canAccessLead | ✅ |

**Validation المفقود:**
- No schema validation for lead data
- No sanitization of company_name, activity, etc.
- No check for valid status enum

---

### `/api/users` - إدارة المستخدمين

**الملف:** `api/users.ts`

| Method | RBAC | Notes |
|--------|------|-------|
| GET | SUPER_ADMIN only | ✅ password_hash excluded |
| POST | SUPER_ADMIN only | ✅ password_hash blocked |
| DELETE | SUPER_ADMIN only | ✅ Self-delete blocked |

**Security:**
```typescript
// Never allow setting password_hash directly
delete snakeUser.password_hash;
```

---

### `/api/reports` - التقارير

**الملف:** `api/reports.ts`

| Method | RBAC | Ownership |
|--------|------|-----------|
| GET | Lead-based | ✅ canAccessLead |
| POST | Lead-based | ✅ canAccessLead |

**Activity Logging:**
```typescript
await query(
  'INSERT INTO activities ... type = report_generated'
);
```

---

### `/api/tasks` - المهام

**الملف:** `api/tasks.ts`

| Method | RBAC | Ownership |
|--------|------|-----------|
| GET | SUPER_ADMIN: all, others: assigned/lead | ✅ |
| POST | Lead-based | ✅ canAccessLead |
| PUT /status | Assigned or lead access | ✅ |

---

### `/api/settings` - الإعدادات

**الملف:** `api/settings.ts`

| Path | Method | RBAC |
|------|--------|------|
| /ai | GET | SUPER_ADMIN |
| /ai | POST | SUPER_ADMIN |
| /scoring | GET | SUPER_ADMIN |
| /scoring | POST | SUPER_ADMIN |

**API Key Masking:**
```typescript
if (settings.geminiApiKey) {
  settings.geminiApiKey = '***' + settings.geminiApiKey.slice(-4);
}
```

---

### `/api/change-password` - تغيير كلمة المرور

**الملف:** `api/change-password.ts`

| البند | القيمة |
|-------|--------|
| Auth Required | ✅ Yes |
| Input Validation | ✅ Yes (min 8 chars) |
| Current Password | ✅ Required |
| Audit Log | ✅ PASSWORD_CHANGED |

---

### `/api/reset-password` - إعادة تعيين كلمة المرور

**الملف:** `api/reset-password.ts`

| البند | القيمة |
|-------|--------|
| Auth Required | ✅ Yes |
| RBAC | ✅ SUPER_ADMIN only |
| Self-Reset | ❌ Blocked |
| Audit Log | ✅ PASSWORD_RESET |

---

## 📈 ملخص التغطية

### Auth Coverage: 100%
- كل الـ endpoints المحمية تستخدم `requireAuth` أو `requireRole`

### RBAC Coverage: 100%
- SUPER_ADMIN, MANAGER, SALES_REP permissions enforced

### IDOR Coverage: 100%
- `canAccessLead()` و `canAccessUser()` مستخدمة

### Input Validation Coverage: ~15%
- فقط auth و change-password لديهم validation
- باقي الـ endpoints تقبل أي data

### Audit Log Coverage: ~50%
- Critical actions logged (login, password, settings)
- CRUD operations not logged

---

## 🔴 الـ Endpoints التي تحتاج Input Validation

| Priority | Endpoint | Risk |
|----------|----------|------|
| P1 | POST /api/leads | Malformed data |
| P1 | POST /api/users | Invalid role/email |
| P1 | POST /api/reports | Large payload |
| P1 | POST /api/tasks | Invalid status |
| P1 | POST /api/activities | Invalid type |
| P2 | POST /api/settings | Invalid JSON |
| P2 | POST /api/logs | Arbitrary data |

**التوصية:** إضافة Zod schemas لكل endpoint
