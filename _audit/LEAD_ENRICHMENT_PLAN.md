# Lead Enrichment Engine - خطة شاملة

## الهدف
نظام بحث ذكي متعدد الطبقات للبحث عن **عميل واحد محدد** وإثراء بياناته من مصادر متعددة.

> ⚠️ **هام:** هذا النظام **مستقل تماماً** عن منظومة Worker الداخلية. نستغل فقط البنية التحتية للمتصفح (Puppeteer/Playwright).

---

## المعمارية العامة

```
┌─────────────────────────────────────────────────────────────────┐
│                    Lead Enrichment Engine                        │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  ┌──────────────┐    ┌──────────────┐    ┌──────────────┐       │
│  │   المدخلات   │───▶│  محرك الكلمات │───▶│   طبقات     │       │
│  │  Lead Data   │    │   المفتاحية   │    │   البحث     │       │
│  └──────────────┘    └──────────────┘    └──────────────┘       │
│                                                 │                │
│                                                 ▼                │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │                    طبقات البحث المتعددة                   │   │
│  ├──────────────────────────────────────────────────────────┤   │
│  │  Layer 1: Website Search (Google)                        │   │
│  │  Layer 2: Social Media Search (Twitter, LinkedIn, etc)   │   │
│  │  Layer 3: Maps Search (Google Maps - مرة واحدة)          │   │
│  │  Layer 4: Contact Info Search (Email, Phone)             │   │
│  │  Layer 5: Reviews & Reputation                           │   │
│  └──────────────────────────────────────────────────────────┘   │
│                                                 │                │
│                                                 ▼                │
│  ┌──────────────┐    ┌──────────────┐    ┌──────────────┐       │
│  │   التحقق    │───▶│   التجميع    │───▶│   المخرجات   │       │
│  │  والتمييز   │    │  والتقييم    │    │  Enriched    │       │
│  └──────────────┘    └──────────────┘    └──────────────┘       │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

---

## 1. المدخلات (Lead Input)

البيانات المتوفرة عن العميل:
```typescript
interface LeadInput {
  name: string;           // اسم الشركة/المحل (إلزامي)
  city?: string;          // المدينة (اختياري لكن مهم للتمييز)
  phone?: string;         // رقم الهاتف (للتحقق)
  category?: string;      // التصنيف (مطعم، شركة، محل...)
  existingWebsite?: string; // موقع موجود مسبقاً
  country?: string;       // الدولة (افتراضي: السعودية)
}
```

---

## 2. محرك الكلمات المفتاحية الذكي (Keyword Engine)

### 2.1 توليد صيغ البحث

```typescript
interface SearchQuery {
  query: string;
  purpose: 'website' | 'social' | 'maps' | 'contact' | 'reviews';
  platform?: string;
  confidence: number; // 0-1
}

