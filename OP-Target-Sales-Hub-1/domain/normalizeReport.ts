/**
 * Report Normalization Layer
 * Principal Engineer Quality Gate: ALL data MUST pass through this layer before UI rendering
 * 
 * This ensures:
 * 1. All array fields are guaranteed to be arrays (never null/undefined/object)
 * 2. All string fields have safe defaults
 * 3. All nested objects exist with proper structure
 * 4. Zero runtime crashes from .map() on non-arrays
 */

import { z } from 'zod';

// ============================================
// Normalized Report Model Types
// ============================================

export interface NormalizedSector {
  primary: string;
  confidence: number;
  matched_signals: string[];
}

export interface NormalizedKeyFinding {
  finding: string;
  evidence_url: string;
  confidence: 'low' | 'medium' | 'high';
}

export interface NormalizedContactsFound {
  phone: string;
  whatsapp: string;
  email: string;
}

export interface NormalizedEvidenceSummary {
  key_findings: NormalizedKeyFinding[];
  tech_hints: string[];
  contacts_found: NormalizedContactsFound;
}

export interface NormalizedWebsiteIssue {
  issue: string;
  impact: string;
  quick_fix: string;
}

export interface NormalizedWebsiteAudit {
  issues: NormalizedWebsiteIssue[];
  cta_quality: string;
  tracking_gap: string;
}

export interface NormalizedSocialPresence {
  platform: string;
  status: 'ok' | 'missing' | 'inactive';
}

export interface NormalizedSocialAudit {
  presence: NormalizedSocialPresence[];
  content_gaps: string[];
  quick_content_ideas: string[];
}

export interface NormalizedSnapshot {
  summary: string;
  market_fit: string;
}

export interface NormalizedPackageSuggestion {
  package_name: string;
  price_range: string;
  scope: string[];  // ALWAYS array, never string
}

export interface NormalizedRecommendedService {
  service: string;
  tier: 'tier1' | 'tier2' | 'tier3';
  why: string;
  confidence: number;
  package_suggestion: NormalizedPackageSuggestion | null;
}

export interface NormalizedObjectionHandler {
  objection: string;
  answer: string;
}

export interface NormalizedWhatsAppMessage {
  text: string;
  timing: string;
}

export interface NormalizedTalkTrack {
  opening: string;
  pitch: string;
  objection_handlers: NormalizedObjectionHandler[];
  whatsapp_messages: NormalizedWhatsAppMessage[];
}

export interface NormalizedFollowUpStep {
  day: number;
  channel: string;
  goal: string;
  action: string;
}

export interface NormalizedPainPoint {
  pain: string;
  solution: string;
  priority: 'high' | 'medium' | 'low';
}

// Main Normalized Report Model
export interface NormalizedReportModel {
  sector: NormalizedSector;
  snapshot: NormalizedSnapshot;
  evidence_summary: NormalizedEvidenceSummary;
  website_audit: NormalizedWebsiteAudit;
  social_audit: NormalizedSocialAudit;
  pain_points: NormalizedPainPoint[];
  recommended_services: NormalizedRecommendedService[];
  talk_track: NormalizedTalkTrack;
  follow_up_plan: NormalizedFollowUpStep[];
}

// ============================================
// Zod Schemas for Validation
// ============================================

const KeyFindingSchema = z.object({
  finding: z.string().default(''),
  evidence_url: z.string().default(''),
  confidence: z.enum(['low', 'medium', 'high']).default('low'),
});

const ContactsFoundSchema = z.object({
  phone: z.string().default(''),
  whatsapp: z.string().default(''),
  email: z.string().default(''),
});

const EvidenceSummarySchema = z.object({
  key_findings: z.array(KeyFindingSchema).default([]),
  tech_hints: z.array(z.string()).default([]),
  contacts_found: ContactsFoundSchema.default({ phone: '', whatsapp: '', email: '' }),
});

const WebsiteIssueSchema = z.object({
  issue: z.string().default(''),
  impact: z.string().default(''),
  quick_fix: z.string().default(''),
});

const WebsiteAuditSchema = z.object({
  issues: z.array(WebsiteIssueSchema).default([]),
  cta_quality: z.string().default('غير متوفر'),
  tracking_gap: z.string().default('غير متوفر'),
});

