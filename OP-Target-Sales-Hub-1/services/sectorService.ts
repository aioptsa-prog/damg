
import { SECTOR_TEMPLATES } from "../constants";
// Fix: Import SectorSlug from types instead of constants to resolve the "locally declared but not exported" error
import { SectorSlug } from "../types";

export class SectorService {
  /**
   * يحلل النص المدخل (اسم الشركة + النشاط) ويستخرج القطاع الأكثر احتمالاً
   */
  detectSector(text: string): { slug: SectorSlug; confidence: number; signals: string[] } {
    const normalized = text.toLowerCase();
    let bestMatch: SectorSlug = 'other';
    let maxSignals = 0;
    let matchedSignals: string[] = [];

    for (const template of SECTOR_TEMPLATES) {
      const signals = template.triggers.filter(trigger => 
        normalized.includes(trigger.toLowerCase())
      );

      if (signals.length > maxSignals) {
        maxSignals = signals.length;
        bestMatch = template.slug;
        matchedSignals = signals;
      }
    }

    const confidence = maxSignals === 0 ? 10 : Math.min(maxSignals * 25, 95);

    return {
      slug: bestMatch,
      confidence,
      signals: matchedSignals
    };
  }

  getTemplate(slug: SectorSlug) {
    return SECTOR_TEMPLATES.find(s => s.slug === slug) || SECTOR_TEMPLATES.find(s => s.slug === 'other');
  }
}

export const sectorService = new SectorService();
