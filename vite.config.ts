import path from 'path';
import fs from 'fs';
import { defineConfig, loadEnv, Plugin } from 'vite';
import tailwindcss from '@tailwindcss/vite';

function copyHtaccess(): Plugin {
  return {
    name: 'copy-htaccess',
    closeBundle() {
      fs.copyFileSync(
        path.resolve(__dirname, 'public/.htaccess'),
        path.resolve(__dirname, 'dist/.htaccess')
      );
    }
  };
}

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, '.', '');
    return {
      server: {
        port: 3000,
        host: '0.0.0.0',
      },
      plugins: [tailwindcss(), copyHtaccess()],
      define: {
        'process.env.API_KEY': JSON.stringify(env.GEMINI_API_KEY),
        'process.env.GEMINI_API_KEY': JSON.stringify(env.GEMINI_API_KEY)
      },
      resolve: {
        alias: {
          '@': path.resolve(__dirname, '.'),
        }
      }
    };
});
