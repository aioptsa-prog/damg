// Test OpenAI API connection
import pg from 'pg';
import dotenv from 'dotenv';

dotenv.config();

const pool = new pg.Pool({
  connectionString: process.env.DATABASE_URL,
  ssl: { rejectUnauthorized: false }
});

async function testOpenAI() {
  try {
    // Get API key from DB
    const result = await pool.query("SELECT value FROM settings WHERE key = 'ai_settings'");
    const settings = result.rows[0]?.value;
    
    if (!settings) {
      console.log('❌ No AI settings found in database');
      return;
    }

    console.log('Settings found:', {
      provider: settings.provider,
      hasOpenaiKey: !!settings.openaiApiKey,
      keyLength: settings.openaiApiKey?.length,
      keyStart: settings.openaiApiKey?.slice(0, 10) + '...',
      model: settings.openaiModel
    });

    if (!settings.openaiApiKey) {
      console.log('❌ No OpenAI API key configured');
      return;
    }

    // Test OpenAI API
    console.log('\nTesting OpenAI API...');
    const response = await fetch('https://api.openai.com/v1/chat/completions', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${settings.openaiApiKey}`
      },
      body: JSON.stringify({
        model: settings.openaiModel || 'gpt-4o-mini',
        messages: [{ role: 'user', content: 'Say "Hello" in Arabic' }],
        max_tokens: 50
      })
    });

    if (!response.ok) {
      const error = await response.text();
      console.log('❌ OpenAI API Error:', response.status, error);
      return;
    }

    const data = await response.json();
    console.log('✅ OpenAI API works!');
    console.log('Response:', data.choices[0]?.message?.content);

  } catch (error) {
    console.error('❌ Error:', error.message);
  } finally {
    await pool.end();
  }
}

testOpenAI();
