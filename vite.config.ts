import path from 'path';
import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, '.', '');
  
  return {
    server: {
      port: 3000,
      host: '0.0.0.0',
      proxy: {
        '/api': {
          target: 'https://op-target-sales-hub.vercel.app',
          changeOrigin: true,
          secure: true,
        }
      }
    },
    // Local development: FORGE_API_BASE_URL=http://localhost:8081
    plugins: [
      react({
        jsxRuntime: 'automatic',
      })
    ],
    esbuild: {
      jsxDev: false,
    },
    build: {
      minify: 'esbuild',
      sourcemap: false,
    },
    define: {
      'process.env.NODE_ENV': JSON.stringify(mode)
    },
    resolve: {
      alias: {
        '@': path.resolve(__dirname, '.'),
      }
    }
  };
});

