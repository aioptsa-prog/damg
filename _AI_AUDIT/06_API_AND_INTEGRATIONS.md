# 06_API_AND_INTEGRATIONS - واجهات البرمجة والتكاملات

## ما تم فحصه
- ✅ جميع ملفات `api/` (9 ملفات)
- ✅ `openapi.json`
- ✅ خدمات التكامل في `services/`

---

## 📡 قائمة الـ API Endpoints

### من تحليل الكود (api/*.ts):

| Endpoint | Method | الوصف | Auth |
|----------|--------|-------|------|
| `/api/leads` | GET | جلب العملاء | ❌ لا تحقق |
| `/api/leads` | POST | إنشاء/تحديث عميل | ❌ لا تحقق |
| `/api/leads?id=X` | DELETE | حذف عميل | ❌ لا تحقق |
| `/api/reports?leadId=X` | GET | جلب تقارير عميل | ❌ لا تحقق |
| `/api/reports` | POST | حفظ تقرير | ❌ لا تحقق |
| `/api/users` | GET | جلب المستخدمين | ❌ لا تحقق |
| `/api/users` | POST | إنشاء/تحديث مستخدم | ❌ لا تحقق |
| `/api/users/points?userId=X` | GET | حساب نقاط مستخدم | ❌ لا تحقق |
| `/api/analytics?userId=X` | GET | إحصائيات Dashboard | ❌ لا تحقق |
| `/api/activities?leadId=X` | GET | نشاطات عميل | ❌ لا تحقق |
| `/api/activities` | POST | إضافة نشاط | ❌ لا تحقق |
| `/api/tasks?leadId=X` | GET | مهام عميل | ❌ لا تحقق |
| `/api/tasks` | POST | إنشاء مهام | ❌ لا تحقق |
| `/api/tasks/status` | PUT | تحديث حالة مهمة | ❌ لا تحقق |
| `/api/settings/ai` | GET | إعدادات AI | ❌ لا تحقق |
| `/api/settings/ai` | POST | حفظ إعدادات AI | ❌ لا تحقق |
| `/api/settings/scoring` | POST | حفظ إعدادات النقاط | ❌ لا تحقق |
| `/api/logs/audit` | GET | سجل الرقابة | ❌ لا تحقق |
| `/api/logs/audit` | POST | إضافة سجل | ❌ لا تحقق |
| `/api/logs/usage` | POST | تسجيل استخدام AI | ❌ لا تحقق |

---

## 🚨 مشاكل أمنية حرجة في الـ API

### 1. عدم وجود Authentication على الـ Backend

```typescript
// api/leads.ts - لا يوجد تحقق من الـ token
export default async function handler(req: any, res: any) {
  // ❌ لا يوجد:
  // - التحقق من JWT
  // - التحقق من الجلسة
  // - التحقق من الصلاحيات
  
  const leadsRes = await query(
    'SELECT * FROM leads WHERE owner_user_id = $1 OR $1 IS NULL',
    [userId]  // ⚠️ userId يأتي من Query string!
  );
}
```

### 2. الثقة بـ Query Parameters

```typescript
// api/leads.ts:12-14
const userId = queryParams.userId;
// ⚠️ أي شخص يمكنه إرسال userId أي مستخدم آخر!
```

### 3. SQL Injection محمي جزئياً

```typescript
// ✅ يستخدم parameterized queries
await query('SELECT * FROM leads WHERE id = $1', [id]);

// ⚠️ لكن لا validation على المدخلات
const leadData = toSnake(req.body);
// يمكن إرسال أي بيانات
```

---

## 🔌 التكاملات الخارجية

### 1. Google Gemini AI

**الملف:** `services/aiService.ts`

```typescript
import { GoogleGenAI } from "@google/genai";

const ai = new GoogleGenAI({ apiKey });
const result = await ai.models.generateContent({
  model: 'gemini-3-flash-preview',
  contents: prompt,
  config: {
    responseMimeType: "application/json",
    responseSchema: REPORT_SCHEMA,
    tools: [{ googleSearch: {} }]  // بحث حي
  }
});
```

