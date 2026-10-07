import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/pages/welcome.css',
                'resources/js/app.tsx',
            ],
            refresh: true,
        }),
        react({ babel: { plugins: ['babel-plugin-react-compiler'] } }),
        tailwindcss(),
    ],
    server: { host: '127.0.0.1' },
    build: { target: 'es2022' },
});
