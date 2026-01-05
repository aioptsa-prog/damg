/**
 * Unit Tests for normalizeReport
 * Principal Engineer Quality Gate: Prevent .map crashes on non-arrays
 */

import { describe, it, expect } from 'vitest';
import { normalizeReport, NormalizedReportModel } from '../domain/normalizeReport';

describe('normalizeReport', () => {
  describe('handles null/undefined input', () => {
    it('should return defaults for null input', () => {
      const result = normalizeReport(null);
      expect(result).toBeDefined();
      expect(result.sector.primary).toBe('other');
      expect(result.sector.confidence).toBe(0);
      expect(Array.isArray(result.recommended_services)).toBe(true);
      expect(Array.isArray(result.follow_up_plan)).toBe(true);
    });

    it('should return defaults for undefined input', () => {
      const result = normalizeReport(undefined);
      expect(result).toBeDefined();
      expect(Array.isArray(result.evidence_summary.key_findings)).toBe(true);
    });

    it('should return defaults for empty object', () => {
      const result = normalizeReport({});
      expect(result.snapshot.summary).toBe('غير متوفر');
      expect(result.snapshot.market_fit).toBe('غير متوفر');
    });
  });

  describe('sector normalization', () => {
    it('should handle sector as string', () => {
      const result = normalizeReport({ sector: 'retail' });
      expect(result.sector.primary).toBe('retail');
      expect(result.sector.confidence).toBe(0);
      expect(Array.isArray(result.sector.matched_signals)).toBe(true);
    });

    it('should handle sector as object without confidence', () => {
      const result = normalizeReport({ sector: { primary: 'tech' } });
      expect(result.sector.primary).toBe('tech');
      expect(result.sector.confidence).toBe(0);
    });

    it('should handle sector with all fields', () => {
      const result = normalizeReport({
        sector: { primary: 'finance', confidence: 85, matched_signals: ['bank', 'loan'] }
      });
      expect(result.sector.primary).toBe('finance');
      expect(result.sector.confidence).toBe(85);
      expect(result.sector.matched_signals).toEqual(['bank', 'loan']);
    });
  });

  describe('recommended_services normalization', () => {
    it('should handle recommended_services as null', () => {
      const result = normalizeReport({ recommended_services: null });
      expect(Array.isArray(result.recommended_services)).toBe(true);
      expect(result.recommended_services.length).toBe(0);
    });

    it('should handle recommended_services as single object (not array)', () => {
      const result = normalizeReport({
        recommended_services: { service: 'SEO', tier: 'tier1', why: 'test' }
      });
      expect(Array.isArray(result.recommended_services)).toBe(true);
      expect(result.recommended_services.length).toBe(1);
      expect(result.recommended_services[0].service).toBe('SEO');
    });

    it('should handle recommended_services as array', () => {
      const result = normalizeReport({
        recommended_services: [
          { service: 'SEO', tier: 'tier1' },
          { service: 'PPC', tier: 'tier2' }
        ]
      });
      expect(result.recommended_services.length).toBe(2);
    });
  });

  describe('package_suggestion.scope normalization - CRITICAL', () => {
    it('should convert scope string to array (fixes .map crash)', () => {
      const result = normalizeReport({
        recommended_services: [{
          service: 'SEO',
          package_suggestion: {
            package_name: 'Basic',
            price_range: '1000-2000',
            scope: 'Line 1\nLine 2\nLine 3'
          }
        }]
      });
      
      const scope = result.recommended_services[0].package_suggestion?.scope;
      expect(Array.isArray(scope)).toBe(true);
      expect(scope?.length).toBe(3);
      expect(scope?.[0]).toBe('Line 1');
    });

    it('should keep scope as array if already array', () => {
      const result = normalizeReport({
        recommended_services: [{
          service: 'SEO',
          package_suggestion: {
            scope: ['Item 1', 'Item 2']
          }
        }]
      });
      
      const scope = result.recommended_services[0].package_suggestion?.scope;
      expect(Array.isArray(scope)).toBe(true);
      expect(scope).toEqual(['Item 1', 'Item 2']);
    });

    it('should handle null scope', () => {
      const result = normalizeReport({
        recommended_services: [{
          service: 'SEO',
          package_suggestion: {
            scope: null
          }
        }]
      });
      
      const scope = result.recommended_services[0].package_suggestion?.scope;
      expect(Array.isArray(scope)).toBe(true);
      expect(scope?.length).toBe(0);
    });

    it('should handle undefined scope', () => {
      const result = normalizeReport({
        recommended_services: [{
          service: 'SEO',
          package_suggestion: {}
        }]
      });
      
      const scope = result.recommended_services[0].package_suggestion?.scope;
      expect(Array.isArray(scope)).toBe(true);
    });
  });

  describe('follow_up_plan normalization', () => {
    it('should handle follow_up_plan as null', () => {
      const result = normalizeReport({ follow_up_plan: null });
      expect(Array.isArray(result.follow_up_plan)).toBe(true);
      expect(result.follow_up_plan.length).toBe(0);
    });

    it('should handle follow_up_plan as string', () => {
      const result = normalizeReport({ follow_up_plan: 'Day 1: Call\nDay 2: Email' });
      expect(Array.isArray(result.follow_up_plan)).toBe(true);
    });

    it('should handle follow_up_plan as array', () => {
      const result = normalizeReport({
        follow_up_plan: [
          { day: 1, channel: 'phone', goal: 'intro', action: 'call' },
          { day: 3, channel: 'email', goal: 'follow', action: 'send' }
        ]
      });
      expect(result.follow_up_plan.length).toBe(2);
      expect(result.follow_up_plan[0].day).toBe(1);
    });
  });

  describe('evidence_summary normalization', () => {
    it('should handle missing key_findings', () => {
      const result = normalizeReport({ evidence_summary: {} });
      expect(Array.isArray(result.evidence_summary.key_findings)).toBe(true);
    });

    it('should handle missing tech_hints', () => {
      const result = normalizeReport({ evidence_summary: {} });
      expect(Array.isArray(result.evidence_summary.tech_hints)).toBe(true);
    });

    it('should handle missing contacts_found', () => {
      const result = normalizeReport({ evidence_summary: {} });
      expect(result.evidence_summary.contacts_found).toBeDefined();
      expect(result.evidence_summary.contacts_found.phone).toBe('');
    });
  });

  describe('talk_track normalization', () => {
    it('should handle missing objection_handlers', () => {
      const result = normalizeReport({ talk_track: {} });
      expect(Array.isArray(result.talk_track.objection_handlers)).toBe(true);
    });

    it('should handle missing whatsapp_messages', () => {
      const result = normalizeReport({ talk_track: {} });
      expect(Array.isArray(result.talk_track.whatsapp_messages)).toBe(true);
    });

    it('should handle objection_handlers as single object', () => {
      const result = normalizeReport({
        talk_track: {
          objection_handlers: { objection: 'too expensive', answer: 'value proposition' }
        }
      });
      expect(Array.isArray(result.talk_track.objection_handlers)).toBe(true);
      expect(result.talk_track.objection_handlers.length).toBe(1);
    });
  });

  describe('website_audit normalization', () => {
    it('should handle missing issues', () => {
      const result = normalizeReport({ website_audit: {} });
      expect(Array.isArray(result.website_audit.issues)).toBe(true);
    });

    it('should provide default cta_quality', () => {
      const result = normalizeReport({ website_audit: {} });
      expect(result.website_audit.cta_quality).toBe('غير متوفر');
    });
  });

  describe('social_audit normalization', () => {
    it('should handle missing presence', () => {
      const result = normalizeReport({ social_audit: {} });
      expect(Array.isArray(result.social_audit.presence)).toBe(true);
    });

    it('should handle missing content_gaps', () => {
      const result = normalizeReport({ social_audit: {} });
      expect(Array.isArray(result.social_audit.content_gaps)).toBe(true);
    });

    it('should handle missing quick_content_ideas', () => {
      const result = normalizeReport({ social_audit: {} });
      expect(Array.isArray(result.social_audit.quick_content_ideas)).toBe(true);
    });
  });

  describe('all array fields are guaranteed arrays', () => {
    it('should guarantee ALL array fields are arrays regardless of input', () => {
      // Worst case: all fields are wrong types
      const badInput = {
        sector: { matched_signals: 'not an array' },
        evidence_summary: {
          key_findings: 'string',
          tech_hints: null,
          contacts_found: 'invalid'
        },
        website_audit: { issues: {} },
        social_audit: {
          presence: undefined,
          content_gaps: 123,
          quick_content_ideas: false
        },
        pain_points: 'string',
        recommended_services: { single: 'object' },
        talk_track: {
          objection_handlers: null,
          whatsapp_messages: 'text'
        },
        follow_up_plan: true
      };

      const result = normalizeReport(badInput);

      // ALL these MUST be arrays - this is the contract
      expect(Array.isArray(result.sector.matched_signals)).toBe(true);
      expect(Array.isArray(result.evidence_summary.key_findings)).toBe(true);
      expect(Array.isArray(result.evidence_summary.tech_hints)).toBe(true);
      expect(Array.isArray(result.website_audit.issues)).toBe(true);
      expect(Array.isArray(result.social_audit.presence)).toBe(true);
      expect(Array.isArray(result.social_audit.content_gaps)).toBe(true);
      expect(Array.isArray(result.social_audit.quick_content_ideas)).toBe(true);
      expect(Array.isArray(result.pain_points)).toBe(true);
      expect(Array.isArray(result.recommended_services)).toBe(true);
      expect(Array.isArray(result.talk_track.objection_handlers)).toBe(true);
      expect(Array.isArray(result.talk_track.whatsapp_messages)).toBe(true);
      expect(Array.isArray(result.follow_up_plan)).toBe(true);
    });
  });
});
