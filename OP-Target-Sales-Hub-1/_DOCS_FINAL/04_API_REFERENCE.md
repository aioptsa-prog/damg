# 04_API_REFERENCE - مرجع الـ API

---

## 🔓 Public Endpoints

### POST /api/auth
Login and get session cookie.

```bash
curl -X POST http://localhost:3000/api/auth \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"xxx"}' \
  -c cookies.txt
```

| Response | Meaning |
|----------|---------|
| 200 | Success + Set-Cookie |
| 401 | Invalid credentials |
| 403 | Account disabled |
| 429 | Rate limited |

### POST /api/seed
Create initial admin (protected by SEED_SECRET).

```bash
curl -X POST http://localhost:3000/api/seed \
  -H "Content-Type: application/json" \
  -d '{"secret":"SEED_SECRET"}'
```

---

## 🔒 Authenticated Endpoints

All require `auth_token` cookie.

### GET /api/me
```bash
curl http://localhost:3000/api/me -b cookies.txt
# Returns: { user: { id, name, email, role, ... } }
```

### POST /api/logout
```bash
curl -X POST http://localhost:3000/api/logout -b cookies.txt
# Clears cookie
```

---

## 📊 Resource Endpoints

### /api/leads
| Method | RBAC | Description |
|--------|------|-------------|
| GET | Own/Team/All | List leads |
| POST | Own/All | Create/Update |
| DELETE | Own/Team/All | Delete |

### /api/reports
| Method | RBAC | Description |
|--------|------|-------------|
| GET | Lead-based | Get reports for lead |
| POST | Lead-based | Create report |

### /api/users (Admin Only)
| Method | Description |
|--------|-------------|
| GET | List all users |
| POST | Create/Update user |
| DELETE | Delete user |

### /api/settings (Admin Only)
| Method | Path | Description |
|--------|------|-------------|
| GET | /api/settings/ai | Get AI settings |
| POST | /api/settings/ai | Update AI settings |
| GET | /api/settings/scoring | Get scoring |
| POST | /api/settings/scoring | Update scoring |

---

## 🔑 Password Endpoints

### POST /api/reset-password (Admin Only)
```bash
curl -X POST http://localhost:3000/api/reset-password \
  -H "Content-Type: application/json" \
  -d '{"userId":"xxx"}' \
  -b cookies.txt
# Returns: { temporaryPassword: "xxx" }
```

### POST /api/change-password
```bash
curl -X POST http://localhost:3000/api/change-password \
  -H "Content-Type: application/json" \
  -d '{"currentPassword":"old","newPassword":"new"}' \
  -b cookies.txt
```

---

## ❌ Error Responses

| Status | Error | Meaning |
|--------|-------|---------|
| 400 | Bad Request | Invalid input |
| 401 | Unauthorized | Not logged in |
| 403 | Forbidden | No permission |
| 404 | Not Found | Resource missing |
| 429 | Too Many Requests | Rate limited |
| 500 | Internal Error | Server error |
