/**
 * Social Media Search Layer - طبقة البحث عن حسابات التواصل الاجتماعي
 * 
 * تبحث في Google عن حسابات العميل على منصات التواصل المختلفة
 */

export class SocialMediaSearchLayer {
  constructor(enricher) {
    this.enricher = enricher;
    
    // أنماط استخراج الـ handle من الروابط
    this.platformPatterns = {
      twitter: [
        /(?:twitter\.com|x\.com)\/([a-zA-Z0-9_]{1,15})(?:\/|\?|$)/,
      ],
      instagram: [
        /instagram\.com\/([a-zA-Z0-9_.]{1,30})(?:\/|\?|$)/,
      ],
      linkedin: [
        /linkedin\.com\/company\/([a-zA-Z0-9\-]+)(?:\/|\?|$)/,
        /linkedin\.com\/in\/([a-zA-Z0-9\-]+)(?:\/|\?|$)/,
      ],
      facebook: [
        /facebook\.com\/([a-zA-Z0-9.]+)(?:\/|\?|$)/,
        /fb\.com\/([a-zA-Z0-9.]+)(?:\/|\?|$)/,
      ],
      snapchat: [
        /snapchat\.com\/add\/([a-zA-Z0-9_\-.]+)(?:\/|\?|$)/,
      ],
      tiktok: [
        /tiktok\.com\/@([a-zA-Z0-9_.]+)(?:\/|\?|$)/,
      ],
      youtube: [
        /youtube\.com\/(?:c\/|channel\/|user\/|@)([a-zA-Z0-9_\-]+)(?:\/|\?|$)/,
      ]
    };

    // handles يجب تجاهلها (صفحات عامة)
    this.ignoredHandles = [
      'home', 'explore', 'search', 'login', 'signup', 'register',
      'about', 'help', 'settings', 'privacy', 'terms', 'contact',
      'share', 'intent', 'hashtag', 'i', 'watch', 'channel'
    ];
  }

  /**
   * البحث عن حسابات التواصل الاجتماعي
   */
  async search(lead, queries) {
    const results = [];
    const page = await this.enricher.createPage();
    const processedUrls = new Set();

    try {
      // تجميع الاستعلامات حسب المنصة
      const platformQueries = this.groupByPlatform(queries);

      for (const [platform, platformQs] of Object.entries(platformQueries)) {
        console.log(`[SocialLayer] Searching ${platform}...`);
        
        // استخدام أول استعلام فقط لكل منصة
        const query = platformQs[0];
        
        const searchResults = await this.googleSearch(page, query.query);
        
        for (const result of searchResults) {
          // تجنب المكررات
          if (processedUrls.has(result.url)) continue;
          processedUrls.add(result.url);

          const extracted = this.extractSocialInfo(result.url, platform);
          
          if (extracted && !this.isIgnoredHandle(extracted.handle)) {
            const confidence = this.calculateConfidence(extracted, lead, result);
            
            if (confidence > 0.3) {
              results.push({
                platform,
                url: result.url,
                handle: extracted.handle,
                title: result.title,
                confidence,
                source: 'google'
              });
            }
          }
        }

        await this.enricher.delay(1500);
      }
    } finally {
      await page.close();
    }

    return results;
  }

  /**
   * تجميع الاستعلامات حسب المنصة
   */
  groupByPlatform(queries) {
    const grouped = {};
    
    for (const query of queries) {
      if (query.platform) {
        if (!grouped[query.platform]) {
          grouped[query.platform] = [];
        }
        grouped[query.platform].push(query);
      }
    }
    
    return grouped;
  }

