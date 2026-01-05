/**
 * Result Aggregator - تجميع النتائج وحساب الثقة الإجمالية
 */

export class Aggregator {
  constructor() {
    // أوزان كل طبقة في الثقة الإجمالية
    this.weights = {
      website: 0.25,
      socialMedia: 0.20,
      maps: 0.35,
      contact: 0.20
    };
  }

  /**
   * تجميع نتائج التواصل الاجتماعي حسب المنصة
   */
  groupSocialResults(results) {
    const grouped = {};

    for (const result of results) {
      const platform = result.platform;
      
      // أخذ أعلى نتيجة لكل منصة
      if (!grouped[platform] || result.confidence > grouped[platform].confidence) {
        grouped[platform] = {
          url: result.url,
          handle: result.handle,
          confidence: result.confidence
        };
      }
    }

    return grouped;
  }

  /**
   * إنشاء ملخص النتائج
   */
  summarize(results, duration) {
    const fieldsEnriched = [];
    let totalConfidence = 0;
    let weightSum = 0;

    // Website
    if (results.enriched.website) {
      fieldsEnriched.push('website');
      totalConfidence += results.enriched.website.confidence * this.weights.website;
      weightSum += this.weights.website;
    }

    // Social Media
    const socialPlatforms = Object.keys(results.enriched.socialMedia || {});
    if (socialPlatforms.length > 0) {
      fieldsEnriched.push(...socialPlatforms.map(p => `social:${p}`));
      
      // متوسط ثقة المنصات
      const avgSocialConfidence = socialPlatforms.reduce((sum, p) => 
        sum + results.enriched.socialMedia[p].confidence, 0
      ) / socialPlatforms.length;
      
      totalConfidence += avgSocialConfidence * this.weights.socialMedia;
      weightSum += this.weights.socialMedia;
    }

    // Maps
    if (results.enriched.maps) {
      fieldsEnriched.push('maps');
      totalConfidence += results.enriched.maps.confidence * this.weights.maps;
      weightSum += this.weights.maps;
    }

    // Contact
    if (results.enriched.contact) {
      fieldsEnriched.push('contact');
      totalConfidence += results.enriched.contact.confidence * this.weights.contact;
      weightSum += this.weights.contact;
    }

    // حساب الثقة الإجمالية
    const normalizedConfidence = weightSum > 0 ? totalConfidence / weightSum : 0;

    return {
      totalConfidence: Math.round(normalizedConfidence * 100) / 100,
      fieldsEnriched,
      searchesPerformed: results.searchesPerformed,
      duration,
      errors: results.errors.length,
      verdict: this.getOverallVerdict(normalizedConfidence, fieldsEnriched.length)
    };
  }

  /**
   * الحكم الإجمالي
   */
  getOverallVerdict(confidence, fieldsCount) {
    if (confidence >= 0.8 && fieldsCount >= 3) {
      return 'excellent';
    }
    if (confidence >= 0.6 && fieldsCount >= 2) {
      return 'good';
    }
    if (confidence >= 0.4 && fieldsCount >= 1) {
      return 'partial';
    }
    if (fieldsCount > 0) {
      return 'minimal';
    }
    return 'none';
  }

  /**
   * دمج نتائج من مصادر متعددة
   */
  mergeResults(existingData, newData) {
    const merged = { ...existingData };

    // Website - أخذ الأعلى ثقة
    if (newData.website) {
      if (!merged.website || newData.website.confidence > merged.website.confidence) {
        merged.website = newData.website;
      }
    }

    // Social Media - دمج المنصات
    if (newData.socialMedia) {
      merged.socialMedia = merged.socialMedia || {};
      for (const [platform, data] of Object.entries(newData.socialMedia)) {
        if (!merged.socialMedia[platform] || data.confidence > merged.socialMedia[platform].confidence) {
          merged.socialMedia[platform] = data;
        }
      }
    }

    // Maps - أخذ الأعلى ثقة
    if (newData.maps) {
      if (!merged.maps || newData.maps.confidence > merged.maps.confidence) {
        merged.maps = newData.maps;
      }
    }

    // Contact - دمج
    if (newData.contact) {
      merged.contact = merged.contact || { phones: [], emails: [] };
      if (newData.contact.phones) {
        merged.contact.phones = [...new Set([...merged.contact.phones, ...newData.contact.phones])];
      }
      if (newData.contact.emails) {
        merged.contact.emails = [...new Set([...merged.contact.emails, ...newData.contact.emails])];
      }
    }

    return merged;
  }

  /**
   * تحويل النتائج لصيغة API
   */
  toApiResponse(results) {
    return {
      ok: true,
      lead: results.original,
      enriched: {
        website: results.enriched.website ? {
          url: results.enriched.website.url,
          confidence: results.enriched.website.confidence
        } : null,
        
        socialMedia: Object.fromEntries(
          Object.entries(results.enriched.socialMedia || {}).map(([platform, data]) => [
            platform,
            { url: data.url, handle: data.handle, confidence: data.confidence }
          ])
        ),
        
        maps: results.enriched.maps ? {
          name: results.enriched.maps.name,
          address: results.enriched.maps.address,
          phone: results.enriched.maps.phone,
          website: results.enriched.maps.website,
          rating: results.enriched.maps.rating,
          reviewCount: results.enriched.maps.reviewCount,
          coordinates: results.enriched.maps.coordinates,
          confidence: results.enriched.maps.confidence
        } : null
      },
      summary: results.summary,
      errors: results.errors.length > 0 ? results.errors : undefined
    };
  }
}

export default Aggregator;
