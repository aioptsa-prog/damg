/**
 * Integration Client Service
 * 
 * Frontend service for calling OP-Target integration endpoints.
 * Does NOT call forge directly - all calls go through OP-Target server.
 * 
 * @since Phase 5
 */

// Types
export interface ForgeLink {
  id: string;
  op_target_lead_id: string;
  external_lead_id: string;
  external_phone?: string;
  external_name?: string;
  external_city?: string;
  linked_at: string;
  link_status: string;
}

export interface ForgeSurveyReport {
  id: string;
  output: {
    analysis?: {
      summary?: string;
      potential?: string;
      recommended_approach?: string;
      key_points?: string[];
    };
    suggested_message?: string;
  };
  suggested_message?: string;
  created_at: string;
  ttl_expires_at?: string;
  usage?: {
    latencyMs: number;
    inputTokens: number;
    outputTokens: number;
    cost: number;
  };
}

export interface WhatsAppSendResult {
  ok: boolean;
  sent?: boolean;
  dry_run?: boolean;
  phone?: string;
  message_preview?: string;
  report_id?: string;
  provider_response?: any;
  error?: string;
  dedupe_blocked?: boolean;
}

export interface IntegrationError {
  ok: false;
  error: string;
  hint?: string;
  details?: string;
}

// Phase 6: Enrichment types
export interface EnrichJob {
  jobId: string;
  status: 'queued' | 'running' | 'success' | 'partial' | 'failed' | 'cancelled';
  progress: number;
  modules: ModuleStatus[];
  created_at?: string;
  started_at?: string;
  finished_at?: string;
  last_error?: string;
  correlationId?: string;
}

export interface ModuleStatus {
  module: string;
  status: 'pending' | 'running' | 'success' | 'failed' | 'skipped';
  attempt: number;
  error_code?: string;
  started_at?: string;
  finished_at?: string;
}

export interface LeadSnapshot {
  forgeLeadId: number;
  snapshot: {
    lead_id?: number;
    collected_at?: string;
    sources?: string[];
    name?: string;
    phones?: string[];
    website?: string;
    address?: string;
    category?: string;
    emails?: string[];
    social_links?: Record<string, string>;
    maps?: {
      name?: string;
      category?: string;
      address?: string;
      phones?: string[];
      website?: string;
      rating?: number;
      reviews_count?: number;
      opening_hours?: string;
      map_url?: string;
    };
    website_data?: {
      title?: string;
      description?: string;
      emails?: string[];
      phones?: string[];
      social_links?: Record<string, string>;
      tech_hints?: string[];
    };
    instagram?: {
      url?: string;
      exists?: boolean;
    };
  };
  source: string;
  jobId?: string;
  created_at: string;
}

type ApiResponse<T> = T | IntegrationError;

/**
 * Check if response is an error
 */
export function isError(response: any): response is IntegrationError {
  return response && response.ok === false && typeof response.error === 'string';
}

/**
 * Get user-friendly error message in Arabic
 */
export function getErrorMessage(error: IntegrationError): string {
  const errorMap: Record<string, string> = {
    'Not found': 'الميزة غير مفعّلة',
    'Unauthorized': 'يجب تسجيل الدخول',
    'Access denied to this lead': 'ليس لديك صلاحية للوصول لهذا العميل',
    'Lead not linked to forge': 'العميل غير مربوط بـ Forge',
    'No report found': 'لا يوجد تقرير. قم بتوليد تقرير أولاً',
    'No message available': 'لا توجد رسالة مقترحة',
    'Duplicate send blocked': 'تم إرسال نفس الرسالة مؤخراً',
    'Failed to obtain forge token': 'فشل الاتصال بـ Forge',
    'Failed to send message': 'فشل إرسال الرسالة',
    'Rate limit exceeded': 'تجاوزت الحد المسموح. انتظر قليلاً',
    'Link already exists': 'الربط موجود مسبقاً',
    'No phone number in link': 'لا يوجد رقم هاتف في الربط',
    // Phase 6: Enrichment errors
    'Job already in progress': 'يوجد عملية جمع بيانات قيد التنفيذ',
    'No valid modules specified': 'لم يتم تحديد مصادر صالحة',
    'No snapshot found': 'لا توجد بيانات مُجمّعة. شغّل Worker أولاً',
    'Forge service unavailable': 'خدمة Forge غير متاحة حالياً',
    'Failed to create job': 'فشل إنشاء عملية جمع البيانات',
  };

  return errorMap[error.error] || error.error || 'حدث خطأ غير متوقع';
}

