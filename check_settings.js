import pg from 'pg';
import dotenv from 'dotenv';
dotenv.config();

const pool = new pg.Pool({
  connectionString: process.env.DATABASE_URL,
  ssl: { rejectUnauthorized: false }
});

async function check() {
  const r = await pool.query("SELECT value FROM settings WHERE key = 'ai_settings'");
  const settings = r.rows[0]?.value;
  console.log('Current AI Settings:');
  console.log('  activeProvider:', settings?.activeProvider);
  console.log('  provider:', settings?.provider);
  console.log('  hasOpenaiKey:', !!settings?.openaiApiKey);
  console.log('  hasGeminiKey:', !!settings?.geminiApiKey);
  console.log('  openaiModel:', settings?.openaiModel);
  
  // Fix: set activeProvider to openai
  if (settings?.activeProvider !== 'openai' && settings?.openaiApiKey) {
    settings.activeProvider = 'openai';
    await pool.query("UPDATE settings SET value = $1 WHERE key = 'ai_settings'", [JSON.stringify(settings)]);
    console.log('\n✅ Fixed: activeProvider set to openai');
  }
  
  await pool.end();
}
check();
