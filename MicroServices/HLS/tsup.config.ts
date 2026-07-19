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
});
