# PRE_DEPLOY_ROTATION_CHECKLIST - قائمة التدوير قبل النشر

**الغرض:** قائمة ما يجب تدويره (rotate) قبل أي نشر خارجي/إنتاجي.  
**الحالة الحالية:** بيئة تطوير محلية - لا يتطلب تنفيذ فوري.

---

## 🔐 المفاتيح المطلوب تدويرها قبل النشر

### 1. Neon Database Credentials
- **ماذا:** اسم المستخدم وكلمة المرور لقاعدة البيانات
- **أين:** [Neon Dashboard](https://console.neon.tech/)
- **الخطوات:**
  1. Reset password من لوحة التحكم
  2. تحديث `DATABASE_URL` في environment الإنتاج
  3. اختبار الاتصال
- [ ] تم التدوير

### 2. Google Gemini API Key
- **ماذا:** مفتاح Gemini للذكاء الاصطناعي
- **أين:** [Google AI Studio](https://aistudio.google.com/apikey)
- **الخطوات:**
  1. حذف المفتاح القديم
  2. إنشاء مفتاح جديد
  3. تحديث `GEMINI_API_KEY` في environment
- [ ] تم التدوير

### 3. OpenAI API Key (اختياري)
- **ماذا:** مفتاح OpenAI للـ GPT-4
- **أين:** [OpenAI Platform](https://platform.openai.com/api-keys)
- **الخطوات:**
  1. Revoke المفتاح القديم
  2. إنشاء مفتاح جديد
  3. تحديث `OPENAI_API_KEY` في environment
- [ ] تم التدوير (أو غير مستخدم)

### 4. JWT Secret
- **ماذا:** مفتاح توقيع JWT للجلسات
- **أين:** يُولّد محلياً
- **الخطوات:**
  1. توليد: `openssl rand -base64 32`
  2. إضافة لـ `JWT_SECRET` في environment
- [ ] تم التوليد

### 5. Encryption Secret
- **ماذا:** مفتاح AES-256-GCM للتشفير
- **أين:** يُولّد محلياً
- **الخطوات:**
  1. توليد: `openssl rand -base64 32`
  2. إضافة لـ `ENCRYPTION_SECRET` في environment
- [ ] تم التوليد

---

## ✅ Environment Variables المطلوبة للإنتاج

```bash
# Database (Neon)
DATABASE_URL=postgresql://user:NEW_PASSWORD@host.neon.tech:5432/db?sslmode=require

# AI Services
GEMINI_API_KEY=NEW_GEMINI_KEY
OPENAI_API_KEY=NEW_OPENAI_KEY  # اختياري

# Security
JWT_SECRET=GENERATED_32_CHAR_SECRET
ENCRYPTION_SECRET=GENERATED_32_CHAR_SECRET

# Optional
NODE_ENV=production
```

---

## ⚠️ تحذيرات

1. **لا تنشر أبداً** بدون تدوير كل المفاتيح أعلاه
2. **لا تضع** أي من هذه القيم في الكود أو `.env` داخل الـ repository
3. **استخدم** Vercel Environment Variables أو ما يعادلها للإنتاج

---

## 📋 Pre-Deploy Final Checklist

- [ ] كل المفاتيح أعلاه مُدوّرة
- [ ] `.env` ليس في git (تحقق من `.gitignore`)
- [ ] `npm run build` يعمل بدون أخطاء
- [ ] الاختبارات تمر
- [ ] لا أسرار في `git log` (استخدم `git-filter-branch` إن لزم)
