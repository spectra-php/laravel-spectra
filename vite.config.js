import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import path from 'path';

// Two builds, because the layouts inline each file into a single script, which
// can't import a chunk two entries would share: `app` is the dashboard, and
// `viewer` (--mode viewer) is the page viewers of a shared dashboard get, with
// none of the admin screens. The shared page reuses app.css.
export default defineConfig(({ mode }) => ({
    plugins: [
        vue(),
    ],
    build: {
        outDir: 'dist',
        assetsDir: '',
        emptyOutDir: false,
        chunkSizeWarningLimit: 2000,
        rolldownOptions: {
            input: mode === 'viewer' ? 'resources/js/viewer.ts' : 'resources/js/app.ts',
            output: {
                entryFileNames: mode === 'viewer' ? 'viewer.js' : 'app.js',
                chunkFileNames: '[name].js',
                assetFileNames: mode === 'viewer' ? 'viewer.[ext]' : 'app.[ext]',
            },
        },
    },
    resolve: {
        alias: {
            '@': path.resolve(import.meta.dirname, 'resources/js'),
        },
    },
}));
