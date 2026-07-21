/**
 * Bundle único (splitting: false): os arquivos de `app/` viram um `dist/App.js`.
 * Por isso o sourcemap não é opcional — sem ele a stack trace de produção
 * aponta `App.js:1234` em vez do arquivo/linha do fonte. O `pnpm start` roda
 * com `--enable-source-maps` para o Node consumir o `.map`.
 */

import { defineConfig } from 'tsup';

export default defineConfig({
    entry: ['app/App.ts'],
    format: ['esm'],
    platform: 'node',
    target: 'node22',
    outDir: 'dist',
    clean: true,
    splitting: false,
    dts: false,
    minify: false,
    sourcemap: true,
});
