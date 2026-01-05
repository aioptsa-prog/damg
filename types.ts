
export enum UserRole {
  SUPER_ADMIN = 'SUPER_ADMIN',
  MANAGER = 'MANAGER',
  SALES_REP = 'SALES_REP'
}

export interface Team {
  id: string;
  name: string;
  managerUserId: string;
}

export interface User {
  id: string;
  name: string;
  email: string;
  passwordHash?: string;
  role: UserRole;
  teamId: string;
  avatar: string;
  isActive: boolean;
  mustChangePassword?: boolean;
}

export enum LeadStatus {
  NEW = 'NEW',
  CONTACTED = 'CONTACTED',
  FOLLOW_UP = 'FOLLOW_UP',
  INTERESTED = 'INTERESTED',
  WON = 'WON',
  LOST = 'LOST'
}

export type SectorSlug = 'restaurants' | 'real_estate' | 'ecommerce' | 'clinics' | 'schools' | 'other';

export interface CustomField {
  id: string;
  label: string;
  value: string;
  type: string;
}

export interface ServiceItem {
  id: string;
  name: string;
  description: string;
  sectors: SectorSlug[];
  priority: number;
}

export interface PackageItem {
  id: string;
  name: string;
  price: number;
  originalPrice?: number;
  duration: string;
  scope: string[];
}

export interface ScoringSettings {
  report_generated: number;
  call_result: number;
  whatsapp_sent: number;
  status_interested: number;
  status_won: number;
}

export interface AISettings {
  activeProvider: 'gemini' | 'openai';
  geminiApiKey: string;
  geminiModel: string;
  openaiApiKey: string;
  openaiModel: string;
  temperature: number;
  maxTokens: number;
  systemInstruction: string;
}

export interface EvidencePack {
  sources_used: string[];
  key_findings: Array<{
    finding: string;
    evidence_url?: string;
    confidence: 'low' | 'medium' | 'high';
  }>;
  contacts_found: {
    phone?: string;
    whatsapp?: string;
    email?: string;
  };
  tech_hints: string[];
  fetch_status: Array<{
    source: string;
    status: 'success' | 'failed' | 'blocked' | 'not_provided' | 'error' | 'timeout';
    statusCode?: number;
    notes?: string;
    error?: string;
  }>;
  pages?: Array<{
    url: string;
    title: string;
    description: string;
    h1: string;
    text_snippet: string;
  }>;
  // v4.0: Raw evidence bundle for AI processing
  _raw_bundle?: any;
  _diagnostics?: {
    totalDurationMs?: number;
    errors?: string[];
    warnings?: string[];
    error?: string;
  };
}

export interface Lead {
  id: string;
  companyName: string;
  activity: string;
  city?: string;
  size?: string;
  website?: string;
  notes?: string;
  sector?: { 
    primary: string; 
    confidence: number; 
    matched_signals: string[] 
  };
  status: string;
  ownerUserId: string;
  teamId: string;
  createdAt: string;
  lastActivityAt?: string;
  createdBy?: string; 
  phone?: string;
  customFields: CustomField[];
  attachments: any[];
  decisionMakerName?: string;
  decisionMakerRole?: string;
  contactEmail?: string;
  budgetRange?: 'low' | 'medium' | 'high' | string;
  goalPrimary?: string;
  timeline?: string;
  transcript?: string;
  enrichment_signals?: EvidencePack;
}

export interface Report {
  id: string;
  leadId: string;
  versionNumber: number;
  provider: 'gemini' | 'openai';
  model: string;
  promptVersion: string;
  output: any; 
  change_log?: string;
  usage?: {
    inputTokens: number;
    outputTokens: number;
    cost: number;
    latencyMs: number;
  };
  createdAt: string;
}

export interface LeadActivity {
  id: string;
  leadId: string;
  userId: string;
  type: 'status_change' | 'note' | 'call_result' | 'whatsapp_sent' | 'task_done' | 'export_pdf' | 'export_sheet' | 'report_generated';
  payload: any;
  createdAt: string;
}

export interface AuditLog {
  id: string;
  actorUserId: string;
  action: string;
  entityType: string;
  entityId: string;
  before?: any;
  after?: any;
  createdAt: string;
}

export interface WhatsAppSettings {
  enabled: boolean;
  providerName: string;
  baseUrl: string;
  apiKey: string;
  senderId: string;
}

export interface Task {
  id: string;
  leadId: string;
  assignedToUserId: string;
  dayNumber: number;
  channel: 'call' | 'whatsapp' | 'email';
  goal: string;
  action: string;
  status: 'OPEN' | 'DONE' | 'SKIPPED';
  dueDate: string;
}

export interface Survey {
  title: string;
  questions: any[];
  call_script_questions?: string[];
}