const SocialPresenceSchema = z.object({
  platform: z.string().default(''),
  status: z.enum(['ok', 'missing', 'inactive']).default('missing'),
});

const SocialAuditSchema = z.object({
  presence: z.array(SocialPresenceSchema).default([]),
  content_gaps: z.array(z.string()).default([]),
  quick_content_ideas: z.array(z.string()).default([]),
});

const SnapshotSchema = z.object({
  summary: z.string().default('غير متوفر'),
  market_fit: z.string().default('غير متوفر'),
});

const SectorSchema = z.object({
  primary: z.string().default('other'),
  confidence: z.number().default(0),
  matched_signals: z.array(z.string()).default([]),
});

const PackageSuggestionSchema = z.object({
  package_name: z.string().default(''),
  price_range: z.string().default(''),
  scope: z.array(z.string()).default([]),  // ALWAYS array
});

const RecommendedServiceSchema = z.object({
  service: z.string().default(''),
  tier: z.enum(['tier1', 'tier2', 'tier3']).default('tier1'),
  why: z.string().default(''),
  confidence: z.number().default(0),
  package_suggestion: PackageSuggestionSchema.nullable().default(null),
});

const ObjectionHandlerSchema = z.object({
  objection: z.string().default(''),
  answer: z.string().default(''),
});

const WhatsAppMessageSchema = z.object({
  text: z.string().default(''),
  timing: z.string().default(''),
});

const TalkTrackSchema = z.object({
  opening: z.string().default(''),
  pitch: z.string().default(''),
  objection_handlers: z.array(ObjectionHandlerSchema).default([]),
  whatsapp_messages: z.array(WhatsAppMessageSchema).default([]),
});

const FollowUpStepSchema = z.object({
  day: z.number().default(1),
  channel: z.string().default(''),
  goal: z.string().default(''),
  action: z.string().default(''),
});

const PainPointSchema = z.object({
  pain: z.string().default(''),
  solution: z.string().default(''),
  priority: z.enum(['high', 'medium', 'low']).default('medium'),
});

const NormalizedReportSchema = z.object({
  sector: SectorSchema.default({ primary: 'other', confidence: 0, matched_signals: [] }),
  snapshot: SnapshotSchema.default({ summary: 'غير متوفر', market_fit: 'غير متوفر' }),
  evidence_summary: EvidenceSummarySchema.default({ key_findings: [], tech_hints: [], contacts_found: { phone: '', whatsapp: '', email: '' } }),
  website_audit: WebsiteAuditSchema.default({ issues: [], cta_quality: 'غير متوفر', tracking_gap: 'غير متوفر' }),
  social_audit: SocialAuditSchema.default({ presence: [], content_gaps: [], quick_content_ideas: [] }),
  pain_points: z.array(PainPointSchema).default([]),
  recommended_services: z.array(RecommendedServiceSchema).default([]),
  talk_track: TalkTrackSchema.default({ opening: '', pitch: '', objection_handlers: [], whatsapp_messages: [] }),
  follow_up_plan: z.array(FollowUpStepSchema).default([]),
});

// ============================================
// Normalization Functions
// ============================================

/**
 * Safely convert any value to an array
 */
function toArray<T>(value: unknown, itemTransform?: (item: unknown) => T): T[] {
  if (Array.isArray(value)) {
    return itemTransform ? value.map(itemTransform) : value as T[];
  }
  if (value === null || value === undefined) {
    return [];
  }
  if (typeof value === 'object') {
    // Single object → wrap in array
    return itemTransform ? [itemTransform(value)] : [value as T];
  }
  if (typeof value === 'string' && value.trim()) {
    // String → split by newlines or wrap
    const lines = value.split('\n').filter(line => line.trim());
    return itemTransform ? lines.map(itemTransform) : lines as T[];
  }
  return [];
}

/**
 * Safely get string value
 */
function toString(value: unknown, defaultValue = ''): string {
  if (typeof value === 'string') return value;
  if (value === null || value === undefined) return defaultValue;
  if (typeof value === 'number') return String(value);
  return defaultValue;
}

/**
 * Safely get number value
 */