  /**
   * البحث في Google
   */
  async googleSearch(page, query) {
    const results = [];
    
    try {
      const searchUrl = `https://www.google.com/search?q=${encodeURIComponent(query)}&hl=ar&num=10`;
      await page.goto(searchUrl, { waitUntil: 'domcontentloaded', timeout: 15000 });

      await page.waitForSelector('#search', { timeout: 10000 }).catch(() => null);

      const searchResults = await page.evaluate(() => {
        const items = [];
        const links = document.querySelectorAll('#search a[href^="http"]');

        for (const link of links) {
          const url = link.href;
          const title = link.textContent || '';
          
          // تجاهل روابط Google
          if (!url.includes('google.com')) {
            items.push({ url, title });
          }
        }

        return items;
      });

      results.push(...searchResults);
    } catch (err) {
      console.error('[SocialLayer] Google search error:', err.message);
    }

    return results;
  }

  /**
   * استخراج معلومات الحساب من الرابط
   */
  extractSocialInfo(url, platform) {
    const patterns = this.platformPatterns[platform];
    if (!patterns) return null;

    for (const pattern of patterns) {
      const match = url.match(pattern);
      if (match && match[1]) {
        return {
          handle: match[1],
          platform
        };
      }
    }

    return null;
  }

  /**
   * هل الـ handle يجب تجاهله؟
   */
  isIgnoredHandle(handle) {
    if (!handle) return true;
    const lower = handle.toLowerCase();
    return this.ignoredHandles.includes(lower) || lower.length < 2;
  }

  /**
   * حساب درجة الثقة
   */
  calculateConfidence(extracted, lead, result) {
    let score = 0.3; // نقطة بداية

    const normalizedHandle = this.normalize(extracted.handle);
    const normalizedName = this.normalize(lead.name);
    const nameNoSpaces = normalizedName.replace(/\s+/g, '');

    // 1. تطابق مباشر
    if (normalizedHandle === nameNoSpaces) {
      score += 0.5;
    }
    // 2. الـ handle يحتوي الاسم
    else if (normalizedHandle.includes(nameNoSpaces) || nameNoSpaces.includes(normalizedHandle)) {
      score += 0.35;
    }
    // 3. تشابه جزئي
    else {
      const similarity = this.calculateSimilarity(normalizedHandle, nameNoSpaces);
      score += similarity * 0.3;
    }

    // 4. المدينة في الـ handle
    if (lead.city) {
      const normalizedCity = this.normalize(lead.city);
      if (normalizedHandle.includes(normalizedCity)) {
        score += 0.1;
      }
    }

    // 5. العنوان يحتوي اسم العميل
    if (result.title) {
      const normalizedTitle = this.normalize(result.title);
      if (normalizedTitle.includes(normalizedName)) {
        score += 0.15;
      }
    }

    return Math.min(score, 1);
  }

  /**
   * تطبيع النص
   */
  normalize(text) {
    if (!text) return '';
    return text
      .toLowerCase()
      .replace(/[أإآا]/g, 'ا')
      .replace(/[ة]/g, 'ه')
      .replace(/[ى]/g, 'ي')
      .replace(/[\u064B-\u065F]/g, '')
      .replace(/[_\-\.]/g, '')
      .trim();
  }

  /**
   * حساب التشابه بين نصين (Levenshtein)
   */
  calculateSimilarity(str1, str2) {
    if (str1 === str2) return 1;
    if (!str1 || !str2) return 0;

    const len1 = str1.length;
    const len2 = str2.length;
    const matrix = [];

    for (let i = 0; i <= len1; i++) {
      matrix[i] = [i];
    }
    for (let j = 0; j <= len2; j++) {
      matrix[0][j] = j;
    }

    for (let i = 1; i <= len1; i++) {
      for (let j = 1; j <= len2; j++) {
        const cost = str1[i - 1] === str2[j - 1] ? 0 : 1;
        matrix[i][j] = Math.min(
          matrix[i - 1][j] + 1,
          matrix[i][j - 1] + 1,
          matrix[i - 1][j - 1] + cost
        );
      }
    }

    const maxLen = Math.max(len1, len2);
    return 1 - matrix[len1][len2] / maxLen;
  }
}

export default SocialMediaSearchLayer;