function generateSearchQueries(lead: LeadInput): SearchQuery[] {
  const queries: SearchQuery[] = [];
  
  // === Layer 1: Website Search ===
  
  // صيغة 1: الاسم + المدينة
  queries.push({
    query: `"${lead.name}" ${lead.city || ''} الموقع الرسمي`,
    purpose: 'website',
    confidence: 0.9
  });
  
  // صيغة 2: الاسم بالإنجليزية (إن أمكن تحويله)
  queries.push({
    query: `"${lead.name}" official website`,
    purpose: 'website',
    confidence: 0.7
  });
  
  // صيغة 3: الاسم + التصنيف
  if (lead.category) {
    queries.push({
      query: `"${lead.name}" ${lead.category} ${lead.city || ''}`,
      purpose: 'website',
      confidence: 0.8
    });
  }
  
  // === Layer 2: Social Media Search ===
  
  const socialPlatforms = [
    { name: 'twitter', domain: 'twitter.com OR x.com' },
    { name: 'instagram', domain: 'instagram.com' },
    { name: 'linkedin', domain: 'linkedin.com' },
    { name: 'facebook', domain: 'facebook.com' },
    { name: 'snapchat', domain: 'snapchat.com' },
    { name: 'tiktok', domain: 'tiktok.com' }
  ];
  
  for (const platform of socialPlatforms) {
    queries.push({
      query: `site:${platform.domain} "${lead.name}"`,
      purpose: 'social',
      platform: platform.name,
      confidence: 0.6
    });
  }
  
  // === Layer 3: Maps Search (مرة واحدة) ===
  queries.push({
    query: `${lead.name} ${lead.city || 'السعودية'}`,
    purpose: 'maps',
    confidence: 0.95
  });
  
  // === Layer 4: Contact Info ===
  queries.push({
    query: `"${lead.name}" ${lead.city || ''} "اتصل بنا" OR "تواصل معنا"`,
    purpose: 'contact',
    confidence: 0.5
  });
  
  // === Layer 5: Reviews ===
  queries.push({
    query: `"${lead.name}" تقييم OR مراجعة OR review`,
    purpose: 'reviews',
    confidence: 0.4
  });
  
  return queries;
}
```

### 2.2 معالجة الأسماء العربية

```typescript
const arabicNameVariations = {
  // تحويلات شائعة
  'ال': ['al-', 'el-', ''],
  'ة': ['a', 'ah', 'h', ''],
  'و': ['w', 'o', 'ou'],
  // ... المزيد
};

function generateNameVariations(name: string): string[] {
  const variations: string[] = [name];
  
  // إزالة "ال" التعريف
  if (name.startsWith('ال')) {
    variations.push(name.substring(2));
  }
  
  // إزالة الفراغات الزائدة
  variations.push(name.replace(/\s+/g, ''));
  
  // تحويل للإنجليزية (transliteration)
  // يتم باستخدام مكتبة أو قاموس
  
  return [...new Set(variations)];
}
```

---

## 3. طبقات البحث (Search Layers)

### 3.1 Layer 1: Website Search

**الهدف:** إيجاد الموقع الإلكتروني الرسمي

```typescript
interface WebsiteSearchResult {
  url: string;
  title: string;
  snippet: string;
  confidence: number;
  isOfficial: boolean;
}

async function searchWebsite(browser: Browser, lead: LeadInput): Promise<WebsiteSearchResult[]> {
  const page = await browser.newPage();
  const results: WebsiteSearchResult[] = [];
  
  // البحث في Google
  await page.goto(`https://www.google.com/search?q=${encodeURIComponent(query)}&hl=ar`);
  
  // استخراج النتائج
  const searchResults = await page.$$eval('.g', elements => {
    return elements.map(el => ({
      url: el.querySelector('a')?.href,
      title: el.querySelector('h3')?.textContent,
      snippet: el.querySelector('.VwiC3b')?.textContent
    }));
  });
  
  // تقييم كل نتيجة
  for (const result of searchResults) {
    const confidence = calculateWebsiteConfidence(result, lead);
    if (confidence > 0.3) {
      results.push({ ...result, confidence, isOfficial: confidence > 0.7 });
    }
  }
  
  return results;
}

function calculateWebsiteConfidence(result: any, lead: LeadInput): number {
  let score = 0;
  
  // الاسم موجود في العنوان
  if (result.title?.includes(lead.name)) score += 0.3;
  
  // الاسم موجود في الـ URL
  if (result.url?.toLowerCase().includes(lead.name.toLowerCase())) score += 0.2;
  
  // المدينة مذكورة
  if (lead.city && result.snippet?.includes(lead.city)) score += 0.2;
  
  // ليس موقع تجميعي (yelp, foursquare, etc)
  const aggregators = ['yelp', 'foursquare', 'tripadvisor', 'zomato', 'hungerstation'];
  if (!aggregators.some(a => result.url?.includes(a))) score += 0.2;
  
  // نطاق سعودي
  if (result.url?.includes('.sa') || result.url?.includes('.com.sa')) score += 0.1;
  
  return Math.min(score, 1);
}
```

### 3.2 Layer 2: Social Media Search

**الهدف:** إيجاد حسابات التواصل الاجتماعي

```typescript
interface SocialMediaResult {
  platform: string;
  url: string;
  handle: string;
  followers?: number;
  verified?: boolean;
  confidence: number;
}

