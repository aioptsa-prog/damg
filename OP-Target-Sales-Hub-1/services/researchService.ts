/**
 * Research Service - خدمة البحث الموحدة متعددة الطبقات
 * 
 * تجمع كل طبقات البحث في مكان واحد:
 * - Layer 1: Google Search للموقع والسوشيال
 * - Layer 2: Website Deep Scan
 * - Layer 3: Google Maps
 * - Layer 4: Social Media Verification
 */

import { logger } from '../utils/logger';

export interface ResearchInput {
  companyName: string;
  city?: string;
  activity?: string;
  existingWebsite?: string;
  existingInstagram?: string;
  existingMaps?: string;
}

export interface DiscoveredLink {
  url: string;
  type: 'website' | 'twitter' | 'instagram' | 'linkedin' | 'facebook' | 'tiktok' | 'snapchat' | 'youtube' | 'maps';
  confidence: number;
  source: string;
}

export interface WebsiteData {
  url: string;
  title: string;
  description: string;
  phones: string[];
  emails: string[];
  whatsappLinks: string[];
  socialLinks: { platform: string; url: string }[];
  tracking: {
    googleAnalytics: boolean;
    googleTagManager: boolean;
    metaPixel: boolean;
    tiktokPixel: boolean;
    snapPixel: boolean;
  };
  forms: number;
  ctaButtons: string[];
  textExcerpt: string;
}

export interface MapsData {
  name: string;
  address: string;
  phone: string | null;
  website: string | null;
  rating: number | null;
  reviewCount: number | null;
  coordinates: { lat: number; lng: number } | null;
}

export interface ResearchResult {
  // المدخلات الأصلية
  input: ResearchInput;
  
  // الروابط المكتشفة
  discovered: {
    website: DiscoveredLink | null;
    socialMedia: DiscoveredLink[];
    maps: DiscoveredLink | null;
  };
  
  // البيانات المستخرجة
  extracted: {
    website: WebsiteData | null;
    maps: MapsData | null;
  };
  
  // ملخص
  summary: {
    totalConfidence: number;
    sourcesFound: string[];
    duration: number;
    errors: string[];
  };
}

class ResearchService {
  private forgeUrl: string;

  constructor() {
    this.forgeUrl = (typeof window !== 'undefined' && (window as any).__FORGE_URL__) 
      || process.env.VITE_FORGE_URL 
      || 'http://localhost:8081';
  }

  /**
   * البحث الشامل عن شركة
   */
  async research(input: ResearchInput): Promise<ResearchResult> {
    const startTime = Date.now();
    logger.debug('[ResearchService] Starting research for:', input.companyName);

    const result: ResearchResult = {
      input,
      discovered: {
        website: null,
        socialMedia: [],
        maps: null
      },
      extracted: {
        website: null,
        maps: null
      },
      summary: {
        totalConfidence: 0,
        sourcesFound: [],
        duration: 0,
        errors: []
      }
    };

    try {
      // المرحلة 1: البحث عبر Lead Enricher (Forge)
      const enricherResult = await this.callLeadEnricher(input);
      
      if (enricherResult) {
        // استخراج الموقع من Maps
        if (enricherResult.enriched?.maps) {
          const maps = enricherResult.enriched.maps;
          result.discovered.maps = {
            url: `https://maps.google.com/?q=${encodeURIComponent(maps.address || input.companyName)}`,
            type: 'maps',
            confidence: maps.confidence || 0.5,
            source: 'lead_enricher'
          };
          result.extracted.maps = {
            name: maps.name,
            address: maps.address,
            phone: maps.phone,
            website: maps.website,
            rating: maps.rating,
            reviewCount: maps.reviewCount,
            coordinates: maps.coordinates
          };
          result.summary.sourcesFound.push('google_maps');
        }

        // استخراج الموقع الإلكتروني
        if (enricherResult.enriched?.website) {
          result.discovered.website = {
            url: enricherResult.enriched.website.url,
            type: 'website',
            confidence: enricherResult.enriched.website.confidence || 0.5,
            source: 'lead_enricher'
          };
          result.summary.sourcesFound.push('website');
        }

        // استخراج السوشيال ميديا
        if (enricherResult.enriched?.socialMedia) {
          for (const [platform, data] of Object.entries(enricherResult.enriched.socialMedia)) {
            if (data && (data as any).url) {
              result.discovered.socialMedia.push({
                url: (data as any).url,
                type: platform as any,
                confidence: (data as any).confidence || 0.5,
                source: 'lead_enricher'
              });
              result.summary.sourcesFound.push(platform);
            }
          }
        }
      }

      // المرحلة 2: إذا وجدنا موقع، نجلب تفاصيله
      const websiteUrl = result.discovered.website?.url 
        || input.existingWebsite 
        || result.extracted.maps?.website;
      
      if (websiteUrl) {
        const websiteData = await this.fetchWebsiteDetails(websiteUrl);
        if (websiteData) {
          result.extracted.website = websiteData;
          if (!result.discovered.website) {
            result.discovered.website = {
              url: websiteUrl,
              type: 'website',
              confidence: 0.7,
              source: 'maps_or_input'
            };
          }
          if (!result.summary.sourcesFound.includes('website')) {
            result.summary.sourcesFound.push('website');
          }
        }
      }

      // حساب الثقة الإجمالية
      result.summary.totalConfidence = this.calculateTotalConfidence(result);

    } catch (error: any) {
      logger.error('[ResearchService] Error:', error);
      result.summary.errors.push(error.message);
    }

    result.summary.duration = Date.now() - startTime;
    logger.debug('[ResearchService] Completed in', result.summary.duration, 'ms');
    
    return result;
  }

