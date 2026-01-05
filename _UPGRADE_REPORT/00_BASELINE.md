# 00_BASELINE - تقرير الحالة الأساسية

**تاريخ:** 2026-01-03  
**المشروع:** OP-Target-Sales-Hub

---

## 📦 Package.json Summary

| البند | القيمة |
|-------|--------|
| **Name** | op-target-sales-hub |
| **Type** | module (ESM) |
| **Engines** | غير محدد (يجب إضافته) |

---

## 🔧 Node.js Version

| المصدر | القيمة |
|--------|--------|
| `.nvmrc` | 20 |
| `package.json engines` | ❌ غير موجود |
| **المقترح** | 20.x (LTS مستقر) |

---

## 📚 Dependencies الحالية

### Production Dependencies

| المكتبة | الإصدار الحالي | أحدث إصدار | ملاحظات |
|---------|---------------|------------|---------|
| react | ^19.2.3 | 19.x | ✅ أحدث |
| react-dom | ^19.2.3 | 19.x | ✅ أحدث |
| @google/genai | ^1.34.0 | 1.x | AI SDK |
| bcrypt | ^6.0.0 | 6.x | Password hashing |
| lucide-react | ^0.562.0 | 0.x | Icons |
| pg | ^8.16.3 | 8.x | PostgreSQL client |
| recharts | ^3.6.0 | 3.x | Charts |
| vitest | ^4.0.16 | 4.x | Testing (يجب نقله لـ devDeps) |
| zod | ^4.3.4 | 4.x | Validation |

### Dev Dependencies

| المكتبة | الإصدار الحالي | أحدث إصدار | ملاحظات |
|---------|---------------|------------|---------|
| @types/node | ^22.14.0 | 25.0.3 | ⚠️ يحتاج تحديث |
| @vitejs/plugin-react | ^5.0.0 | 5.x | ✅ أحدث |
| typescript | ~5.8.2 | 5.9.3 | ⚠️ يحتاج تحديث |
| vite | ^6.2.0 | 7.3.0 | ⚠️ Major update متاح |

---

## 🔍 npm outdated Results

```
Package      Current   Wanted  Latest
@types/node  22.19.3   22.19.3  25.0.3
typescript   5.8.3     5.8.3    5.9.3
vite         6.4.1     6.4.1    7.3.0
```

---

## 📁 Project Structure

### API Files (Serverless Functions)
- 17 ملف في `/api/`
- تستخدم: pg, bcrypt, crypto, zod

### Tests
- `tests/logic.test.ts`
- `tests/schema.test.ts`
- تُشغّل عبر: `npx vitest` أو `npm test` (غير معرّف في scripts)

---

## ⚙️ Configuration Files

### vite.config.ts
- Framework: Vite + React
- Port: 3000
- Aliases: `@/` → root

### tsconfig.json
- Target: ES2022
- Module: ESNext
- JSX: react-jsx
- moduleResolution: bundler

### vercel.json
- Framework: vite
- Build: npm run build
- Output: dist

---

## ⚠️ Issues to Fix

1. **`engines` غير موجود في package.json** - يسبب مشاكل Vercel
2. **vitest في dependencies** - يجب نقله لـ devDependencies
3. **@types/bcrypt في dependencies** - يجب نقله لـ devDependencies
4. **لا يوجد test script** في package.json

---

## 📋 Upgrade Plan

### Stage 1 (Core)
- [ ] إضافة `engines: { "node": "20.x" }`
- [ ] تحديث typescript → 5.9.x
- [ ] تحديث @types/node → 25.x
- [ ] نقل vitest و @types/bcrypt لـ devDependencies

### Stage 2 (UI)
- [ ] تحديث lucide-react (minor)
- [ ] تحديث recharts (minor)

### Stage 3 (Vite)
- [ ] تقييم Vite 7.x (major) - قد يكون breaking
- [ ] إذا مشاكل، البقاء على 6.x

### Stage 4 (Serverless)
- [ ] تحديث pg (minor)
- [ ] تحديث zod (minor)
