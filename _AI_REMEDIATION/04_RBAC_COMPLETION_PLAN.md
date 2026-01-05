# 04_RBAC_COMPLETION_PLAN - خطة إكمال الصلاحيات

---

## 📊 المصفوفة النهائية

| Endpoint | SUPER_ADMIN | MANAGER | SALES_REP |
|----------|-------------|---------|-----------|
| GET /leads | ✅ الكل | ✅ الفريق | ✅ ملكه |
| POST /leads | ✅ | ✅ | ✅ ملكه |
| DELETE /leads | ✅ | ✅ الفريق | ✅ ملكه |
| GET /reports | ✅ | ✅ الفريق | ✅ ملكه |
| GET /users | ✅ | ❌ | ❌ |
| POST /users | ✅ | ❌ | ❌ |
| GET /settings | ✅ | ❌ | ❌ |
| POST /settings | ✅ | ❌ | ❌ |
| GET /analytics | ✅ | ✅ الفريق | ✅ شخصي |
| GET /activities | ✅ | ✅ الفريق | ✅ ملكه |
| GET /tasks | ✅ | ✅ الفريق | ✅ assigned |
| GET /logs | ✅ | ❌ | ❌ |

---

## ⚠️ Endpoints تحتاج RBAC

### 1. analytics.ts
```typescript
// المطلوب
import { requireAuth } from './_auth';

// SUPER_ADMIN: all stats
// MANAGER: team stats
// SALES_REP: own stats only
```

### 2. activities.ts
```typescript
// المطلوب
import { requireAuth, canAccessLead } from './_auth';

// Filter by lead ownership
```

### 3. tasks.ts
```typescript
// المطلوب
import { requireAuth } from './_auth';

// Filter by assigned_to_user_id or lead ownership
```

### 4. logs.ts
```typescript
// المطلوب
import { requireRole } from './_auth';

// SUPER_ADMIN only
```

---

## 🔧 التنفيذ

سيتم تطبيق نفس نمط الـ middleware المستخدم في leads.ts:

```typescript
export default async function handler(req, res) {
  const user = requireAuth(req, res);
  if (!user) return;
  
  // Role-based filtering
  if (user.role === 'SUPER_ADMIN') {
    // all data
  } else if (user.role === 'MANAGER') {
    // team data
  } else {
    // own data only
  }
}
```
