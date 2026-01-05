/**
 * Production-safe Logger
 * Only logs in development mode (import.meta.env.DEV)
 */

// @ts-ignore - Vite provides import.meta.env
const isDev = typeof import.meta !== 'undefined' && (import.meta as any).env?.DEV === true;

export const logger = {
  log: (...args: any[]) => {
    if (isDev) console.log(...args);
  },
  warn: (...args: any[]) => {
    if (isDev) console.warn(...args);
  },
  error: (...args: any[]) => {
    // Errors always log (important for debugging production issues)
    console.error(...args);
  },
  debug: (...args: any[]) => {
    if (isDev) console.debug(...args);
  },
  info: (...args: any[]) => {
    if (isDev) console.info(...args);
  },
  // Group logging for complex objects
  group: (label: string, fn: () => void) => {
    if (isDev) {
      console.group(label);
      fn();
      console.groupEnd();
    }
  }
};

// For API routes (Node.js environment)
export const serverLogger = {
  log: (...args: any[]) => {
    if (process.env.NODE_ENV !== 'production') console.log(...args);
  },
  warn: (...args: any[]) => {
    if (process.env.NODE_ENV !== 'production') console.warn(...args);
  },
  error: (...args: any[]) => {
    console.error(...args);
  },
  debug: (...args: any[]) => {
    if (process.env.NODE_ENV !== 'production') console.debug(...args);
  }
};
