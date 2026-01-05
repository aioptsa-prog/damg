
import { WhatsAppSettings } from '../types';
import { db } from './db';
import { logger } from '../utils/logger';
import { encryptionService } from './encryptionService';

class WhatsAppService {
  private getSettings(): WhatsAppSettings {
    const saved = localStorage.getItem('opt_whatsapp_settings');
    const settings = saved ? JSON.parse(saved) : {
      enabled: false,
      providerName: 'WHSender',
      baseUrl: 'https://api.whsender.com/v1/send',
      apiKey: '',
      senderId: ''
    };
    
    // Auto-Decrypt on fetch
    if (settings.apiKey && settings.apiKey.startsWith('enc_v1:')) {
      settings.apiKey = encryptionService.decrypt(settings.apiKey);
    }
    
    return settings;
  }

  async sendMessage(phone: string, message: string, leadId: string, userId: string) {
    const settings = this.getSettings();
    
    if (settings.enabled && settings.apiKey && settings.baseUrl) {
      try {
        const payload = {
          to: phone,
          message: message,
          sender: settings.senderId,
          apikey: settings.apiKey // Decrypted key used here
        };

        logger.debug('Sending via API...', payload);
        await new Promise(r => setTimeout(r, 1000));
        
        db.addActivity({
          leadId,
          userId,
          type: 'whatsapp_sent',
          payload: { phone, method: 'API', status: 'success' }
        });

        return { success: true, method: 'API' };
      } catch (error) {
        throw new Error('فشل الإرسال عبر المزود.');
      }
    } else {
      const encodedMsg = encodeURIComponent(message);
      const url = `https://wa.me/${phone}?text=${encodedMsg}`;
      db.addActivity({
        leadId,
        userId,
        type: 'whatsapp_sent',
        payload: { phone, method: 'LINK_FALLBACK', status: 'redirected' }
      });
      window.open(url, '_blank');
      return { success: true, method: 'LINK' };
    }
  }

  saveSettings(settings: WhatsAppSettings) {
    const old = this.getSettings();
    const toSave = { ...settings };
    
    // Encrypt sensitive field
    if (toSave.apiKey && !toSave.apiKey.startsWith('enc_v1:')) {
      toSave.apiKey = encryptionService.encrypt(toSave.apiKey);
    }

    localStorage.setItem('opt_whatsapp_settings', JSON.stringify(toSave));
    
    db.addAuditLog({
      actorUserId: 'u1',
      action: 'UPDATE_WHATSAPP_CONFIG',
      entityType: 'SETTINGS',
      entityId: 'global',
      before: old,
      after: toSave
    });
  }
}

export const whatsappService = new WhatsAppService();
