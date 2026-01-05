# Worker (Internal Server Mode)

> **تنبيه قانوني:** تأكد من الالتزام بسياسات استخدام المواقع (Terms of Service) والقوانين المحلية قبل تشغيل أي أتمتة تصفح.

## الفكرة
يسحب مهامًا من السيرفر (`/api/pull_job.php`) كل **X** ثانية باستخدام ترويسة `X-Internal-Secret`، ثم يفتح Google Maps بالاستعلام والموقع المحدد، يجمع النتائج (الاسم/الهاتف/العنوان...) ويرسلها إلى `/api/report_results.php`.

## التشغيل على ويندوز
1) ثبّت Node.js LTS.
2) داخل مجلد worker نفّذ:
```
npm i
copy .env.example .env
```
3) عدّل `.env` (BASE_URL / INTERNAL_SECRET / WORKER_ID / PULL_INTERVAL_SEC).
4) شغّل:
```
node index.js
```
5) (اختياري) تحويل إلى exe بواسطة [pkg](https://github.com/vercel/pkg).

## ملاحظة
الأتمتة على مواقع طرف ثالث قد تخضع لشروط استخدام وحدود؛ يفضّل استخدام مزوّدات رسمية حيث أمكن.