const SOCIAL_PLATFORMS = {
  twitter: {
    patterns: [/twitter\.com\/([^\/\?]+)/, /x\.com\/([^\/\?]+)/],
    searchQuery: (name: string) => `site:twitter.com OR site:x.com "${name}"`
  },
  instagram: {
    patterns: [/instagram\.com\/([^\/\?]+)/],
    searchQuery: (name: string) => `site:instagram.com "${name}"`
  },
  linkedin: {
    patterns: [/linkedin\.com\/company\/([^\/\?]+)/],
    searchQuery: (name: string) => `site:linkedin.com/company "${name}"`
  },
  facebook: {
    patterns: [/facebook\.com\/([^\/\?]+)/],
    searchQuery: (name: string) => `site:facebook.com "${name}"`
  },
  snapchat: {
    patterns: [/snapchat\.com\/add\/([^\/\?]+)/],
    searchQuery: (name: string) => `site:snapchat.com "${name}"`
  },
  tiktok: {
    patterns: [/tiktok\.com\/@([^\/\?]+)/],
    searchQuery: (name: string) => `site:tiktok.com "${name}"`
  }
};

async function searchSocialMedia(browser: Browser, lead: LeadInput): Promise<SocialMediaResult[]> {
  const results: SocialMediaResult[] = [];
  
  for (const [platform, config] of Object.entries(SOCIAL_PLATFORMS)) {
    const query = config.searchQuery(lead.name);
    const page = await browser.newPage();
    
    await page.goto(`https://www.google.com/search?q=${encodeURIComponent(query)}`);
    
    const links = await page.$$eval('a', anchors => 
      anchors.map(a => a.href).filter(href => href)
    );
    
    for (const link of links) {
      for (const pattern of config.patterns) {
        const match = link.match(pattern);
        if (match) {
          const handle = match[1];
          const confidence = calculateSocialConfidence(handle, lead);
          
          if (confidence > 0.3) {
            results.push({
              platform,
              url: link,
              handle,
              confidence
            });
          }
          break;
        }
      }
    }
    
    await page.close();
  }
  
  return results;
}

function calculateSocialConfidence(handle: string, lead: LeadInput): number {
  let score = 0;
  
  const normalizedHandle = handle.toLowerCase().replace(/[_\-\.]/g, '');
  const normalizedName = lead.name.toLowerCase().replace(/\s+/g, '');
  
  // التطابق المباشر
  if (normalizedHandle === normalizedName) score = 0.9;
  // التطابق الجزئي
  else if (normalizedHandle.includes(normalizedName) || normalizedName.includes(normalizedHandle)) score = 0.6;
  // تشابه
  else {
    const similarity = calculateStringSimilarity(normalizedHandle, normalizedName);
    score = similarity * 0.5;
  }
  
  // المدينة في الـ handle
  if (lead.city && normalizedHandle.includes(lead.city.toLowerCase())) score += 0.1;
  
  return Math.min(score, 1);
}
```

### 3.3 Layer 3: Google Maps Search (مرة واحدة)

**الهدف:** التحقق من الموقع الجغرافي وجمع معلومات إضافية

```typescript
interface MapsResult {
  name: string;
  address: string;
  phone?: string;
  website?: string;
  rating?: number;
  reviewCount?: number;
  hours?: string[];
  photos?: string[];
  coordinates: { lat: number; lng: number };
  confidence: number;
}

