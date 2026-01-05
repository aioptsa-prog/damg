/**
 * Website Search Layer - طبقة البحث عن الموقع الإلكتروني
 * 
 * تبحث في Google عن الموقع الرسمي للعميل
 */

export class WebsiteSearchLayer {
  constructor(enricher) {
    this.enricher = enricher;
    
    // مواقع تجميعية يجب تجاهلها
    this.aggregatorDomains = [
      'yelp.com', 'tripadvisor.com', 'foursquare.com', 'zomato.com',
      'hungerstation.com', 'talabat.com', 'jahez.net', 'toyou.io',
      'yellowpages', 'daleeli.com', 'kompass.com', 'dnb.com',
      'facebook.com', 'twitter.com', 'instagram.com', 'linkedin.com',
      'youtube.com', 'tiktok.com', 'snapchat.com',
      'google.com/maps', 'maps.google.com'
    ];

    // نطاقات سعودية (أولوية عالية)
    this.saudiDomains = ['.sa', '.com.sa', '.net.sa', '.org.sa'];
  }

  /**
   * البحث عن الموقع الإلكتروني
   */
  async search(lead, queries) {
    const results = [];
    const page = await this.enricher.createPage();

    try {
      for (const queryObj of queries.slice(0, 3)) { // أول 3 استعلامات فقط
        console.log(`[WebsiteLayer] Searching: ${queryObj.query}`);
        
        const searchResults = await this.googleSearch(page, queryObj.query);
        
        for (const result of searchResults) {
          const confidence = this.calculateConfidence(result, lead, queryObj);
          
          if (confidence > 0.3) {
            results.push({
              ...result,
              confidence,
              queryUsed: queryObj.query,
              source: 'google'
            });
          }
        }

        await this.enricher.delay(1500);
      }
    } finally {
      await page.close();
    }

    // ترتيب حسب الثقة
    return results.sort((a, b) => b.confidence - a.confidence);
  }

  /**
   * البحث في Google
   */
  async googleSearch(page, query) {
    const results = [];
    
    try {
      const searchUrl = `https://www.google.com/search?q=${encodeURIComponent(query)}&hl=ar&gl=sa&num=10`;
      await page.goto(searchUrl, { waitUntil: 'domcontentloaded', timeout: 15000 });

      // انتظار تحميل النتائج
      await page.waitForSelector('#search', { timeout: 10000 }).catch(() => null);

      // استخراج النتائج
      const searchResults = await page.evaluate(() => {
        const items = [];
        const resultElements = document.querySelectorAll('#search .g');

        for (const el of resultElements) {
          const linkEl = el.querySelector('a[href^="http"]');
          const titleEl = el.querySelector('h3');
          const snippetEl = el.querySelector('[data-sncf], .VwiC3b, [data-content-feature]');

          if (linkEl && titleEl) {
            const url = linkEl.href;
            // تجاهل روابط Google الداخلية
            if (!url.includes('google.com/search') && !url.includes('webcache')) {
              items.push({
                url: url,
                title: titleEl.textContent || '',
                snippet: snippetEl?.textContent || '',
                domain: new URL(url).hostname
              });
            }
          }
        }

        return items.slice(0, 10);
      });

      results.push(...searchResults);
    } catch (err) {
      console.error('[WebsiteLayer] Google search error:', err.message);
    }

    return results;
  }

  /**
   * حساب درجة الثقة للنتيجة
   */
  calculateConfidence(result, lead, queryObj) {
    let score = queryObj.confidence * 0.3; // وزن الاستعلام

    // 1. تجاهل المواقع التجميعية
    if (this.isAggregator(result.url)) {
      return 0;
    }

    // 2. الاسم موجود في العنوان
    const normalizedName = this.normalize(lead.name);
    const normalizedTitle = this.normalize(result.title);
    if (normalizedTitle.includes(normalizedName)) {
      score += 0.25;
    }

    // 3. الاسم موجود في الـ URL
    const normalizedUrl = this.normalize(result.domain);
    if (normalizedUrl.includes(normalizedName.replace(/\s/g, ''))) {
      score += 0.2;
    }

    // 4. المدينة مذكورة في الوصف
    if (lead.city && result.snippet) {
      if (this.normalize(result.snippet).includes(this.normalize(lead.city))) {
        score += 0.15;
      }
    }

    // 5. نطاق سعودي (أولوية)
    if (this.isSaudiDomain(result.url)) {
      score += 0.1;
    }

    // 6. كلمات دالة على الموقع الرسمي
    const officialKeywords = ['رسمي', 'official', 'الرئيسية', 'home'];
    if (officialKeywords.some(kw => normalizedTitle.includes(kw) || result.snippet?.toLowerCase().includes(kw))) {
      score += 0.1;
    }

    return Math.min(score, 1);
  }

  /**
   * تطبيع النص للمقارنة
   */
  normalize(text) {
    if (!text) return '';
    return text
      .toLowerCase()
      .replace(/[أإآا]/g, 'ا')
      .replace(/[ة]/g, 'ه')
      .replace(/[ى]/g, 'ي')
      .replace(/[\u064B-\u065F]/g, '') // إزالة التشكيل
      .trim();
  }

  /**
   * هل الموقع تجميعي؟
   */
  isAggregator(url) {
    const lowerUrl = url.toLowerCase();
    return this.aggregatorDomains.some(domain => lowerUrl.includes(domain));
  }

  /**
   * هل النطاق سعودي؟
   */
  isSaudiDomain(url) {
    const lowerUrl = url.toLowerCase();
    return this.saudiDomains.some(domain => lowerUrl.includes(domain));
  }
}

export default WebsiteSearchLayer;
