import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            fonts: [
                // Inter matches the PlateRate reference design. Weights 700/800 must be
                // real files: post titles (font-bold) and the brand wordmark
                // (font-extrabold) rendered as smeared synthetic bold without them.
                // Inter also covers Cyrillic, which Instrument Sans lacked entirely —
                // the ru/bg locales were silently falling back to the system font.
                bunny('Inter', {
                    weights: [400, 500, 600, 700, 800],
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
