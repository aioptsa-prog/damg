/**
 * Keyword Engine - محرك الكلمات المفتاحية الذكي
 * 
 * يولّد صيغ بحث متعددة ومتنوعة للوصول لأفضل النتائج
 */

export class KeywordEngine {
  constructor() {
    // منصات التواصل الاجتماعي
    this.socialPlatforms = [
      { name: 'twitter', domains: ['twitter.com', 'x.com'] },
      { name: 'instagram', domains: ['instagram.com'] },
      { name: 'linkedin', domains: ['linkedin.com/company'] },
      { name: 'facebook', domains: ['facebook.com'] },
      { name: 'snapchat', domains: ['snapchat.com'] },
      { name: 'tiktok', domains: ['tiktok.com'] },
      { name: 'youtube', domains: ['youtube.com'] }
    ];

    // كلمات مفتاحية للموقع الرسمي
    this.websiteKeywords = {
      ar: ['الموقع الرسمي', 'موقع', 'رسمي'],
      en: ['official website', 'official site', 'website']
    };

    // كلمات مفتاحية للتواصل
    this.contactKeywords = {
      ar: ['اتصل بنا', 'تواصل معنا', 'رقم الهاتف', 'البريد الإلكتروني'],
      en: ['contact us', 'contact', 'phone', 'email']
    };
  }

  /**
   * توليد جميع صيغ البحث للعميل
   * @param {Object} lead - بيانات العميل
   * @returns {Array} - قائمة الاستعلامات
   */
  generate(lead) {
    const queries = [];
    const nameVariations = this.generateNameVariations(lead.name);

    // === Website Search Queries ===
    queries.push(...this.generateWebsiteQueries(lead, nameVariations));

    // === Social Media Search Queries ===
    queries.push(...this.generateSocialQueries(lead, nameVariations));

    // === Maps Search Query ===
    queries.push(...this.generateMapsQueries(lead, nameVariations));

    // === Contact Search Queries ===
    queries.push(...this.generateContactQueries(lead, nameVariations));

    return queries;
  }

  /**
   * توليد تنويعات الاسم
   */
  generateNameVariations(name) {
    const variations = [name];
    
    // إزالة "ال" التعريف
    if (name.startsWith('ال')) {
      variations.push(name.substring(2));
    }
    
    // إزالة الفراغات
    const noSpaces = name.replace(/\s+/g, '');
    if (noSpaces !== name) {
      variations.push(noSpaces);
    }
    
    // إزالة الكلمات الشائعة
    const commonWords = ['مطعم', 'مؤسسة', 'شركة', 'محل', 'متجر', 'مركز'];
    for (const word of commonWords) {
      if (name.includes(word)) {
        const cleaned = name.replace(word, '').trim();
        if (cleaned.length > 2) {
          variations.push(cleaned);
        }
      }
    }

    // تحويل بسيط للإنجليزية (transliteration)
    const englishVersion = this.transliterate(name);
    if (englishVersion && englishVersion !== name) {
      variations.push(englishVersion);
    }

    return [...new Set(variations)];
  }

  /**
   * تحويل بسيط من العربية للإنجليزية
   */
  transliterate(text) {
    const map = {
      'ا': 'a', 'أ': 'a', 'إ': 'e', 'آ': 'a',
      'ب': 'b', 'ت': 't', 'ث': 'th',
      'ج': 'j', 'ح': 'h', 'خ': 'kh',
      'د': 'd', 'ذ': 'th', 'ر': 'r', 'ز': 'z',
      'س': 's', 'ش': 'sh', 'ص': 's', 'ض': 'd',
      'ط': 't', 'ظ': 'z', 'ع': 'a', 'غ': 'gh',
      'ف': 'f', 'ق': 'q', 'ك': 'k', 'ل': 'l',
      'م': 'm', 'ن': 'n', 'ه': 'h', 'و': 'w',
      'ي': 'y', 'ى': 'a', 'ة': 'a', 'ء': '',
      ' ': ' '
    };

    let result = '';
    for (const char of text) {
      result += map[char] || char;
    }
    return result.trim();
  }

  /**
   * استعلامات البحث عن الموقع الإلكتروني
   */
  generateWebsiteQueries(lead, nameVariations) {
    const queries = [];
    const city = lead.city || '';

    for (const name of nameVariations.slice(0, 3)) { // أول 3 تنويعات فقط
      // صيغة 1: الاسم + المدينة + الموقع الرسمي
      queries.push({
        query: `"${name}" ${city} الموقع الرسمي`,
        purpose: 'website',
        confidence: 0.9,
        variation: 'official_ar'
      });

      // صيغة 2: الاسم فقط (بحث عام)
      queries.push({
        query: `"${name}" ${city}`,
        purpose: 'website',
        confidence: 0.7,
        variation: 'general'
      });

      // صيغة 3: الاسم + التصنيف
      if (lead.category) {
        queries.push({
          query: `"${name}" ${lead.category} ${city}`,
          purpose: 'website',
          confidence: 0.8,
          variation: 'with_category'
        });
      }
    }

    return queries;
  }

  /**
   * استعلامات البحث عن حسابات التواصل الاجتماعي
   */
  generateSocialQueries(lead, nameVariations) {
    const queries = [];
    const primaryName = nameVariations[0];

    for (const platform of this.socialPlatforms) {
      // صيغة site: للبحث المباشر
      const siteQuery = platform.domains.map(d => `site:${d}`).join(' OR ');
      
      queries.push({
        query: `${siteQuery} "${primaryName}"`,
        purpose: 'social',
        platform: platform.name,
        confidence: 0.7,
        variation: 'site_search'
      });

      // صيغة بديلة مع المدينة
      if (lead.city) {
        queries.push({
          query: `${siteQuery} "${primaryName}" ${lead.city}`,
          purpose: 'social',
          platform: platform.name,
          confidence: 0.8,
          variation: 'site_search_city'
        });
      }
    }

    return queries;
  }

  /**
   * استعلام البحث في الخريطة (مرة واحدة)
   */
  generateMapsQueries(lead, nameVariations) {
    const primaryName = nameVariations[0];
    const city = lead.city || 'السعودية';

    return [{
      query: `${primaryName} ${city}`,
      purpose: 'maps',
      confidence: 0.95,
      variation: 'maps_primary'
    }];
  }

  /**
   * استعلامات البحث عن معلومات الاتصال
   */
  generateContactQueries(lead, nameVariations) {
    const queries = [];
    const primaryName = nameVariations[0];
    const city = lead.city || '';

    queries.push({
      query: `"${primaryName}" ${city} "اتصل بنا" OR "تواصل معنا"`,
      purpose: 'contact',
      confidence: 0.5,
      variation: 'contact_ar'
    });

    queries.push({
      query: `"${primaryName}" ${city} email OR phone OR contact`,
      purpose: 'contact',
      confidence: 0.4,
      variation: 'contact_en'
    });

    return queries;
  }
}

export default KeywordEngine;
