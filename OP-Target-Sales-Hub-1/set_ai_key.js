// Script to set AI API key directly in database
// Usage: node set_ai_key.js <provider> <api_key>
// Example: node set_ai_key.js openai sk-proj-xxxxx
// Example: node set_ai_key.js gemini AIzaSyxxxxx

import pg from 'pg';
import dotenv from 'dotenv';

dotenv.config();

const provider = process.argv[2]; // 'openai' or 'gemini'
const apiKey = process.argv[3];

if (!provider || !apiKey) {
  console.log('Usage: node set_ai_key.js <provider> <api_key>');
  console.log('  provider: openai or gemini');
  console.log('  api_key: your API key');
  process.exit(1);
}

const pool = new pg.Pool({
  connectionString: process.env.DATABASE_URL,
  ssl: process.env.DATABASE_URL?.includes('neon') ? { rejectUnauthorized: false } : false
});

async function setAiKey() {
  try {
    // Get existing settings
    const existing = await pool.query("SELECT value FROM settings WHERE key = 'ai_settings'");
    let settings = existing.rows[0]?.value || {};
    
    if (typeof settings === 'string') {
      settings = JSON.parse(settings);
    }

    // Update the key
    if (provider === 'openai') {
      settings.openaiApiKey = apiKey;
      settings.provider = 'openai';
      settings.openaiModel = settings.openaiModel || 'gpt-4o-mini';
    } else if (provider === 'gemini') {
      settings.geminiApiKey = apiKey;
      settings.provider = 'gemini';
    }

    settings.temperature = settings.temperature || 0.7;

    // Save
    await pool.query(
      "INSERT INTO settings (key, value, updated_at) VALUES ('ai_settings', $1, NOW()) ON CONFLICT (key) DO UPDATE SET value = $1, updated_at = NOW()",
      [JSON.stringify(settings)]
    );

    console.log('✅ AI Settings updated successfully!');
    console.log('Provider:', settings.provider);
    console.log('Key set:', provider === 'openai' ? 'openaiApiKey' : 'geminiApiKey');
    
    // Verify
    const verify = await pool.query("SELECT value FROM settings WHERE key = 'ai_settings'");
    const saved = verify.rows[0]?.value;
    console.log('\nSaved settings:', JSON.stringify({
      ...saved,
      openaiApiKey: saved.openaiApiKey ? '***' + saved.openaiApiKey.slice(-4) : null,
      geminiApiKey: saved.geminiApiKey ? '***' + saved.geminiApiKey.slice(-4) : null
    }, null, 2));

  } catch (error) {
    console.error('❌ Error:', error.message);
  } finally {
    await pool.end();
  }
}

setAiKey();
