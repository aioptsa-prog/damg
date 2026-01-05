
import { query, toCamel } from './_db.js';
import { requireRole } from './_auth.js';

/**
 * Settings API with RBAC protection
 * - All settings operations: SUPER_ADMIN only
 */

export default async function handler(req: any, res: any) {
  // Settings are admin-only
  const user = requireRole(req, res, ['SUPER_ADMIN']);
  if (!user) return;

  const { method } = req;
  const path = req.url.split('?')[0];

  try {
    if (method === 'GET') {
      // Support both /api/settings?type=ai and /api/settings/ai paths
      const typeParam = req.query?.type;
      const key = typeParam === 'ai' || path.includes('/ai') ? 'ai_settings' : 
                  typeParam === 'scoring' || path.includes('/scoring') ? 'scoring_settings' : null;
      if (!key) return res.status(404).json({ error: 'Invalid settings type' });

      const resSet = await query('SELECT value FROM settings WHERE key = $1', [key]);

      // For AI settings, mask sensitive keys in response
      if (key === 'ai_settings' && resSet.rows[0]?.value) {
        const settings = resSet.rows[0].value;
        // Mask API keys - only show last 4 chars
        if (settings.geminiApiKey) {
          settings.geminiApiKey = '***' + settings.geminiApiKey.slice(-4);
        }
        if (settings.openaiApiKey) {
          settings.openaiApiKey = '***' + settings.openaiApiKey.slice(-4);
        }
        return res.status(200).json(settings);
      }

      return res.status(200).json(resSet.rows[0]?.value || {});
    }

    if (method === 'POST') {
      // Support both /api/settings?type=ai and /api/settings/ai paths
      const typeParam = req.query?.type;
      const key = typeParam === 'ai' || path.includes('/ai') ? 'ai_settings' : 
                  typeParam === 'scoring' || path.includes('/scoring') ? 'scoring_settings' : null;
      if (!key) return res.status(404).json({ error: 'Invalid settings type' });

      const { settings } = req.body;
      let finalValue = settings || req.body;

      // For AI settings, merge with existing to preserve keys that weren't changed
      if (key === 'ai_settings') {
        const existing = await query('SELECT value FROM settings WHERE key = $1', [key]);
        const existingSettings = existing.rows[0]?.value || {};
        
        // If new key starts with *** or is empty, keep the existing one
        if (!finalValue.geminiApiKey || finalValue.geminiApiKey.startsWith('***')) {
          finalValue.geminiApiKey = existingSettings.geminiApiKey || '';
        }
        if (!finalValue.openaiApiKey || finalValue.openaiApiKey.startsWith('***')) {
          finalValue.openaiApiKey = existingSettings.openaiApiKey || '';
        }
      }

      await query(
        'INSERT INTO settings (key, value, updated_at) VALUES ($1, $2, CURRENT_TIMESTAMP) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = CURRENT_TIMESTAMP',
        [key, JSON.stringify(finalValue)]
      );

      // Audit Log (mask keys in audit)
      const auditValue = { ...finalValue };
      if (auditValue.geminiApiKey) auditValue.geminiApiKey = '***MASKED***';
      if (auditValue.openaiApiKey) auditValue.openaiApiKey = '***MASKED***';
      
      await query(
        'INSERT INTO audit_logs (id, actor_user_id, action, entity_type, entity_id, after, created_at) VALUES ($1, $2, $3, $4, $5, $6, NOW())',
        [crypto.randomUUID(), user.id, 'UPDATE_SETTINGS', 'SETTINGS', key, JSON.stringify(auditValue)]
      );

      return res.status(200).json({ success: true });
    }

    res.setHeader('Allow', ['GET', 'POST']);
    res.status(405).end();
  } catch (error: any) {
    console.error('API Settings Error:', error);
    res.status(500).json({ message: error.message });
  }
}

