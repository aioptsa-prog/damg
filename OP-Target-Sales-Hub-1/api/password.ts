
import bcrypt from 'bcrypt';
import { query } from './_db.js';
import { requireAuth, requireRole, getAuthFromRequest } from './_auth.js';

/**
 * Password API - Combined endpoint for change-password and reset-password
 * POST /api/password?action=change - User changes own password
 * POST /api/password?action=reset - Admin resets user password
 */

const BCRYPT_ROUNDS = 10;

async function handleChangePassword(req: any, res: any) {
    const user = getAuthFromRequest(req);
    if (!user) {
        return res.status(401).json({ error: 'Not authenticated' });
    }

    const { currentPassword, newPassword } = req.body;

    if (!currentPassword || !newPassword) {
        return res.status(400).json({ error: 'Current and new password are required' });
    }

    if (newPassword.length < 8) {
        return res.status(400).json({ error: 'Password must be at least 8 characters' });
    }

    try {
        const userResult = await query('SELECT password_hash FROM users WHERE id = $1', [user.id]);
        if (userResult.rows.length === 0) {
            return res.status(404).json({ error: 'User not found' });
        }

        const isValid = await bcrypt.compare(currentPassword, userResult.rows[0].password_hash);
        if (!isValid) {
            return res.status(401).json({ error: 'Current password is incorrect' });
        }

        const newHash = await bcrypt.hash(newPassword, BCRYPT_ROUNDS);
        await query(
            'UPDATE users SET password_hash = $1, must_change_password = false WHERE id = $2',
            [newHash, user.id]
        );

        await query(
            'INSERT INTO audit_logs (id, actor_user_id, action, entity_type, entity_id, created_at) VALUES ($1, $2, $3, $4, $5, NOW())',
            [crypto.randomUUID(), user.id, 'PASSWORD_CHANGED', 'USER', user.id]
        );

        return res.status(200).json({ success: true, message: 'Password changed successfully' });
    } catch (error: any) {
        console.error('Change password error:', error);
        return res.status(500).json({ error: 'Internal server error' });
    }
}

async function handleResetPassword(req: any, res: any) {
    const admin = requireRole(req, res, ['SUPER_ADMIN']);
    if (!admin) return;

    const { userId, temporaryPassword } = req.body;

    if (!userId) {
        return res.status(400).json({ error: 'User ID is required' });
    }

    if (userId === admin.id) {
        return res.status(400).json({ error: 'Cannot reset your own password via this endpoint' });
    }

    try {
        const userCheck = await query('SELECT id FROM users WHERE id = $1', [userId]);
        if (userCheck.rows.length === 0) {
            return res.status(404).json({ error: 'User not found' });
        }

        const tempPass = temporaryPassword || `Temp${Math.random().toString(36).slice(2, 10)}!`;
        const hash = await bcrypt.hash(tempPass, BCRYPT_ROUNDS);

        await query(
            'UPDATE users SET password_hash = $1, must_change_password = true WHERE id = $2',
            [hash, userId]
        );

        await query(
            'INSERT INTO audit_logs (id, actor_user_id, action, entity_type, entity_id, created_at) VALUES ($1, $2, $3, $4, $5, NOW())',
            [crypto.randomUUID(), admin.id, 'PASSWORD_RESET', 'USER', userId]
        );

        return res.status(200).json({ 
            success: true, 
            temporaryPassword: tempPass,
            message: 'Password reset. User must change on next login.'
        });
    } catch (error: any) {
        console.error('Reset password error:', error);
        return res.status(500).json({ error: 'Internal server error' });
    }
}

export default async function handler(req: any, res: any) {
    if (req.method !== 'POST') {
        return res.status(405).json({ error: 'Method not allowed' });
    }

    const action = req.query.action || req.body.action;

    switch (action) {
        case 'change':
            return handleChangePassword(req, res);
        case 'reset':
            return handleResetPassword(req, res);
        default:
            return res.status(400).json({ error: 'Invalid action. Use ?action=change or ?action=reset' });
    }
}
