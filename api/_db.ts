
import pg from 'pg';

const { Pool } = pg;

// Fail-closed: Require DATABASE_URL to be set
const connectionString = process.env.DATABASE_URL;
if (!connectionString) {
  throw new Error('DATABASE_URL environment variable is required. See .env.example for setup instructions.');
}

// استخدام DATABASE_URL من متغيرات البيئة
const pool = new Pool({
  connectionString: connectionString,
  ssl: {
    rejectUnauthorized: false // مطلوب للاتصال بـ Neon
  }
});

export const query = async (text: string, params?: any[]) => {
  const start = Date.now();
  const res = await pool.query(text, params);
  const duration = Date.now() - start;
  // console.log('Executed query', { text, duration, rows: res.rowCount });
  return res;
};

// وظيفة مساعدة لتحويل snake_case إلى camelCase
export const toCamel = (obj: any) => {
  if (Array.isArray(obj)) return obj.map(v => toCamel(v));
  if (obj !== null && obj.constructor === Object) {
    return Object.keys(obj).reduce((result, key) => {
      const camelKey = key.replace(/([-_][a-z])/g, group =>
        group.toUpperCase().replace('-', '').replace('_', '')
      );
      result[camelKey] = toCamel(obj[key]);
      return result;
    }, {} as any);
  }
  return obj;
};

// وظيفة مساعدة لتحويل camelCase إلى snake_case قبل الحفظ
export const toSnake = (obj: any) => {
  if (obj !== null && obj.constructor === Object) {
    return Object.keys(obj).reduce((result, key) => {
      const snakeKey = key.replace(/[A-Z]/g, letter => `_${letter.toLowerCase()}`);
      result[snakeKey] = obj[key];
      return result;
    }, {} as any);
  }
  return obj;
};