function toNumber(value: unknown, defaultValue = 0): number {
  if (typeof value === 'number' && !isNaN(value)) return value;
  if (typeof value === 'string') {
    const parsed = parseFloat(value);
    return isNaN(parsed) ? defaultValue : parsed;
  }
  return defaultValue;
}

/**
 * Normalize package_suggestion.scope - CRITICAL FIX for .map crash
 * scope can come as: string, array, null, undefined
 * Must ALWAYS return string[]
 */
function normalizeScope(scope: unknown): string[] {
  if (Array.isArray(scope)) {
    return scope.map(item => toString(item)).filter(Boolean);
  }
  if (typeof scope === 'string' && scope.trim()) {
    return scope.split('\n').map(line => line.trim()).filter(Boolean);
  }
  return [];
}

/**
 * Normalize a single recommended service
 */
function normalizeRecommendedService(raw: unknown): NormalizedRecommendedService {
  if (!raw || typeof raw !== 'object') {
    return {
      service: '',
      tier: 'tier1',
      why: '',
      confidence: 0,
      package_suggestion: null,
    };
  }

  const obj = raw as Record<string, unknown>;
  
  let packageSuggestion: NormalizedPackageSuggestion | null = null;
  if (obj.package_suggestion && typeof obj.package_suggestion === 'object') {
    const pkg = obj.package_suggestion as Record<string, unknown>;
    packageSuggestion = {
      package_name: toString(pkg.package_name),
      price_range: toString(pkg.price_range),
      scope: normalizeScope(pkg.scope),  // CRITICAL: Always array
    };
  }

  return {
    service: toString(obj.service),
    tier: (['tier1', 'tier2', 'tier3'].includes(toString(obj.tier)) ? toString(obj.tier) : 'tier1') as 'tier1' | 'tier2' | 'tier3',
    why: toString(obj.why),
    confidence: toNumber(obj.confidence),
    package_suggestion: packageSuggestion,
  };
}

/**
 * Normalize sector - can come as string or object
 */
function normalizeSector(raw: unknown): NormalizedSector {
  if (typeof raw === 'string') {
    return { primary: raw, confidence: 0, matched_signals: [] };
  }
  if (raw && typeof raw === 'object') {
    const obj = raw as Record<string, unknown>;
    return {
      primary: toString(obj.primary, 'other'),
      confidence: toNumber(obj.confidence),
      matched_signals: toArray<string>(obj.matched_signals),
    };
  }
  return { primary: 'other', confidence: 0, matched_signals: [] };
}

/**
 * Main normalization function - THE SINGLE SOURCE OF TRUTH
 * All report data MUST pass through this before reaching UI
 */
