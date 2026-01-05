# REST API v1 Documentation

## Base URL
```
https://nexus.op-tg.com/v1/api
```

## Authentication
All endpoints (except public ones) require authentication via session cookie or `X-Session-ID` header.

### Login
```http
POST /v1/api/auth/login
Content-Type: application/json

{
  "mobile": "590000000",
  "password": "Forge@2025!",
  "remember": false
}
```

**Response (200)**:
```json
{
  "ok": true,
  "user": {
    "id": 1,
    "name": "Forge Admin",
    "mobile": "590000000",
    "role": "admin"
  },
  "token": "session_id_here"
}
```

### Get Current User
```http
GET /v1/api/auth/me
X-Session-ID: {token}
```

### Logout
```http
POST /v1/api/auth/logout
X-Session-ID: {token}
```

---

## Endpoints

### Leads

#### List Leads
```http
GET /v1/api/leads/index.php?page=1&limit=20&category_id=5&search=clinic
X-Session-ID: {token}
```

**Query Parameters**:
- `page`: int (default: 1)
- `limit`: int (default: 20, max: 100)
- `category_id`: int (optional)
- `city_id`: int (optional)
- `search`: string (optional)
- `status`: string (for agents: assignment status)

**Response**:
```json
{
  "ok": true,
  "data": [
    {
      "id": 123,
      "phone": "966501234567",
      "name": "عيادة النور",
      "category": {
        "id": 5,
        "name": "عيادات أسنان",
        "slug": "dental-clinics"
      },
      "location": {
        "city_name": "الرياض",
        "district_name": "حي النخيل"
      },
      "rating": 4.5
    }
  ],
  "pagination": {
    "page": 1,
    "limit": 20,
    "total": 150,
    "pages": 8
  }
}
```

---

### Categories

#### List Categories (Tree)
```http
GET /v1/api/categories/index.php
```

**Response**:
```json
{
  "ok": true,
  "data": [
    {
      "id": 1,
      "name": "الخدمات الطبية",
      "slug": "medical-services",
      "children": [
        {
          "id": 5,
          "name": "عيادات أسنان",
          "slug": "dental-clinics"
        }
      ]
    }
  ],
  "flat": [...] // Flat list for dropdowns
}
```

---

## Error Responses

All errors follow this format:
```json
{
  "ok": false,
  "error": "error_code",
  "message": "Human-readable message (Arabic)"
}
```

**Common Error Codes**:
- `unauthorized` (401): Not logged in
- `invalid_credentials` (401): Wrong mobile/password
- `method_not_allowed` (405): Wrong HTTP method
- `server_error` (500): Internal server error

---

## CORS

All endpoints support CORS with:
- `Access-Control-Allow-Origin: *`
- `Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS`
- `Access-Control-Allow-Credentials: true`

---

## Rate Limiting

Global rate limit: 600 requests/minute per IP (configurable via `rate_limit_global_per_min` setting).

---

## Testing

### cURL Examples

**Login**:
```bash
curl -X POST https://nexus.op-tg.com/v1/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"mobile":"590000000","password":"Forge@2025!"}'
```

**Get Leads**:
```bash
curl -X GET "https://nexus.op-tg.com/v1/api/leads/index.php?page=1&limit=10" \
  -H "X-Session-ID: YOUR_TOKEN_HERE" \
  -H "Content-Type: application/json"
```

---

## Next Steps

See `/saudi-lead-iq-main/src/lib/api.ts` for TypeScript API client implementation.
