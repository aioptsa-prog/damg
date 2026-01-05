
import { Lead, Report } from '../types';
import { logger } from '../utils/logger';
import { db } from './db';

export const exportService = {
  /**
   * Triggers the browser print dialog. 
   * CSS media queries in index.html handle the A4 RTL formatting.
   */
  async toPdf(lead: Lead, report: Report) {
    logger.debug('Generating PDF for', lead.companyName);
    
    // Add audit log for the export
    db.addAuditLog({
      actorUserId: 'u1',
      action: 'EXPORT_PDF',
      entityType: 'REPORT',
      entityId: report.id
    });

    db.addActivity({
      leadId: lead.id,
      userId: 'u1',
      type: 'export_pdf',
      payload: { 
        version: report.versionNumber, 
        timestamp: new Date().toISOString(),
        format: 'A4_Professional'
      }
    });
    
    // Small delay to ensure any dynamic content is settled
    setTimeout(() => {
      window.print();
    }, 500);
  },

  /**
   * Simulates a POST request to a Google Sheets App Script or Service Account.
   */
  async toSheets(lead: Lead, report: Report) {
    logger.debug('Connecting to Google Sheets API...');
    
    // Simulate API Roundtrip
    await new Promise(r => setTimeout(r, 2000));
    
    const settings = JSON.parse(localStorage.getItem('opt_sheets_settings') || '{}');
    if (settings.enabled === false) {
      throw new Error('SHEETS_DISABLED: تكامل Google Sheets غير مفعل من الإعدادات.');
    }

    db.addAuditLog({
      actorUserId: 'u1',
      action: 'EXPORT_SHEETS',
      entityType: 'LEAD',
      entityId: lead.id
    });

    db.addActivity({
      leadId: lead.id,
      userId: 'u1',
      type: 'export_sheet',
      payload: { 
        sheetId: settings.sheetId || 'opt_sales_tracker_2024',
        tabName: settings.tabName || 'Leads',
        data: {
          date: new Date().toLocaleDateString('ar-SA'),
          rep: 'أحمد السوبر',
          company: lead.companyName,
          sector: lead.sector?.primary || 'عام',
          status: lead.status,
          services: (Array.isArray(report.output?.recommended_services) ? report.output.recommended_services : []).map((s: any) => s.service).join(' | ')
        }
      }
    });
    
    return true;
  }
};