export function normalizeReport(raw: unknown): NormalizedReportModel {
  // Handle null/undefined
  if (!raw || typeof raw !== 'object') {
    return NormalizedReportSchema.parse({});
  }

  const data = raw as Record<string, unknown>;

  try {
    // Build normalized model with explicit transformations
    const normalized: NormalizedReportModel = {
      sector: normalizeSector(data.sector),
      
      snapshot: {
        summary: toString((data.snapshot as any)?.summary, 'غير متوفر'),
        market_fit: toString((data.snapshot as any)?.market_fit, 'غير متوفر'),
      },
      
      evidence_summary: {
        key_findings: toArray(
          (data.evidence_summary as any)?.key_findings,
          (item: any) => ({
            finding: toString(item?.finding),
            evidence_url: toString(item?.evidence_url),
            confidence: (['low', 'medium', 'high'].includes(item?.confidence) ? item.confidence : 'low') as 'low' | 'medium' | 'high',
          })
        ),
        tech_hints: toArray<string>((data.evidence_summary as any)?.tech_hints),
        contacts_found: {
          phone: toString((data.evidence_summary as any)?.contacts_found?.phone),
          whatsapp: toString((data.evidence_summary as any)?.contacts_found?.whatsapp),
          email: toString((data.evidence_summary as any)?.contacts_found?.email),
        },
      },
      
      website_audit: {
        issues: toArray(
          (data.website_audit as any)?.issues,
          (item: any) => ({
            issue: toString(item?.issue),
            impact: toString(item?.impact),
            quick_fix: toString(item?.quick_fix),
          })
        ),
        cta_quality: toString((data.website_audit as any)?.cta_quality, 'غير متوفر'),
        tracking_gap: toString((data.website_audit as any)?.tracking_gap, 'غير متوفر'),
      },
      
      social_audit: {
        presence: toArray(
          (data.social_audit as any)?.presence,
          (item: any) => ({
            platform: toString(item?.platform),
            status: (['ok', 'missing', 'inactive'].includes(item?.status) ? item.status : 'missing') as 'ok' | 'missing' | 'inactive',
          })
        ),
        content_gaps: toArray<string>((data.social_audit as any)?.content_gaps),
        quick_content_ideas: toArray<string>((data.social_audit as any)?.quick_content_ideas),
      },
      
      pain_points: toArray(
        data.pain_points,
        (item: any) => ({
          pain: toString(item?.pain),
          solution: toString(item?.solution),
          priority: (['high', 'medium', 'low'].includes(item?.priority) ? item.priority : 'medium') as 'high' | 'medium' | 'low',
        })
      ),
      
      recommended_services: toArray(data.recommended_services, normalizeRecommendedService),
      
      talk_track: {
        opening: toString((data.talk_track as any)?.opening),
        pitch: toString((data.talk_track as any)?.pitch),
        objection_handlers: toArray(
          (data.talk_track as any)?.objection_handlers,
          (item: any) => ({
            objection: toString(item?.objection),
            answer: toString(item?.answer),
          })
        ),
        whatsapp_messages: toArray(
          (data.talk_track as any)?.whatsapp_messages,
          (item: any) => ({
            text: toString(item?.text),
            timing: toString(item?.timing),
          })
        ),
      },
      
      follow_up_plan: toArray(
        data.follow_up_plan,
        (item: any) => ({
          day: toNumber(item?.day, 1),
          channel: toString(item?.channel),
          goal: toString(item?.goal),
          action: toString(item?.action),
        })
      ),
    };

    return normalized;
  } catch (error) {
    console.error('[normalizeReport] Failed to normalize, using defaults:', error);
    return NormalizedReportSchema.parse({});
  }
}

// Export schema for testing
export { NormalizedReportSchema };

// ============================================
// Evidence Pack Normalization (for LeadForm)
// ============================================

export interface NormalizedEvidencePack {
  sources_used: string[];
  key_findings: NormalizedKeyFinding[];
  tech_hints: string[];
  contacts_found: NormalizedContactsFound;
  fetch_status: Array<{ source: string; status: string; error?: string }>;
  warnings: string[];
  // Raw bundle for AI processing
  _raw_bundle?: any;
}

/**
 * Normalize EvidencePack from enrichment service
 * Guarantees all array fields are arrays
 */
export function normalizeEvidence(raw: unknown): NormalizedEvidencePack {
  if (!raw || typeof raw !== 'object') {
    return {
      sources_used: [],
      key_findings: [],
      tech_hints: [],
      contacts_found: { phone: '', whatsapp: '', email: '' },
      fetch_status: [],
      warnings: [],
      _raw_bundle: null,
    };
  }

  const data = raw as Record<string, unknown>;

  return {
    sources_used: toArray<string>(data.sources_used),
    key_findings: toArray(
      data.key_findings,
      (item: any) => ({
        finding: toString(item?.finding),
        evidence_url: toString(item?.evidence_url),
        confidence: (['low', 'medium', 'high'].includes(item?.confidence) ? item.confidence : 'low') as 'low' | 'medium' | 'high',
      })
    ),
    tech_hints: toArray<string>(data.tech_hints),
    contacts_found: {
      phone: toString((data.contacts_found as any)?.phone),
      whatsapp: toString((data.contacts_found as any)?.whatsapp),
      email: toString((data.contacts_found as any)?.email),
    },
    fetch_status: toArray(
      data.fetch_status,
      (item: any) => ({
        source: toString(item?.source),
        status: toString(item?.status),
        error: item?.error ? toString(item.error) : undefined,
      })
    ),
    warnings: toArray<string>(data.warnings),
    // CRITICAL: Preserve raw bundle for AI processing
    _raw_bundle: (data as any)._raw_bundle || null,
  };
}
