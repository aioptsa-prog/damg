/**
 * Domain Schemas - Zod validation for all API contracts
 * Principal Engineer Quality Gate: All data must be validated before use
 */

import { z } from 'zod';

// ============================================
// Enrichment Service Schemas
// ============================================

export const KeyFindingSchema = z.object({
  finding: z.string().default(''),
  evidence_url: z.string().optional(),
  confidence: z.enum(['low', 'medium', 'high']).default('low'),
});

export const FetchStatusSchema = z.object({
  source: z.string(),
  status: z.enum(['success', 'failed', 'skipped']),
  error: z.string().optional(),
});

export const ContactsFoundSchema = z.object({
  phone: z.string().optional(),
  whatsapp: z.string().optional(),
  email: z.string().optional(),
});

export const EvidencePackSchema = z.object({
  sources_used: z.array(z.string()).default([]),
  key_findings: z.array(KeyFindingSchema).default([]),
  tech_hints: z.array(z.string()).default([]),
  contacts_found: ContactsFoundSchema.default({}),
  fetch_status: z.array(FetchStatusSchema).default([]),
  warnings: z.array(z.string()).default([]),
});

// ============================================
// Sector Detection Schemas
// ============================================

export const SectorSchema = z.object({
  primary: z.string().default('other'),
  confidence: z.number().min(0).max(100).default(0),
  matched_signals: z.array(z.string()).default([]),
}).default({ primary: 'other', confidence: 0, matched_signals: [] });

// ============================================
// AI Report Output Schemas
// ============================================

export const WebsiteIssueSchema = z.object({
  issue: z.string().default(''),
  impact: z.string().default(''),
  quick_fix: z.string().default(''),
});

export const WebsiteAuditSchema = z.object({
  issues: z.array(WebsiteIssueSchema).default([]),
  cta_quality: z.string().default('غير متوفر'),
  tracking_gap: z.string().default('غير متوفر'),
}).default({ issues: [], cta_quality: 'غير متوفر', tracking_gap: 'غير متوفر' });

export const SocialPresenceSchema = z.object({
  platform: z.string(),
  status: z.enum(['ok', 'missing', 'inactive']).default('missing'),
});

export const SocialAuditSchema = z.object({
  presence: z.array(SocialPresenceSchema).default([]),
  content_gaps: z.array(z.string()).default([]),
  quick_content_ideas: z.array(z.string()).default([]),
}).default({ presence: [], content_gaps: [], quick_content_ideas: [] });

export const EvidenceSummarySchema = z.object({
  key_findings: z.array(KeyFindingSchema).default([]),
  tech_hints: z.array(z.string()).default([]),
  contacts_found: ContactsFoundSchema.default({}),
}).default({ key_findings: [], tech_hints: [], contacts_found: {} });

export const SnapshotSchema = z.object({
  summary: z.string().default('غير متوفر'),
  market_fit: z.string().default('غير متوفر'),
}).default({ summary: 'غير متوفر', market_fit: 'غير متوفر' });

export const PackageSuggestionSchema = z.object({
  package_name: z.string().default(''),
  price_range: z.string().default(''),
  scope: z.string().default(''),
});

export const RecommendedServiceSchema = z.object({
  service: z.string().default(''),
  tier: z.enum(['tier1', 'tier2', 'tier3']).default('tier1'),
  why: z.string().default(''),
  confidence: z.number().default(0),
  package_suggestion: PackageSuggestionSchema.optional(),
});

export const ObjectionHandlerSchema = z.object({
  objection: z.string().default(''),
  answer: z.string().default(''),
});

export const WhatsAppMessageSchema = z.object({
  text: z.string().default(''),
  timing: z.string().optional(),
});

export const TalkTrackSchema = z.object({
  opening: z.string().default(''),
  pitch: z.string().default(''),
  objection_handlers: z.array(ObjectionHandlerSchema).default([]),
  whatsapp_messages: z.array(WhatsAppMessageSchema).default([]),
}).default({ opening: '', pitch: '', objection_handlers: [], whatsapp_messages: [] });

export const FollowUpStepSchema = z.object({
  day: z.number().default(1),
  channel: z.string().default(''),
  goal: z.string().default(''),
  action: z.string().default(''),
});

export const PainPointSchema = z.object({
  pain: z.string().default(''),
  solution: z.string().default(''),
  priority: z.enum(['high', 'medium', 'low']).default('medium'),
});

// Main Report Output Schema
export const ReportOutputSchema = z.object({
  sector: SectorSchema,
  snapshot: SnapshotSchema,
  evidence_summary: EvidenceSummarySchema,
  website_audit: WebsiteAuditSchema,
  social_audit: SocialAuditSchema,
  pain_points: z.array(PainPointSchema).default([]),
  recommended_services: z.array(RecommendedServiceSchema).default([]),
  talk_track: TalkTrackSchema,
  follow_up_plan: z.array(FollowUpStepSchema).default([]),
}).passthrough(); // Allow extra fields

// ============================================
// API Response Envelope
// ============================================

export const ApiResponseSchema = <T extends z.ZodTypeAny>(dataSchema: T) =>
  z.object({
    ok: z.boolean(),
    data: dataSchema.optional(),
    error: z.object({
      code: z.string(),
      message: z.string(),
      details: z.any().optional(),
    }).optional(),
  });

// ============================================
// Helper Functions
// ============================================

/**
 * Safely parse report output with defaults for missing fields
 */
export function parseReportOutput(raw: unknown): z.infer<typeof ReportOutputSchema> {
  const result = ReportOutputSchema.safeParse(raw);
  if (result.success) {
    return result.data;
  }
  // Return safe defaults if parsing fails
  console.warn('[Schema] Report output parsing failed, using defaults:', result.error.issues);
  return ReportOutputSchema.parse({});
}

/**
 * Safely parse evidence pack with defaults
 */
export function parseEvidencePack(raw: unknown): z.infer<typeof EvidencePackSchema> {
  const result = EvidencePackSchema.safeParse(raw);
  if (result.success) {
    return result.data;
  }
  console.warn('[Schema] Evidence pack parsing failed, using defaults:', result.error.issues);
  return EvidencePackSchema.parse({});
}

// Type exports
export type ReportOutput = z.infer<typeof ReportOutputSchema>;
export type EvidencePack = z.infer<typeof EvidencePackSchema>;
export type Sector = z.infer<typeof SectorSchema>;
export type TalkTrack = z.infer<typeof TalkTrackSchema>;
export type WebsiteAudit = z.infer<typeof WebsiteAuditSchema>;
export type SocialAudit = z.infer<typeof SocialAuditSchema>;
export type EvidenceSummary = z.infer<typeof EvidenceSummarySchema>;
export type Snapshot = z.infer<typeof SnapshotSchema>;
