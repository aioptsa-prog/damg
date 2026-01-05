# Routing Map

**Application:** OP Target Sales Hub  
**Router:** React Router v6 (BrowserRouter)

---

## Public Routes (No Authentication)

| Path | Component | Purpose |
|------|-----------|---------|
| `/login` | `LoginPage` | User authentication |
| `/change-password` | `ChangePasswordPage` | Force password change for new users |

---

## Protected Routes (Requires Authentication)

| Path | Component | Purpose | Admin Only |
|------|-----------|---------|------------|
| `/` | Redirect | Redirects to `/dashboard` | No |
| `/dashboard` | `DashboardPage` | Main dashboard with analytics | No |
| `/leads` | `LeadsPage` | List all leads (RBAC filtered) | No |
| `/leads/new` | `NewLeadPage` | Create new lead + generate report | No |
| `/leads/:id` | `LeadDetailsPage` | View/edit lead details | No |
| `/leads/:id/survey` | `SurveyPage` | Smart survey for lead | No |
| `/reports/:id` | `ReportPage` | View generated report | No |
| `/leaderboard` | `Leaderboard` | Sales team rankings | No |
| `/settings` | `SettingsPanel` | System settings | **Yes** |
| `/users` | `UserManagement` | User management | **Yes** |

---

## Error Routes

| Path | Component | Purpose |
|------|-----------|---------|
| `*` | `NotFound` | 404 page for unknown routes |

---

## Route Access Control

### Authentication Flow
```
1. User visits any protected route
2. ProtectedRoute checks authService.isAuthenticated()
3. If not authenticated → Redirect to /login
4. If mustChangePassword → Redirect to /change-password
5. If adminOnly && not SUPER_ADMIN → Redirect to /dashboard
6. Otherwise → Render route
```

### RBAC Levels
| Role | Dashboard | Leads | Reports | Settings | Users |
|------|-----------|-------|---------|----------|-------|
| SUPER_ADMIN | ✅ | ✅ All | ✅ All | ✅ | ✅ |
| TEAM_LEAD | ✅ | ✅ Team | ✅ Team | ❌ | ❌ |
| SALES_REP | ✅ | ✅ Own | ✅ Own | ❌ | ❌ |

---

## Deep Link Support

All routes support direct URL access:
- `/dashboard` - Opens dashboard directly
- `/leads/abc123` - Opens specific lead
- `/reports/xyz789` - Opens specific report

### Vercel Configuration
```json
{
  "rewrites": [
    { "source": "/api/(.*)", "destination": "/api/$1" },
    { "source": "/((?!api/).*)", "destination": "/index.html" }
  ]
}
```

---

## Page Titles

Each page sets document title via `usePageTitle` hook:
- Dashboard: "لوحة التحكم | OP Target"
- Leads: "العملاء | OP Target"
- Report: "التقرير الاستراتيجي | OP Target"
- etc.

---

## Navigation

### Sidebar Navigation Items
```typescript
const NAV_ITEMS = [
  { path: '/dashboard', label: 'لوحة التحكم', icon: LayoutDashboard },
  { path: '/leads', label: 'العملاء', icon: Users },
  { path: '/users', label: 'المستخدمين', icon: UserCog, adminOnly: true },
  { path: '/leaderboard', label: 'المتصدرين', icon: TrendingUp },
  { path: '/leads/new', label: 'تقرير جديد', icon: PlusCircle },
  { path: '/settings', label: 'الإعدادات', icon: Settings, adminOnly: true },
];
```

### Back Button
Shows on:
- `/leads/:id` (back to /leads)
- `/leads/:id/survey` (back to lead details)
- `/reports/:id` (back to lead details)
