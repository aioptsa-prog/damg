# 08_HANDOFF_PLAN - خطة التسليم

---

## 📋 للوكيل الجديد

### أول 15 دقيقة
1. اقرأ `/_DOCS_FINAL/00_EXECUTIVE_SUMMARY.md`
2. راجع `.env.example` لفهم المتغيرات
3. اقرأ `07_GAP_ANALYSIS.md` لفهم ما تم وما لم يتم

### أول ساعة
4. شغّل المشروع محلياً (`npm install && npm run dev`)
5. نفّذ smoke tests من `06_TESTING_AND_SMOKE.md`
6. راجع `03_SECURITY_MODEL.md` للأمان

---

## 🚨 الأسبوع الأول - أولويات

### P0 (يجب قبل أي شيء)
| # | المهمة | الملف | الوقت |
|---|--------|-------|-------|
| 1 | إضافة production seed guard | `api/seed.ts` | 15min |
| 2 | التحقق من mustChangePassword في Frontend | `App.tsx`, `authService.ts` | 1h |
| 3 | تدوير المفاتيح قبل النشر | Neon, Gemini | 30min |

### P1 (الأسبوع الأول)
| # | المهمة | الوقت |
|---|--------|-------|
| 4 | Input validation (zod) على auth endpoints | 2h |
| 5 | CORS configuration صريحة | 30min |
| 6 | إضافة request logging | 1h |

---

## ⚠️ لا تلمس هذه الملفات

| الملف | السبب |
|-------|-------|
| `api/_auth.ts` | Core RBAC - مستقر |
| `api/auth.ts` | Login flow - مستقر |
| `services/authService.ts` | Cookie handling - مستقر |

---

## 🔴 نقاط حساسة

1. **Rate Limiting:** في Memory - يُفقد عند restart
2. **Cookies:** Secure flag فقط في production
3. **Seed:** لا guard للإنتاج حالياً
4. **mustChangePassword:** الـ Frontend enforcement غير مؤكد

---

## ✅ معايير النجاح

- [ ] `npm run build` يمر بدون أخطاء
- [ ] كل endpoints ترجع 401 بدون cookie
- [ ] كل endpoints admin-only ترجع 403 لغير admin
- [ ] لا أسرار في `dist/` بعد build
- [ ] Smoke tests تمر (8 سيناريوهات)

---

## 📚 المراجع

| الموضوع | الملف |
|---------|-------|
| التشغيل | `02_SETUP_RUNBOOK.md` |
| الأمان | `03_SECURITY_MODEL.md` |
| API | `04_API_REFERENCE.md` |
| الفجوات | `07_GAP_ANALYSIS.md` |
| سجل التغييرات | `/_AI_REMEDIATION/01_SECURITY_PATCHLOG.md` |
