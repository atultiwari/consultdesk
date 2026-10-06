import { defineConfig } from 'vite';

// Builds dist/embed.js: a single self-running script for other sites to include.
export default defineConfig({
  build: {
    outDir: 'dist',
    emptyOutDir: false,
    lib: {
      entry: 'src/embed/embed.ts',
      formats: ['iife'],
      name: 'ConsultDeskEmbed',
      fileName: () => 'embed.js',
    },
  },
});
