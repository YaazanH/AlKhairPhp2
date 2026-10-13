import {
    defineConfig
} from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from "@tailwindcss/vite";

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/css/platform-landing.css', 'resources/css/auth-design.css', 'resources/css/platform-dashboard.css', 'resources/js/app.js', 'resources/js/platform-landing.js'],
            refresh: [`resources/views/**/*`],
        }),
        tailwindcss(),
    ],
    server: {
        host: '127.0.0.1',
        cors: true,
        hmr: {
            host: '127.0.0.1',
        },
    },
});
