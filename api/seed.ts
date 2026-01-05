/**
 * Seed Script - Creates initial SUPER_ADMIN user if none exists
 * 
 * SECURITY: Returns 404 in production environment
 * 
 * Usage (Preview/Dev only):
 *   POST /api/seed with { secret: SEED_SECRET }
 */

const BCRYPT_ROUNDS = 10;

// API Handler
export default async function handler(req: any, res: any) {
    // P0 SECURITY: Return 404 in production FIRST (before any imports)
    const isProduction = process.env.VERCEL_ENV === 'production';
    
    if (isProduction) {
        return res.status(404).json({ error: 'Not found' });
    }

    if (req.method !== 'POST') {
        return res.status(405).json({ error: 'Method not allowed' });
    }

    // Require SEED_SECRET
    const seedSecret = process.env.SEED_SECRET;
    const providedSecret = req.body?.secret;

    if (!seedSecret) {
        return res.status(500).json({ error: 'SEED_SECRET not configured' });
    }

    if (providedSecret !== seedSecret) {
        return res.status(403).json({ error: 'Invalid seed secret' });
    }

    try {
        // Dynamic imports - only loaded after security checks pass
        const bcrypt = await import('bcrypt');
        const { query } = await import('./_db.js');
        
        const adminEmail = process.env.ADMIN_EMAIL || 'admin@optarget.sa';
        const adminPassword = process.env.ADMIN_PASSWORD;

        if (!adminPassword) {
            return res.status(500).json({ 
                error: 'ADMIN_PASSWORD environment variable is required' 
            });
        }

        // Check if SUPER_ADMIN exists
        const existingAdmin = await query(
            "SELECT id FROM users WHERE role = 'SUPER_ADMIN' LIMIT 1"
        );

        if (existingAdmin.rows.length > 0) {
            return res.status(200).json({ 
                created: false, 
                message: 'SUPER_ADMIN already exists' 
            });
        }

        // Create admin
        const passwordHash = await bcrypt.default.hash(adminPassword, BCRYPT_ROUNDS);
        const adminId = crypto.randomUUID();
        
        await query(
            `INSERT INTO users (id, name, email, password_hash, role, is_active, must_change_password, created_at) 
             VALUES ($1, $2, $3, $4, $5, $6, $7, NOW())`,
            [adminId, 'مسؤول النظام', adminEmail, passwordHash, 'SUPER_ADMIN', true, true]
        );

        await query(
            'INSERT INTO audit_logs (id, actor_user_id, action, entity_type, entity_id, created_at) VALUES ($1, $2, $3, $4, $5, NOW())',
            [crypto.randomUUID(), 'system', 'ADMIN_SEEDED', 'USER', adminId]
        );

        return res.status(201).json({ 
            created: true, 
            message: `Admin created: ${adminEmail}` 
        });

    } catch (error: any) {
        console.error('Seed error:', error.message);
        return res.status(500).json({ error: 'Seed failed' });
    }
}
