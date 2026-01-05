# 02_RBAC_MATRIX - مصفوفة الصلاحيات

**التاريخ:** 2026-01-03  
**الإصدار:** v2.6-security

---

## 👥 الأدوار (Roles)

| الدور | الوصف | المستوى |
|-------|-------|---------|
| `SUPER_ADMIN` | مسؤول النظام الكامل | 1 (أعلى) |
| `MANAGER` | مدير فريق المبيعات | 2 |
| `SALES_REP` | مندوب مبيعات | 3 (أدنى) |

---

## 🔐 مصفوفة الصلاحيات

### API Endpoints

| Endpoint | Method | SUPER_ADMIN | MANAGER | SALES_REP | ملاحظات |
|----------|--------|-------------|---------|-----------|---------|
| `/api/auth` | POST | ✅ | ✅ | ✅ | Login (public) |
| `/api/logout` | POST | ✅ | ✅ | ✅ | Logout (authenticated) |
| `/api/me` | GET | ✅ | ✅ | ✅ | Current user |
| `/api/leads` | GET | ✅ الكل | ✅ الفريق | ✅ ملكه فقط | RBAC enforced |
| `/api/leads` | POST | ✅ | ✅ | ✅ ملكه فقط | IDOR protected |
| `/api/leads` | DELETE | ✅ | ✅ الفريق | ✅ ملكه فقط | IDOR protected |
| `/api/reports` | GET | ✅ | ✅ الفريق | ✅ ملكه فقط | Lead-based |
| `/api/reports` | POST | ✅ | ✅ الفريق | ✅ ملكه فقط | Lead-based |
| `/api/users` | GET (list) | ✅ | ❌ | ❌ | Admin only |
| `/api/users` | GET (points) | ✅ | ✅ ذاته | ✅ ذاته | Own points |
| `/api/users` | POST | ✅ | ❌ | ❌ | Admin only |
| `/api/users` | DELETE | ✅ | ❌ | ❌ | Admin only |
| `/api/settings/*` | GET | ✅ | ❌ | ❌ | Admin only |
| `/api/settings/*` | POST | ✅ | ❌ | ❌ | Admin only |
| `/api/analytics` | GET | ⚠️ TBD | ⚠️ TBD | ⚠️ TBD | Needs RBAC |
| `/api/activities` | * | ⚠️ TBD | ⚠️ TBD | ⚠️ TBD | Needs RBAC |
| `/api/tasks` | * | ⚠️ TBD | ⚠️ TBD | ⚠️ TBD | Needs RBAC |
| `/api/logs` | * | ⚠️ TBD | ⚠️ TBD | ⚠️ TBD | Needs RBAC |

---

### Frontend Components

| Component | SUPER_ADMIN | MANAGER | SALES_REP |
|-----------|-------------|---------|-----------|
| Dashboard | ✅ كامل | ✅ الفريق | ✅ شخصي |
| LeadList | ✅ الكل | ✅ الفريق | ✅ ملكه |
| LeadForm | ✅ | ✅ | ✅ |
| LeadDetails | ✅ الكل | ✅ الفريق | ✅ ملكه |
| ReportView | ✅ الكل | ✅ الفريق | ✅ ملكه |
| Leaderboard | ✅ | ✅ | ✅ |
| UserManagement | ✅ | ❌ | ❌ |
| SettingsPanel | ✅ | ❌ | ❌ |
| SmartSurvey | ✅ | ✅ | ✅ |

---

## 🔄 تدفق التحقق

```
Request
   │
   ▼
┌──────────────────────────────┐
│ 1. Extract JWT from Cookie   │
│    getAuthFromRequest()      │
│    - Check auth_token cookie │
│    - Verify JWT signature    │
│    - Check expiration        │
└──────────────┬───────────────┘
               │
               ▼
┌──────────────────────────────┐
│ 2. RequireAuth Check         │
│    requireAuth()             │
│    - Return 401 if no token  │
│    - Extract user ID & role  │
└──────────────┬───────────────┘
               │
               ▼
┌──────────────────────────────┐
│ 3. RequireRole Check         │
│    requireRole(allowedRoles) │
│    - Return 403 if no access │
└──────────────┬───────────────┘
               │
               ▼
┌──────────────────────────────┐
│ 4. Resource Access Check     │
│    canAccessLead(user, id)   │
│    canAccessUser(auth, id)   │
│    - SUPER_ADMIN: all        │
│    - MANAGER: team only      │
│    - SALES_REP: own only     │
└──────────────┬───────────────┘
               │
               ▼
          Proceed/403
```

---

## 🛡️ IDOR Protection Rules

### Leads
| Role | Read | Update | Delete |
|------|------|--------|--------|
| SUPER_ADMIN | أي lead | أي lead | أي lead |
| MANAGER | leads فريقه | leads فريقه | leads فريقه |
| SALES_REP | leads ملكه | leads ملكه | leads ملكه |

### Users
| Role | Read List | Read Profile | Update | Delete |
|------|-----------|--------------|--------|--------|
| SUPER_ADMIN | ✅ | أي user | أي user | أي user (إلا ذاته) |
| MANAGER | ❌ | ذاته | ❌ | ❌ |
| SALES_REP | ❌ | ذاته | ❌ | ❌ |

### Reports
- الوصول يعتمد على ملكية الـ Lead المرتبط

### Settings
- SUPER_ADMIN فقط

---

## 📝 ملاحظات التنفيذ

1. **كل endpoint يجب أن يستخدم:**
   - `requireAuth()` للتحقق من الهوية
   - `requireRole()` للصلاحيات الثابتة
   - `canAccessLead()` أو `canAccessUser()` للـ IDOR

2. **لا اعتماد على:**
   - Query string parameters للتحقق
   - Frontend-only protection
   - localStorage للجلسات

3. **Endpoints تحتاج تحديث:**
   - `api/analytics.ts`
   - `api/activities.ts`
   - `api/tasks.ts`
   - `api/logs.ts`