async function searchMaps(browser: Browser, lead: LeadInput): Promise<MapsResult | null> {
  const page = await browser.newPage();
  
  const query = `${lead.name} ${lead.city || 'السعودية'}`;
  await page.goto(`https://www.google.com/maps/search/${encodeURIComponent(query)}`);
  
  // انتظار تحميل النتائج
  await page.waitForSelector('[role="feed"]', { timeout: 10000 }).catch(() => null);
  
  // النقر على أول نتيجة
  const firstResult = await page.$('[role="feed"] > div:first-child');
  if (!firstResult) return null;
  
  await firstResult.click();
  await page.waitForSelector('[data-section-id="ap"]', { timeout: 5000 }).catch(() => null);
  
  // استخراج البيانات
  const data = await page.evaluate(() => {
    const getName = () => document.querySelector('h1')?.textContent;
    const getAddress = () => document.querySelector('[data-item-id="address"]')?.textContent;
    const getPhone = () => document.querySelector('[data-item-id^="phone"]')?.textContent;
    const getWebsite = () => document.querySelector('[data-item-id="authority"]')?.getAttribute('href');
    const getRating = () => {
      const text = document.querySelector('[role="img"][aria-label*="stars"]')?.getAttribute('aria-label');
      return text ? parseFloat(text) : null;
    };
    
    return { name: getName(), address: getAddress(), phone: getPhone(), website: getWebsite(), rating: getRating() };
  });
  
  // حساب الثقة
  const confidence = calculateMapsConfidence(data, lead);
  
  await page.close();
  
  return confidence > 0.4 ? { ...data, confidence } : null;
}

function calculateMapsConfidence(data: any, lead: LeadInput): number {
  let score = 0;
  
  // تطابق الاسم
  if (data.name?.includes(lead.name) || lead.name.includes(data.name)) score += 0.4;
  
  // تطابق المدينة
  if (lead.city && data.address?.includes(lead.city)) score += 0.3;
  
  // تطابق الهاتف (إن وجد)
  if (lead.phone && data.phone) {
    const normalizedLeadPhone = lead.phone.replace(/\D/g, '');
    const normalizedDataPhone = data.phone.replace(/\D/g, '');
    if (normalizedLeadPhone.includes(normalizedDataPhone) || normalizedDataPhone.includes(normalizedLeadPhone)) {
      score += 0.3;
    }
  }
  
  return Math.min(score, 1);
}
```

---

## 4. طبقة التحقق والتمييز (Verification Layer)

### 4.1 التعامل مع تشابه الأسماء

```typescript
interface VerificationResult {
  isMatch: boolean;
  confidence: number;
  matchedFields: string[];
  conflicts: string[];
}

function verifyMatch(lead: LeadInput, found: any): VerificationResult {
  const matchedFields: string[] = [];
  const conflicts: string[] = [];
  
  // 1. مقارنة الأسماء
  const nameSimilarity = calculateStringSimilarity(lead.name, found.name);
  if (nameSimilarity > 0.8) matchedFields.push('name');
  else if (nameSimilarity < 0.4) conflicts.push('name');
  
  // 2. مقارنة المدينة
  if (lead.city && found.city) {
    if (lead.city === found.city) matchedFields.push('city');
    else conflicts.push('city');
  }
  
  // 3. مقارنة الهاتف
  if (lead.phone && found.phone) {
    const phoneMatch = normalizePhone(lead.phone) === normalizePhone(found.phone);
    if (phoneMatch) matchedFields.push('phone');
    else conflicts.push('phone');
  }
  
  // 4. مقارنة التصنيف
  if (lead.category && found.category) {
    if (isSameCategory(lead.category, found.category)) matchedFields.push('category');
  }
  
  // حساب الثقة النهائية
  const confidence = (matchedFields.length * 0.3) - (conflicts.length * 0.2);
  
  return {
    isMatch: confidence > 0.5 && conflicts.length === 0,
    confidence: Math.max(0, Math.min(1, confidence)),
    matchedFields,
    conflicts
  };
}

