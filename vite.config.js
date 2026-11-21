import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { google } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            // Fonts are downloaded at build time and served from this app,
            // so visitors never send a request to a third-party font host.
            fonts: [
                // Inter Variable (Desain.md). One variable file covers the 400/510/590
                // weights the design system uses; it is fetched once at build time.
                google('Inter', {
                    alias: 'sans',
                    weights: ['400..700'],
                }),
                // Martian Mono (Desain2.md, SIL Open Font License): metadata and tag labels only.
                google('Martian Mono', {
                    alias: 'martian',
                    weights: ['400'],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