/**
 * Integration Client
 */
class IntegrationClient {
  private baseUrl = '/api/integration/forge';

  /**
   * Make authenticated request
   */
  private async request<T>(
    endpoint: string,
    options: RequestInit = {}
  ): Promise<ApiResponse<T>> {
    try {
      const response = await fetch(`${this.baseUrl}${endpoint}`, {
        ...options,
        headers: {
          'Content-Type': 'application/json',
          ...options.headers,
        },
        credentials: 'include', // Include cookies for auth
      });

      const data = await response.json();
      return data;
    } catch (error: any) {
      return {
        ok: false,
        error: 'Network error',
        details: error.message,
      };
    }
  }

  // ==========================================
  // Link Management
  // ==========================================

  /**
   * Get link status for a lead
   */
  async getLink(opLeadId: string): Promise<ApiResponse<{ ok: true; link: ForgeLink | null }>> {
    return this.request(`/link?op_target_lead_id=${encodeURIComponent(opLeadId)}`);
  }

  /**
   * Create link between OP-Target lead and forge lead
   */
  async createLink(params: {
    opLeadId: string;
    forgeLeadId: string;
    forgePhone?: string;
    forgeName?: string;
    forgeCity?: string;
  }): Promise<ApiResponse<{ ok: true; link: ForgeLink }>> {
    return this.request('/link', {
      method: 'POST',
      body: JSON.stringify({
        op_target_lead_id: params.opLeadId,
        forge_lead_id: params.forgeLeadId,
        forge_phone: params.forgePhone,
        forge_name: params.forgeName,
        forge_city: params.forgeCity,
      }),
    });
  }

  /**
   * Remove link
   */
  async removeLink(opLeadId: string): Promise<ApiResponse<{ ok: true; unlinked: boolean }>> {
    return this.request(`/link?op_target_lead_id=${encodeURIComponent(opLeadId)}`, {
      method: 'DELETE',
    });
  }

  // ==========================================
  // Survey Generation
  // ==========================================

  /**
   * Generate or get cached survey report
   */
  async generateSurvey(
    opLeadId: string,
    force = false
  ): Promise<ApiResponse<{ ok: true; cached: boolean; report: ForgeSurveyReport }>> {
    return this.request('/survey', {
      method: 'POST',
      body: JSON.stringify({ opLeadId, force }),
    });
  }

  // ==========================================
  // WhatsApp Send
  // ==========================================

  /**
   * Send WhatsApp message from report
   */
  async sendWhatsApp(params: {
    opLeadId: string;
    reportId?: string;
    message?: string;
    dryRun?: boolean;
  }): Promise<ApiResponse<WhatsAppSendResult>> {
    return this.request('/whatsapp/send', {
      method: 'POST',
      body: JSON.stringify({
        opLeadId: params.opLeadId,
        reportId: params.reportId,
        message: params.message,
        dryRun: params.dryRun,
      }),
    });
  }

  /**
   * Preview WhatsApp message (dry run)
   */
  async previewWhatsApp(
    opLeadId: string,
    message?: string
  ): Promise<ApiResponse<WhatsAppSendResult>> {
    return this.sendWhatsApp({ opLeadId, message, dryRun: true });
  }

  // ==========================================
  // Phase 6: Worker Enrichment
  // ==========================================

  /**
   * Start enrichment job for a lead
   */
  async startEnrich(params: {
    opLeadId: string;
    modules?: string[];
    force?: boolean;
  }): Promise<ApiResponse<{ ok: true; jobId: string; status: string; modules: string[]; correlationId: string }>> {
    return this.request('/enrich', {
      method: 'POST',
      body: JSON.stringify({
        opLeadId: params.opLeadId,
        modules: params.modules || ['maps', 'website'],
        options: { force: params.force || false },
      }),
    });
  }

  /**
   * Get enrichment job status
   */
  async getEnrichStatus(jobId: string): Promise<ApiResponse<{ ok: true } & EnrichJob>> {
    return this.request(`/enrich/status?jobId=${encodeURIComponent(jobId)}`);
  }

  /**
   * Get lead snapshot
   */
  async getSnapshot(opLeadId: string): Promise<ApiResponse<{ ok: true } & LeadSnapshot>> {
    return this.request(`/snapshot?opLeadId=${encodeURIComponent(opLeadId)}`);
  }
}

// Export singleton instance
export const integrationClient = new IntegrationClient();