**الحالة:** ✅ مُطبق بالكامل
**المخاطر:** 
- ⚠️ API Key قد يتسرب للـ Frontend
- ⚠️ لا caching للاستجابات

---

### 2. OpenAI GPT-4

**الملف:** `services/aiService.ts:264-290`

```typescript
const response = await fetch('https://api.openai.com/v1/chat/completions', {
  headers: { 'Authorization': `Bearer ${apiKey}` },
  body: JSON.stringify({
    model: model,
    response_format: { type: "json_object" },
  })
});
```

**الحالة:** ✅ مُطبق كبديل
**المخاطر:** نفس مخاطر Gemini

---

### 3. WhatsApp (WHSender)

**الملف:** `services/whatsappService.ts`

```typescript
// الوضع المُفعل
const payload = {
  to: phone,
  message: message,
  sender: settings.senderId,
  apikey: settings.apiKey
};
await fetch(settings.baseUrl, { method: 'POST', body: payload });

// الوضع الاحتياطي (Fallback)
window.open(`https://wa.me/${phone}?text=${encodedMsg}`, '_blank');
```

**الحالة:** ⚠️ جزئي
- API call مجهز لكن لم يُختبر
- Fallback يعمل
**المخاطر:**
- ⚠️ API Key في localStorage (مشفر وهمياً)

---

### 4. Google Sheets

**الملف:** `components/SettingsPanel.tsx:40-48`

```typescript
const [sheetsSettings, setSheetsSettings] = useState({
  enabled: true,
  sheetId: '1BxiMVs0...',  // مثال ثابت
  tabName: 'Leads_2024',
  serviceAccount: 'opt-sales-hub@optarget.iam.gserviceaccount.com'
});
```

**الحالة:** ❌ مُعد لكن غير مُنفذ
- الإعدادات موجودة في الواجهة
- لا يوجد كود للكتابة فعلياً في Sheets

---

## 🔐 إدارة الـ Secrets

### الوضع الحالي:

| الـ Secret | طريقة التخزين | المخاطرة |
|------------|---------------|----------|
| Gemini API Key | Settings DB + Vite env | 🔴 عالية |
| OpenAI API Key | Settings DB | 🟡 متوسطة |
| WhatsApp API Key | localStorage (Base64) | 🔴 عالية |
| Database URL | env variable | ✅ آمن |

### مشاكل التسريب:

1. **Vite يحقن API Key في الـ Bundle:**
```typescript
// vite.config.ts:14
'process.env.API_KEY': JSON.stringify(env.GEMINI_API_KEY)
// ⚠️ هذا يجعل المفتاح مرئياً في الـ JavaScript!
```

2. **"التشفير" في EncryptionService:**
```typescript
// services/encryptionService.ts
// ⚠️ ليس تشفير حقيقي - مجرد Base64!
encrypt(text: string): string {
  const buffer = new TextEncoder().encode(text + ":" + this.secret);
  const b64 = btoa(String.fromCharCode(...new Uint8Array(buffer)));
  return `enc_v1:${b64}`;
}
```

---

## 📋 OpenAPI Specification

**الملف:** `openapi.json`

```json
{
  "openapi": "3.0.0",
  "info": {
    "title": "OP Target Sales Hub API",
    "version": "1.0.0"
  },
  "paths": {
    "/api/leads": { ... },
    "/api/reports": { ... }
  }
}
```

**الحالة:** ⚠️ موجود لكن ناقص
- لا يشمل كل الـ endpoints
- لا يشمل authentication schemes

---

## ✅ التوصيات

1. **إضافة JWT middleware** للتحقق من كل request
2. **نقل API Keys للـ Backend فقط** (لا inject في Frontend)
3. **استخدام تشفير حقيقي** (AES-256-GCM مع secret آمن)
4. **Rate Limiting على الـ Server** (لا Client-side)
5. **تحديث OpenAPI spec** ليشمل كل الـ endpoints
6. **CORS configuration** صارمة