  /**
   * استدعاء Lead Enricher من Forge
   */
  private async callLeadEnricher(input: ResearchInput): Promise<any> {
    try {
      logger.debug('[ResearchService] Calling Lead Enricher at:', this.forgeUrl);
      
      const response = await fetch(`${this.forgeUrl}/v1/api/leads/enrich.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          lead: {
            name: input.companyName,
            city: input.city,
            category: input.activity
          }
        })
      });

      if (!response.ok) {
        const errorText = await response.text();
        logger.error('[ResearchService] Lead Enricher error:', response.status, errorText);
        return null;
      }

      const data = await response.json();
      logger.debug('[ResearchService] Lead Enricher response:', data.ok ? 'success' : 'failed');
      return data.ok ? data : null;

    } catch (error: any) {
      logger.error('[ResearchService] Lead Enricher call failed:', error.message);
      return null;
    }
  }

  /**
   * جلب تفاصيل الموقع الإلكتروني
   */
  private async fetchWebsiteDetails(url: string): Promise<WebsiteData | null> {
    try {
      // استخدام API الموجود في reports.ts
      const response = await fetch('/api/reports?enrich=true', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({ website: url })
      });

      if (!response.ok) {
        return null;
      }

      const bundle = await response.json();
      const websiteSource = bundle.sources?.find((s: any) => s.type === 'website');
      
      if (!websiteSource?.parsed) {
        return null;
      }

      const parsed = websiteSource.parsed;
      return {
        url: websiteSource.finalUrl || url,
        title: parsed.title || '',
        description: parsed.metaDescription || '',
        phones: parsed.phones || [],
        emails: parsed.emails || [],
        whatsappLinks: parsed.whatsappLinks || [],
        socialLinks: parsed.socialLinks || [],
        tracking: parsed.tracking || {},
        forms: parsed.forms || 0,
        ctaButtons: parsed.ctaButtons || [],
        textExcerpt: parsed.textExcerpt || ''
      };

    } catch (error: any) {
      logger.error('[ResearchService] Website fetch failed:', error.message);
      return null;
    }
  }

  /**
   * حساب الثقة الإجمالية
   */
  private calculateTotalConfidence(result: ResearchResult): number {
    let score = 0;
    let weight = 0;

    // الموقع الإلكتروني (وزن 30%)
    if (result.discovered.website) {
      score += result.discovered.website.confidence * 0.3;
      weight += 0.3;
    }

    // Maps (وزن 35%)
    if (result.discovered.maps) {
      score += result.discovered.maps.confidence * 0.35;
      weight += 0.35;
    }

    // السوشيال ميديا (وزن 20%)
    if (result.discovered.socialMedia.length > 0) {
      const avgSocialConfidence = result.discovered.socialMedia.reduce((sum, s) => sum + s.confidence, 0) 
        / result.discovered.socialMedia.length;
      score += avgSocialConfidence * 0.2;
      weight += 0.2;
    }

    // بيانات الموقع المستخرجة (وزن 15%)
    if (result.extracted.website) {
      const hasUsefulData = result.extracted.website.phones.length > 0 
        || result.extracted.website.emails.length > 0;
      score += (hasUsefulData ? 0.8 : 0.4) * 0.15;
      weight += 0.15;
    }

    return weight > 0 ? Math.round((score / weight) * 100) / 100 : 0;
  }

  /**
   * تحويل النتائج لصيغة Evidence Bundle للـ AI
   */
  toEvidenceBundle(result: ResearchResult): any {
    return {
      sources: [
        ...(result.extracted.website ? [{
          type: 'website',
          url: result.discovered.website?.url,
          status: 'success',
          parseOk: true,
          parsed: result.extracted.website,
          keyFindings: [
            result.extracted.website.title && `عنوان: ${result.extracted.website.title}`,
            result.extracted.website.phones.length > 0 && `هاتف: ${result.extracted.website.phones.join(', ')}`,
            result.extracted.website.emails.length > 0 && `بريد: ${result.extracted.website.emails.join(', ')}`,
          ].filter(Boolean)
        }] : []),
        ...(result.extracted.maps ? [{
          type: 'google_maps',
          url: result.discovered.maps?.url,
          status: 'success',
          parseOk: true,
          keyFindings: [
            `الاسم: ${result.extracted.maps.name}`,
            `العنوان: ${result.extracted.maps.address}`,
            result.extracted.maps.phone && `الهاتف: ${result.extracted.maps.phone}`,
            result.extracted.maps.rating && `التقييم: ${result.extracted.maps.rating}`,
          ].filter(Boolean)
        }] : []),
        ...result.discovered.socialMedia.map(s => ({
          type: s.type,
          url: s.url,
          status: 'success',
          parseOk: true,
          keyFindings: [`حساب ${s.type}: ${s.url}`]
        }))
      ],
      extracted: {
        website: result.extracted.website,
        maps: result.extracted.maps
      },
      diagnostics: {
        totalDurationMs: result.summary.duration,
        errors: result.summary.errors,
        warnings: []
      },
      qualityScore: Math.round(result.summary.totalConfidence * 100),
      fetchedAt: new Date().toISOString()
    };
  }
}

export const researchService = new ResearchService();
