import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import path from 'path';

export default defineConfig({
    plugins: [
        vue(),
    ],
    build: {
        outDir: 'dist',
        assetsDir: '',
        emptyOutDir: false,
        chunkSizeWarningLimit: 2000,
        rolldownOptions: {
            input: 'resources/js/app.ts',
            output: {
                entryFileNames: 'app.js',
                chunkFileNames: '[name].js',
                assetFileNames: 'app.[ext]',
            },
        },
    },
    resolve: {
        alias: {
            '@': path.resolve(import.meta.dirname, 'resources/js'),
        },
    },
});
