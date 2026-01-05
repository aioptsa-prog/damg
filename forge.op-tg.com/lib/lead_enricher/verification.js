/**
 * Verification Layer - طبقة التحقق والتمييز
 * 
 * تتحقق من صحة النتائج وتميز بين الأسماء المتشابهة
 */

export class Verifier {
  constructor() {
    // عتبات الثقة
    this.thresholds = {
      high: 0.8,
      medium: 0.5,
      low: 0.3
    };
  }

  /**
   * اختيار أفضل نتيجة من القائمة
   */
  pickBest(results, lead) {
    if (!results || results.length === 0) return null;

    // ترتيب حسب الثقة
    const sorted = [...results].sort((a, b) => b.confidence - a.confidence);
    
    // أخذ أعلى نتيجة إذا تجاوزت العتبة
    const best = sorted[0];
    if (best.confidence >= this.thresholds.low) {
      return {
        ...best,
        alternatives: sorted.slice(1, 3).filter(r => r.confidence >= this.thresholds.low)
      };
    }

    return null;
  }

  /**
   * التحقق من تطابق النتيجة مع العميل
   */
  verify(result, lead) {
    const matchedFields = [];
    const conflicts = [];

    // 1. مقارنة الأسماء
    if (result.name && lead.name) {
      const nameSimilarity = this.calculateSimilarity(
        this.normalize(result.name),
        this.normalize(lead.name)
      );
      
      if (nameSimilarity > 0.7) {
        matchedFields.push({ field: 'name', similarity: nameSimilarity });
      } else if (nameSimilarity < 0.3) {
        conflicts.push({ field: 'name', reason: 'low_similarity', similarity: nameSimilarity });
      }
    }

    // 2. مقارنة المدينة
    if (result.city && lead.city) {
      const cityMatch = this.normalize(result.city).includes(this.normalize(lead.city)) ||
                        this.normalize(lead.city).includes(this.normalize(result.city));
      
      if (cityMatch) {
        matchedFields.push({ field: 'city', match: true });
      } else {
        conflicts.push({ field: 'city', reason: 'mismatch' });
      }
    }

    // 3. مقارنة الهاتف
    if (result.phone && lead.phone) {
      const normalizedResultPhone = result.phone.replace(/\D/g, '').slice(-9);
      const normalizedLeadPhone = lead.phone.replace(/\D/g, '').slice(-9);
      
      if (normalizedResultPhone === normalizedLeadPhone) {
        matchedFields.push({ field: 'phone', match: true });
      } else {
        conflicts.push({ field: 'phone', reason: 'mismatch' });
      }
    }

    // حساب الثقة النهائية
    const confidence = this.calculateVerificationConfidence(matchedFields, conflicts);

    return {
      isMatch: confidence >= this.thresholds.medium && conflicts.length === 0,
      confidence,
      matchedFields,
      conflicts,
      verdict: this.getVerdict(confidence, conflicts)
    };
  }

  /**
   * حساب ثقة التحقق
   */
  calculateVerificationConfidence(matchedFields, conflicts) {
    let score = 0;
    
    // نقاط للحقول المتطابقة
    for (const match of matchedFields) {
      if (match.field === 'name') score += 0.4 * (match.similarity || 1);
      if (match.field === 'city') score += 0.3;
      if (match.field === 'phone') score += 0.3;
    }

    // خصم للتعارضات
    for (const conflict of conflicts) {
      if (conflict.field === 'name') score -= 0.3;
      if (conflict.field === 'city') score -= 0.2;
      if (conflict.field === 'phone') score -= 0.2;
    }

    return Math.max(0, Math.min(1, score));
  }

  /**
   * الحكم النهائي
   */
  getVerdict(confidence, conflicts) {
    if (conflicts.length > 0) {
      return 'needs_review';
    }
    if (confidence >= this.thresholds.high) {
      return 'confirmed';
    }
    if (confidence >= this.thresholds.medium) {
      return 'likely';
    }
    if (confidence >= this.thresholds.low) {
      return 'possible';
    }
    return 'unlikely';
  }

  /**
   * إزالة المكررات
   */
  deduplicate(results) {
    const seen = new Map();

    for (const result of results) {
      const key = this.getDeduplicationKey(result);
      const existing = seen.get(key);

      if (!existing || result.confidence > existing.confidence) {
        seen.set(key, result);
      }
    }

    return Array.from(seen.values());
  }

  /**
   * مفتاح إزالة التكرار
   */
  getDeduplicationKey(result) {
    if (result.url) {
      try {
        const url = new URL(result.url);
        return url.hostname + url.pathname.replace(/\/$/, '');
      } catch {
        return result.url;
      }
    }
    if (result.handle) {
      return `${result.platform}:${result.handle.toLowerCase()}`;
    }
    return this.normalize(result.name || '');
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
      .replace(/\s+/g, ' ')
      .trim();
  }

  /**
   * حساب التشابه (Levenshtein)
   */
  calculateSimilarity(str1, str2) {
    if (str1 === str2) return 1;
    if (!str1 || !str2) return 0;

    // تحقق سريع للاحتواء
    if (str1.includes(str2) || str2.includes(str1)) {
      const ratio = Math.min(str1.length, str2.length) / Math.max(str1.length, str2.length);
      return 0.7 + (ratio * 0.3);
    }

    const len1 = str1.length;
    const len2 = str2.length;
    
    if (len1 === 0) return 0;
    if (len2 === 0) return 0;

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

export default Verifier;