// خوارزمية Levenshtein للتشابه
function calculateStringSimilarity(str1: string, str2: string): number {
  const s1 = str1.toLowerCase().trim();
  const s2 = str2.toLowerCase().trim();
  
  if (s1 === s2) return 1;
  if (s1.length === 0 || s2.length === 0) return 0;
  
  const matrix: number[][] = [];
  
  for (let i = 0; i <= s1.length; i++) {
    matrix[i] = [i];
  }
  for (let j = 0; j <= s2.length; j++) {
    matrix[0][j] = j;
  }
  
  for (let i = 1; i <= s1.length; i++) {
    for (let j = 1; j <= s2.length; j++) {
      const cost = s1[i - 1] === s2[j - 1] ? 0 : 1;
      matrix[i][j] = Math.min(
        matrix[i - 1][j] + 1,
        matrix[i][j - 1] + 1,
        matrix[i - 1][j - 1] + cost
      );
    }
  }
  
  const maxLen = Math.max(s1.length, s2.length);
  return 1 - matrix[s1.length][s2.length] / maxLen;
}
```

### 4.2 فلترة النتائج المكررة

```typescript
function deduplicateResults(results: any[]): any[] {
  const seen = new Map<string, any>();
  
  for (const result of results) {
    const key = normalizeForDedup(result);
    const existing = seen.get(key);
    
    if (!existing || result.confidence > existing.confidence) {
      seen.set(key, result);
    }
  }
  
  return Array.from(seen.values());
}

function normalizeForDedup(result: any): string {
  // إنشاء مفتاح فريد بناءً على URL أو الاسم
  if (result.url) {
    return new URL(result.url).hostname + new URL(result.url).pathname;
  }
  return result.name?.toLowerCase().replace(/\s+/g, '') || '';
}
```

---

## 5. تجميع النتائج (Result Aggregation)

### 5.1 هيكل المخرجات

```typescript
interface EnrichedLead {
  // البيانات الأصلية
  original: LeadInput;
  
  // البيانات المُثراة
  enriched: {
    // الموقع الإلكتروني
    website?: {
      url: string;
      confidence: number;
      source: 'google' | 'maps' | 'social';
    };
    
    // حسابات التواصل
    socialMedia: {
      twitter?: { url: string; handle: string; confidence: number };
      instagram?: { url: string; handle: string; confidence: number };
      linkedin?: { url: string; handle: string; confidence: number };
      facebook?: { url: string; handle: string; confidence: number };
      snapchat?: { url: string; handle: string; confidence: number };
      tiktok?: { url: string; handle: string; confidence: number };
    };
    
    // معلومات الخريطة
    maps?: {
      address: string;
      coordinates: { lat: number; lng: number };
      rating?: number;
      reviewCount?: number;
      hours?: string[];
      confidence: number;
    };
    
    // معلومات الاتصال
    contact?: {
      phones: string[];
      emails: string[];
      confidence: number;
    };
  };
  
  // ملخص
  summary: {
    totalConfidence: number;
    fieldsEnriched: string[];
    searchesPerformed: number;
    duration: number;
  };
}
```

### 5.2 حساب الثقة الإجمالية

```typescript
function calculateTotalConfidence(enriched: EnrichedLead['enriched']): number {
  const weights = {
    website: 0.25,
    socialMedia: 0.20,
    maps: 0.30,
    contact: 0.25
  };
  
  let totalScore = 0;
  let totalWeight = 0;
  
  if (enriched.website) {
    totalScore += enriched.website.confidence * weights.website;
    totalWeight += weights.website;
  }
  
  const socialCount = Object.values(enriched.socialMedia).filter(Boolean).length;
  if (socialCount > 0) {
    const avgSocialConfidence = Object.values(enriched.socialMedia)
      .filter(Boolean)
      .reduce((sum, s) => sum + s.confidence, 0) / socialCount;
    totalScore += avgSocialConfidence * weights.socialMedia;
    totalWeight += weights.socialMedia;
  }
  
  if (enriched.maps) {
    totalScore += enriched.maps.confidence * weights.maps;
    totalWeight += weights.maps;
  }
  
  if (enriched.contact) {
    totalScore += enriched.contact.confidence * weights.contact;
    totalWeight += weights.contact;
  }
  
  return totalWeight > 0 ? totalScore / totalWeight : 0;
}
```

---

## 6. التكامل مع OP-Target

### 6.1 API Endpoint

```
POST /api/leads/enrich
Content-Type: application/json

