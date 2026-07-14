import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import {ViteImageOptimizer} from 'vite-plugin-image-optimizer';

export default defineConfig({
    build: {
        rollupOptions: {
            output: {
                manualChunks(id) {
                    if (id.includes('prosemirror')) {
                        return 'vendor-prosemirror';
                    }
                },
            },
        },
    },
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            valetTls: 'inventario.test',
        }),
        tailwindcss(),
        process.env.NODE_ENV === 'production' && ViteImageOptimizer({
            png: { quality: 70 },
            jpg: { quality: 70 },
            jpeg: { quality: 70 },
            webp: { quality: 75 },
            gif: { optimizationLevel: 3 },
        })
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
