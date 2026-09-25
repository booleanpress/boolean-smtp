import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { resolve } from 'path';
import { hotFilePlugin } from './vite/hot-file.js';

export default defineConfig({
    plugins: [react(), tailwindcss(), hotFilePlugin({ outDir: resolve(import.meta.dirname, '../public') })],
    esbuild: { jsx: 'automatic' },
    root: resolve(import.meta.dirname),
    base: './',
    publicDir: false,
    resolve: {
        alias: {
            '@': resolve(import.meta.dirname, './src'),
        },
    },
    build: {
        outDir: resolve(import.meta.dirname, '../public'),
        emptyOutDir: true,
        manifest: 'manifest.json',
        rollupOptions: {
            input: resolve(import.meta.dirname, 'src/main.jsx'),
            output: {
                entryFileNames: 'assets/[name]-[hash].js',
                chunkFileNames: 'assets/[name]-[hash].js',
                assetFileNames: 'assets/[name]-[hash].[ext]',
                manualChunks(id) {
                    // Framework + design-system runtime in one long-lived chunk; match package
                    // names, not substrings (lucide-react must not land here).
                    if (/node_modules\/(\.pnpm\/)?(react|react-dom|react-router|scheduler|radix-ui|@radix-ui)[@/]/.test(id)) {
                        return 'vendor';
                    }
                }
            },
        },
        sourcemap: false,
    },
    server: {
        host: '127.0.0.1',
        origin: 'http://127.0.0.1:5173',
        port: 5173,
        strictPort: true,
        cors: true,
    },
    test: {
        environment: 'jsdom',
        globals: true,
        setupFiles: ['./src/test/setup.js'],
        css: false,
    },
});