{
  "leadId": 123,
  // أو
  "lead": {
    "name": "مطعم البرجر الوطني",
    "city": "الرياض",
    "phone": "0501234567"
  }
}
```

### 6.2 Response

```json
{
  "ok": true,
  "enriched": {
    "website": {
      "url": "https://alburger.sa",
      "confidence": 0.85
    },
    "socialMedia": {
      "twitter": { "url": "https://twitter.com/alburger_sa", "handle": "alburger_sa", "confidence": 0.78 },
      "instagram": { "url": "https://instagram.com/alburger.sa", "handle": "alburger.sa", "confidence": 0.82 }
    },
    "maps": {
      "address": "شارع الملك فهد، الرياض",
      "rating": 4.5,
      "reviewCount": 234,
      "confidence": 0.92
    }
  },
  "summary": {
    "totalConfidence": 0.84,
    "fieldsEnriched": ["website", "twitter", "instagram", "maps"],
    "duration": 12500
  }
}
```

---

## 7. السيناريوهات والحالات الخاصة

### 7.1 عدم وجود نتائج
- إرجاع `enriched: {}` مع `totalConfidence: 0`
- تسجيل السبب في `summary.notes`

### 7.2 نتائج متعددة متشابهة
- إرجاع أعلى نتيجة ثقة
- تضمين `alternatives` للنتائج الأخرى

### 7.3 تعارض في البيانات
- إرجاع `conflicts` array
- ترك القرار للمستخدم

### 7.4 Rate Limiting
- تأخير بين الطلبات (2-5 ثواني)
- استخدام User-Agent عشوائي
- تدوير الـ IP إن أمكن

---

## 8. خطة التنفيذ

| المرحلة | المهمة | الأولوية | الوقت المقدر |
|---------|--------|----------|--------------|
| 1 | إنشاء `LeadEnricher` class | عالية | 2 ساعة |
| 2 | تنفيذ Website Search Layer | عالية | 2 ساعة |
| 3 | تنفيذ Social Media Search Layer | عالية | 3 ساعات |
| 4 | تنفيذ Maps Search Layer | عالية | 2 ساعة |
| 5 | تنفيذ Verification Layer | متوسطة | 2 ساعة |
| 6 | تنفيذ Result Aggregation | متوسطة | 1 ساعة |
| 7 | إنشاء API Endpoint | متوسطة | 1 ساعة |
| 8 | التكامل مع OP-Target UI | متوسطة | 2 ساعة |
| 9 | الاختبار والتحسين | عالية | 3 ساعات |

**الإجمالي:** ~18 ساعة عمل

---

## 9. الملفات المطلوب إنشاؤها

```
forge.op-tg.com/
├── lib/
│   └── lead_enricher/
│       ├── index.js              # Main entry point
│       ├── keyword_engine.js     # Keyword generation
│       ├── layers/
│       │   ├── website.js        # Website search
│       │   ├── social.js         # Social media search
│       │   ├── maps.js           # Google Maps search
│       │   └── contact.js        # Contact info search
│       ├── verification.js       # Verification & dedup
│       └── aggregator.js         # Result aggregation
├── v1/api/
│   └── leads/
│       └── enrich.php            # API endpoint
```

---

## 10. الخطوة التالية

**هل توافق على هذه الخطة؟** إذا نعم، سأبدأ بتنفيذ المرحلة الأولى: إنشاء `LeadEnricher` class الأساسي.
