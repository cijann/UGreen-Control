import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';
import { fileURLToPath, URL } from 'node:url';
import { readFileSync } from 'node:fs';

export default defineConfig(({ mode }) => {
  const root = fileURLToPath(new URL('.', import.meta.url));
  const unraid = (process.env.VITE_PLATFORM ?? loadEnv(mode, root, '').VITE_PLATFORM) === 'unraid';
  return {
    base: unraid ? '/plugins/ugreen-control/www/' : './',
    plugins: [react(), ...(unraid ? [{
      name: 'unraid-assets',
      transformIndexHtml(html: string) {
        return html.replace(/<base href="[^"]*"\s*\/>/, '<base href="/plugins/ugreen-control/www/" />');
      },
      generateBundle() {
        this.emitFile({ type: 'asset', fileName: 'images/logo.png', source: readFileSync(new URL('../App.Native.UGreenLED/app/www/images/logo.png', import.meta.url)) });
      },
    } satisfies import('vite').Plugin] : [])],
    resolve: {
      alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
    },
    build: {
      outDir: fileURLToPath(new URL(unraid ? './dist' : '../App.Native.UGreenLED/app/www', import.meta.url)),
      emptyOutDir: unraid,
      sourcemap: false,
    },
  };
});
