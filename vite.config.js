import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'node:path';

// Two-entry build:
//   - admin/  → React SPA for the visual facet builder
//   - public/ → Vanilla(ish) runtime that renders facets on the frontend
//
// Both compile to assets/dist/<name>/ with hashed filenames + a manifest.json,
// which PHP reads at enqueue time to resolve the latest built asset URLs.
export default defineConfig({
    plugins: [react()],

    // Disable Vite's static-copy directory. Its default is `public/`, which
    // collides with our runtime-source directory — without this, every file
    // under public/src/ ships into dist twice (once bundled, once verbatim).
    publicDir: false,

    build: {
        outDir: 'assets/dist',
        emptyOutDir: true,
        manifest: true,
        sourcemap: true,
        rollupOptions: {
            input: {
                admin: resolve(__dirname, 'admin/src/main.jsx'),
                public: resolve(__dirname, 'public/src/main.js'),
            },
            output: {
                entryFileNames: '[name]/[name].[hash].js',
                chunkFileNames: '[name]/chunks/[name].[hash].js',
                assetFileNames: '[name]/[name].[hash][extname]',
            },
        },
    },

    server: {
        port: 5173,
        strictPort: true,
        cors: true,
        // When the WP container is on http://localhost:8080, HMR origin needs
        // to match so the browser can connect back to Vite from the WP page.
        hmr: { host: 'localhost' },
    },
});
