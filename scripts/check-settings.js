import pg from 'pg';
const { Pool } = pg;

const pool = new Pool({
  connectionString: process.env.DATABASE_URL_UNPOOLED,
  ssl: { rejectUnauthorized: false }
});

async function main() {
  try {
    const result = await pool.query("SELECT key, value FROM settings WHERE key = 'ai_settings'");
    console.log('AI Settings:', JSON.stringify(result.rows[0]?.value || {}, null, 2));
  } catch (e) {
    console.error('Error:', e.message);
  } finally {
    await pool.end();
  }
}

main();
