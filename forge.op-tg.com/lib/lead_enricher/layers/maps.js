/**
 * Maps Search Layer - طبقة البحث في الخريطة
 * 
 * تبحث في Google Maps عن العميل مرة واحدة فقط
 * وتستخرج معلومات الموقع والتقييم والتفاصيل
 */

export class MapsSearchLayer {
  constructor(enricher) {
    this.enricher = enricher;
  }

  /**
   * البحث في Google Maps (مرة واحدة)
   */
  async search(lead) {
    const page = await this.enricher.createPage();
    
    try {
      const query = `${lead.name} ${lead.city || 'السعودية'}`;
      console.log(`[MapsLayer] Searching: ${query}`);

      // الذهاب لـ Google Maps
      const mapsUrl = `https://www.google.com/maps/search/${encodeURIComponent(query)}`;
      await page.goto(mapsUrl, { waitUntil: 'networkidle2', timeout: 20000 });

      // انتظار تحميل النتائج
      await page.waitForSelector('[role="feed"], [role="main"]', { timeout: 15000 }).catch(() => null);
      await this.enricher.delay(2000);

      // محاولة النقر على أول نتيجة
      const firstResult = await page.$('[role="feed"] > div > div > a, [role="article"]');
      if (firstResult) {
        await firstResult.click();
        await this.enricher.delay(2000);
      }

      // استخراج البيانات
      const data = await this.extractPlaceData(page);
      
      if (!data || !data.name) {
        console.log('[MapsLayer] No place data found');
        return null;
      }

      // حساب الثقة
      const confidence = this.calculateConfidence(data, lead);
      
      if (confidence < 0.4) {
        console.log(`[MapsLayer] Low confidence (${confidence}), skipping`);
        return null;
      }

      return {
        ...data,
        confidence,
        source: 'google_maps'
      };

    } catch (err) {
      console.error('[MapsLayer] Error:', err.message);
      return null;
    } finally {
      await page.close();
    }
  }

  /**
   * استخراج بيانات المكان من الصفحة
   */
  async extractPlaceData(page) {
    return await page.evaluate(() => {
      const data = {
        name: null,
        address: null,
        phone: null,
        website: null,
        rating: null,
        reviewCount: null,
        hours: [],
        category: null,
        coordinates: null
      };

      // الاسم
      const nameEl = document.querySelector('h1.DUwDvf, h1[data-attrid="title"]');
      data.name = nameEl?.textContent?.trim() || null;

      // العنوان
      const addressEl = document.querySelector('[data-item-id="address"] .fontBodyMedium, button[data-item-id="address"]');
      data.address = addressEl?.textContent?.trim() || null;

      // الهاتف
      const phoneEl = document.querySelector('[data-item-id^="phone"] .fontBodyMedium, button[data-item-id^="phone"]');
      if (phoneEl) {
        const phoneText = phoneEl.textContent?.trim();
        // استخراج الرقم فقط
        const phoneMatch = phoneText?.match(/[\d\s\+\-\(\)]+/);
        data.phone = phoneMatch ? phoneMatch[0].replace(/\s+/g, '') : null;
      }

      // الموقع الإلكتروني
      const websiteEl = document.querySelector('[data-item-id="authority"] a, a[data-item-id="authority"]');
      data.website = websiteEl?.href || null;

      // التقييم
      const ratingEl = document.querySelector('[role="img"][aria-label*="stars"], .fontDisplayLarge');
      if (ratingEl) {
        const ratingText = ratingEl.getAttribute('aria-label') || ratingEl.textContent;
        const ratingMatch = ratingText?.match(/([\d.]+)/);
        data.rating = ratingMatch ? parseFloat(ratingMatch[1]) : null;
      }

      // عدد التقييمات
      const reviewCountEl = document.querySelector('[aria-label*="reviews"], .fontBodyMedium span');
      if (reviewCountEl) {
        const countText = reviewCountEl.textContent || reviewCountEl.getAttribute('aria-label');
        const countMatch = countText?.match(/([\d,]+)/);
        data.reviewCount = countMatch ? parseInt(countMatch[1].replace(/,/g, '')) : null;
      }

      // التصنيف
      const categoryEl = document.querySelector('button[jsaction*="category"]');
      data.category = categoryEl?.textContent?.trim() || null;

      // الإحداثيات من الـ URL
      const url = window.location.href;
      const coordMatch = url.match(/@(-?\d+\.\d+),(-?\d+\.\d+)/);
      if (coordMatch) {
        data.coordinates = {
          lat: parseFloat(coordMatch[1]),
          lng: parseFloat(coordMatch[2])
        };
      }

      // ساعات العمل
      const hoursButton = document.querySelector('[data-item-id="oh"] button, [aria-label*="hours"]');
      if (hoursButton) {
        // محاولة استخراج ساعات العمل المرئية
        const hoursText = hoursButton.textContent?.trim();
        if (hoursText) {
          data.hours = [hoursText];
        }
      }

      return data;
    });
  }

  /**
   * حساب درجة الثقة
   */
  calculateConfidence(data, lead) {
    let score = 0;

    // 1. تطابق الاسم
    if (data.name && lead.name) {
      const similarity = this.calculateSimilarity(
        this.normalize(data.name),
        this.normalize(lead.name)
      );
      score += similarity * 0.4;
    }

    // 2. تطابق المدينة
    if (lead.city && data.address) {
      if (this.normalize(data.address).includes(this.normalize(lead.city))) {
        score += 0.25;
      }
    }

    // 3. تطابق الهاتف
    if (lead.phone && data.phone) {
      const normalizedLeadPhone = lead.phone.replace(/\D/g, '').slice(-9);
      const normalizedDataPhone = data.phone.replace(/\D/g, '').slice(-9);
      if (normalizedLeadPhone === normalizedDataPhone) {
        score += 0.3;
      }
    }

    // 4. وجود موقع إلكتروني (إضافي)
    if (data.website) {
      score += 0.05;
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
      .trim();
  }

  /**
   * حساب التشابه
   */
  calculateSimilarity(str1, str2) {
    if (str1 === str2) return 1;
    if (!str1 || !str2) return 0;

    // تحقق من الاحتواء
    if (str1.includes(str2) || str2.includes(str1)) {
      return 0.8;
    }

    const len1 = str1.length;
    const len2 = str2.length;
    const matrix = [];

    for (let i = 0; i <= len1; i++) matrix[i] = [i];
    for (let j = 0; j <= len2; j++) matrix[0][j] = j;

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

    return 1 - matrix[len1][len2] / Math.max(len1, len2);
  }
}

export default MapsSearchLayer;
