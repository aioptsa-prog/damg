/**
 * Enrichment Service (v4.0 - Evidence-based Research Engine)
 * 
 * This service calls the server-side /api/enrich endpoint which performs
 * ACTUAL website fetching and parsing to extract real evidence.
 * 
 * No more simulated data - everything is fetched from real sources.
 */

import { EvidencePack } from '../types';
import { logger } from '../utils/logger';

// Evidence Bundle from API
export interface EvidenceBundle {
  sources: Array<{
    type: 'website' | 'instagram' | 'google_maps';
    url: string;
    fetchedAt: string;
    status: 'success' | 'blocked' | 'error' | 'timeout';
    statusCode?: number;
    finalUrl?: string;
    bytes?: number;
    parseOk: boolean;
    notes: string;
    keyFindings: string[];
    rawExcerpt?: string;
    parsed?: {
      title: string;
      metaDescription: string;
      h1: string[];
      h2: string[];
      phones: string[];
      emails: string[];
      whatsappLinks: string[];
      socialLinks: Array<{ platform: string; url: string }>;
      forms: number;
      ctaButtons: string[];
      tracking: {
        googleAnalytics: boolean;
        googleTagManager: boolean;
        metaPixel: boolean;
        tiktokPixel: boolean;
        snapPixel: boolean;
        otherTracking: string[];
      };
      textExcerpt: string;
    };
  }>;
  extracted: {
    website: any;
    social: any;
  };
  diagnostics: {
    totalDurationMs: number;
    errors: string[];
    warnings: string[];
  };
  qualityScore: number;
  fetchedAt: string;
}

export const enrichmentService = {
  /**
   * Enrich a lead by fetching real data from website/social sources
   * This calls the server-side API to bypass CORS restrictions
   */
  async enrichLead(website?: string, instagram?: string, maps?: string): Promise<EvidencePack> {
    logger.debug('[Enrichment v4.0] Starting REAL evidence collection...', { website, instagram, maps });
    
    try {
      // Call server-side enrichment API (merged into reports endpoint)
      const response = await fetch('/api/reports?enrich=true', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({ website, instagram, maps }),
      });

      logger.debug('[Enrichment v4.0] API Response status:', response.status);

      if (!response.ok) {
        const errorData = await response.json();
        console.error('[Enrichment v4.0] API Error:', errorData);
        throw new Error(errorData.message || `API Error: ${response.statusText}`);
      }

      const bundle: EvidenceBundle = await response.json();
      logger.debug(`[Enrichment v4.0] Completed in ${bundle.diagnostics.totalDurationMs}ms, quality: ${bundle.qualityScore}%`);
      logger.debug('[Enrichment v4.0] Bundle sources:', bundle.sources?.length || 0);

      // Convert EvidenceBundle to EvidencePack format for compatibility
      return this.convertBundleToLegacy(bundle, website, instagram, maps);
    } catch (error: any) {
      console.error('[Enrichment v4.0] Error:', error);
      
      // Return minimal evidence with error info
      return {
        sources_used: [],
        key_findings: [{
          finding: `فشل جمع الأدلة: ${error.message}`,
          confidence: 'low'
        }],
        contacts_found: {},
        tech_hints: [],
        fetch_status: [
          { source: 'enrichment_api', status: 'error', error: error.message }
        ],
        pages: [],
        _raw_bundle: null,
        _diagnostics: { error: error.message }
      };
    }
  },

  /**
   * Convert new EvidenceBundle format to legacy EvidencePack for backward compatibility
   */
  convertBundleToLegacy(bundle: EvidenceBundle, website?: string, instagram?: string, maps?: string): EvidencePack {
    const evidence: EvidencePack = {
      sources_used: [],
      key_findings: [],
      contacts_found: {},
      tech_hints: [],
      fetch_status: [],
      pages: [],
      // Store raw bundle for AI to use
      _raw_bundle: bundle,
      _diagnostics: bundle.diagnostics
    };

    // Process each source
    for (const source of bundle.sources) {
      evidence.sources_used.push(source.type);
      evidence.fetch_status.push({
        source: source.type,
        status: source.status,
        statusCode: source.statusCode,
        notes: source.notes
      });

      // Add key findings
      for (const finding of source.keyFindings) {
        evidence.key_findings.push({
          finding,
          evidence_url: source.url,
          confidence: source.status === 'success' ? 'high' : 'medium'
        });
      }

      // Extract website-specific data
      if (source.type === 'website' && source.parsed) {
        const parsed = source.parsed;
        
        // Contacts
        if (parsed.phones.length > 0) {
          evidence.contacts_found.phone = parsed.phones[0];
        }
        if (parsed.emails.length > 0) {
          evidence.contacts_found.email = parsed.emails[0];
        }
        if (parsed.whatsappLinks.length > 0) {
          evidence.contacts_found.whatsapp = parsed.whatsappLinks[0];
        }

        // Tech hints from tracking
        const techHints: string[] = [];
        if (parsed.tracking.googleAnalytics) techHints.push('Google Analytics');
        if (parsed.tracking.googleTagManager) techHints.push('Google Tag Manager');
        if (parsed.tracking.metaPixel) techHints.push('Meta Pixel');
        if (parsed.tracking.tiktokPixel) techHints.push('TikTok Pixel');
        if (parsed.tracking.snapPixel) techHints.push('Snapchat Pixel');
        techHints.push(...parsed.tracking.otherTracking);
        evidence.tech_hints = techHints;

        // Pages data
        evidence.pages = [{
          url: source.finalUrl || source.url,
          title: parsed.title,
          description: parsed.metaDescription,
          h1: parsed.h1.join(' | '),
          text_snippet: parsed.textExcerpt
        }];

        // Add tracking status to findings
        if (techHints.length === 0) {
          evidence.key_findings.push({
            finding: 'لا توجد أدوات تتبع مثبتة (GA/GTM/Pixel) - فرصة لتحسين قياس الأداء',
            evidence_url: source.url,
            confidence: 'high'
          });
        }

        // Add CTA analysis
        if (parsed.forms === 0 && parsed.whatsappLinks.length === 0) {
          evidence.key_findings.push({
            finding: 'لا توجد نماذج اتصال أو روابط واتساب واضحة - فرصة لتحسين التحويل',
            evidence_url: source.url,
            confidence: 'high'
          });
        }
      }
    }

    // Add diagnostics warnings as findings
    for (const warning of bundle.diagnostics.warnings) {
      if (warning.includes('TRACKING_NOT_FOUND')) {
        // Already handled above
      } else if (warning.includes('INSTAGRAM_BLOCKED')) {
        evidence.key_findings.push({
          finding: 'تعذر الوصول لبيانات Instagram - يُنصح بتكامل Meta Graph API للحصول على تحليلات دقيقة',
          confidence: 'medium'
        });
      } else if (warning.includes('MAPS_API_REQUIRED')) {
        evidence.key_findings.push({
          finding: 'بيانات خرائط جوجل تتطلب تكامل Google Places API',
          confidence: 'medium'
        });
      }
    }

    // If no sources succeeded, add clear message
    if (evidence.sources_used.length === 0 || bundle.qualityScore < 20) {
      evidence.key_findings.push({
        finding: 'لم يتم جمع أدلة كافية - يرجى التحقق من صحة الروابط المدخلة',
        confidence: 'low'
      });
    }

    return evidence;
  },

  /**
   * Get the raw evidence bundle for AI processing
   */
  getRawBundle(evidence: EvidencePack): EvidenceBundle | null {
    return (evidence as any)._raw_bundle || null;
  }
};
