import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
        VitePWA({
            strategies: 'injectManifest',
            srcDir: 'resources/js',
            filename: 'sw.js',
            outDir: 'public',
            injectManifest: {
                injectionPoint: 'self.__WB_MANIFEST',
                globDirectory: 'public',
                globPatterns: ['**/*.{js,css,html,ico,png,svg,woff,woff2}'],
                maximumFileSizeToCacheInBytes: 5000000,
            },
            manifest: false
        })
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
