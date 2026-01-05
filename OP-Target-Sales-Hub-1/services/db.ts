
import { Lead, Report, LeadActivity, Task, AuditLog, User, LeadStatus, ScoringSettings, ServiceItem, PackageItem, UserRole, Team, AISettings } from '../types';
import { SERVICES_CATALOG, PACKAGES_CATALOG } from '../constants';

/**
 * Database Production Bridge v5.0
 * يقوم هذا المحرك بالربط بين الواجهة والـ Vercel Serverless Functions.
 */
class DatabaseService {
  private apiBase = '/api';

  private async fetchAPI<T>(endpoint: string, options: RequestInit = {}): Promise<T> {
    const response = await fetch(`${this.apiBase}${endpoint}`, {
      ...options,
      headers: {
        'Content-Type': 'application/json',
        ...options.headers,
      },
    });

    if (!response.ok) {
      const error = await response.json().catch(() => ({ message: 'Network error' }));
      throw new Error(error.message || 'حدث خطأ في الاتصال بالسيرفر');
    }

    return response.json();
  }

  // --- Leads ---
  async getLeads(user: User): Promise<Lead[]> {
    return this.fetchAPI<Lead[]>(`/leads?userId=${user.id}`);
  }

  async saveLead(lead: Lead): Promise<void> {
    return this.fetchAPI<void>('/leads', {
      method: 'POST',
      body: JSON.stringify(lead),
    });
  }

  async deleteLead(id: string, actorUserId: string): Promise<void> {
    return this.fetchAPI<void>(`/leads?id=${id}&actorId=${actorUserId}`, {
      method: 'DELETE',
    });
  }

  // --- Reports ---
  async getReportsByLeadId(leadId: string): Promise<Report[]> {
    return this.fetchAPI<Report[]>(`/reports?leadId=${leadId}`);
  }

  async saveReport(report: Report, userId: string): Promise<void> {
    return this.fetchAPI<void>('/reports', {
      method: 'POST',
      body: JSON.stringify({ ...report, userId }),
    });
  }

  async getNextReportVersion(leadId: string): Promise<number> {
    const reports = await this.getReportsByLeadId(leadId);
    return reports.length > 0 ? reports[0].versionNumber + 1 : 1;
  }

  // --- Tasks & Activities ---
  async getTasks(leadId?: string): Promise<Task[]> {
    const query = leadId ? `?leadId=${leadId}` : '';
    return this.fetchAPI<Task[]>(`/tasks${query}`);
  }

  async saveTasks(newTasks: Task[]): Promise<void> {
    return this.fetchAPI<void>('/tasks', {
      method: 'POST',
      body: JSON.stringify(newTasks),
    });
  }

  async updateTaskStatus(taskId: string, status: 'OPEN' | 'DONE', userId: string): Promise<void> {
    return this.fetchAPI<void>(`/tasks/status`, {
      method: 'PUT',
      body: JSON.stringify({ taskId, status, userId }),
    });
  }

  async addActivity(activity: Partial<LeadActivity>): Promise<void> {
    return this.fetchAPI<void>('/activities', {
      method: 'POST',
      body: JSON.stringify(activity),
    });
  }

  async getActivities(leadId: string): Promise<LeadActivity[]> {
    return this.fetchAPI<LeadActivity[]>(`/activities?leadId=${leadId}`);
  }

  // --- Settings & Metadata ---
  async getAISettings(): Promise<AISettings> {
    try {
      return await this.fetchAPI<AISettings>('/settings?type=ai');
    } catch {
      return {
        activeProvider: 'gemini',
        geminiApiKey: '',
        geminiModel: 'gemini-2.0-flash',
        openaiApiKey: '',
        openaiModel: 'gpt-4o',
        temperature: 0.7,
        maxTokens: 2048,
        systemInstruction: ''
      };
    }
  }

  async saveAISettings(settings: AISettings, actorUserId: string): Promise<void> {
    return this.fetchAPI<void>('/settings?type=ai', {
      method: 'POST',
      body: JSON.stringify({ settings, actorUserId }),
    });
  }

  async getAnalytics(user: User): Promise<any> {
    try {
      return await this.fetchAPI<any>(`/analytics?userId=${user.id}`);
    } catch {
      return {
        totalLeads: 0,
        totalReports: 0,
        wonLeads: 0,
        totalCost: 0,
        avgLatency: 0,
        funnel: { new: 0, contacted: 0, interested: 0, won: 0 },
        topSectors: []
      };
    }
  }

  // --- Static Helpers (Until moved to API) ---
  getServices(): ServiceItem[] { return SERVICES_CATALOG; }
  getPackages(): PackageItem[] { return PACKAGES_CATALOG; }
  getScoringSettings(): ScoringSettings { 
    return { report_generated: 1, call_result: 2, whatsapp_sent: 1, status_interested: 3, status_won: 10 }; 
  }
  
  // Added missing saveScoringSettings
  async saveScoringSettings(settings: ScoringSettings): Promise<void> {
    return this.fetchAPI<void>('/settings?type=scoring', {
      method: 'POST',
      body: JSON.stringify(settings),
    });
  }

  // Seed - handled by server API
  seed() {
    // No-op: seed is handled by /api/seed endpoint
  }
  
  // Auth Helpers
  async getUsers(): Promise<User[]> { 
    try {
      const data = await this.fetchAPI<User[]>('/users');
      return Array.isArray(data) ? data : [];
    } catch {
      return [];
    }
  }
  async getTeams(): Promise<Team[]> { 
    try {
      return await this.fetchAPI<Team[]>('/users?teams=true'); 
    } catch {
      return []; // Return empty array on error
    }
  }

  async saveTeam(team: Partial<Team>): Promise<Team> {
    return this.fetchAPI<Team>('/users?teamAction=save', { 
      method: 'POST', 
      body: JSON.stringify({ team }) 
    });
  }

  async deleteTeam(teamId: string): Promise<void> {
    return this.fetchAPI<void>(`/users?teamAction=delete&teamId=${teamId}`, { method: 'GET' });
  }
  async calculateUserPoints(userId: string): Promise<number> { 
    try {
      const data = await this.fetchAPI<{points: number}>(`/users?points=true&userId=${userId}`);
      return data.points;
    } catch {
      return 0; // Return 0 on error to prevent UI crash
    }
  }

  async saveUser(user: User, actorId: string): Promise<void> {
    return this.fetchAPI<void>('/users', { method: 'POST', body: JSON.stringify({ user, actorId }) });
  }

  async deleteUser(userId: string, actorId: string): Promise<void> {
    return this.fetchAPI<void>(`/users?id=${userId}&actorId=${actorId}`, { method: 'DELETE' });
  }

  async logUsage(usage: any): Promise<void> {
    try {
      return await this.fetchAPI<void>('/logs?type=usage', { method: 'POST', body: JSON.stringify(usage) });
    } catch {
      // Silently fail - usage logging is not critical
    }
  }

  // Audit logs - using /api/users?audit=true
  async getAuditLogs(): Promise<AuditLog[]> {
    try {
      return await this.fetchAPI<AuditLog[]>('/users?audit=true');
    } catch {
      return []; // Return empty array on error to prevent UI crash
    }
  }

  addAuditLog(log: any) {
    this.fetchAPI('/logs/audit', { method: 'POST', body: JSON.stringify(log) });
  }
}

export const db = new DatabaseService();
