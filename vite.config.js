import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    // Emit ASCII-only JS/CSS (non-ASCII as \u / \ escapes) so text never turns into
    // mojibake like "\u00e2\u20ac\u201d" when a browser or server mis-detects the file charset.
    esbuild: {
        charset: 'ascii',
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
