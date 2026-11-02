import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { fileURLToPath, URL } from 'node:url';
import { readFileSync } from 'node:fs';

export default defineConfig(() => {
  return {
    base: '/plugins/ugreen-control/www/',
    plugins: [
      react(),
      {
        name: 'unraid-assets',
        transformIndexHtml(html: string) {
          return html.replace(/<base href="[^"]*"\s*\/>/, '<base href="/plugins/ugreen-control/www/" />');
        },
        generateBundle() {
          const logoPath = new URL('./public/images/logo.png', import.meta.url);
          this.emitFile({ type: 'asset', fileName: 'images/logo.png', source: readFileSync(logoPath) });
        },
      },
    ],
    resolve: {
      alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
    },
    build: {
      outDir: fileURLToPath(new URL('./dist', import.meta.url)),
      emptyOutDir: true,
      sourcemap: false,
    },
  };
});
