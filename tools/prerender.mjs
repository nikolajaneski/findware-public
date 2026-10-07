import { mkdir, writeFile } from 'node:fs/promises';
import { build } from 'vite';
import { pathToFileURL } from 'node:url';
import path from 'node:path';

// Use the production JSX transform on both sides of hydration.
// Rendering runs no effects, booking requests or provider calls.
await build({
    build: {
        ssr: 'resources/js/app.tsx',
        outDir: 'bootstrap/prerender',
        emptyOutDir: true,
        rollupOptions: {
            input: 'resources/js/app.tsx',
            output: { entryFileNames: 'app.mjs' },
        },
    },
});
process.env.NODE_ENV = 'production';
const { renderToString } = await import('react-dom/server');
const module = await import(
    pathToFileURL(path.resolve('bootstrap/prerender/app.mjs')).href
);
const render = await module.default;
if (typeof render !== 'function')
    throw new Error('Missing Inertia build-time renderer.');
await mkdir('public/build', { recursive: true });
for (const page of [
    { name: 'homepage', url: '/', props: {} },
    {
        name: 'consultation',
        url: '/consultation',
        props: { consultation: true },
    },
]) {
    const result = await render(
        {
            component: 'welcome',
            props: page.props,
            url: page.url,
            version: null,
            clearHistory: false,
            encryptHistory: false,
        },
        renderToString,
    );
    const marker = '<div data-server-rendered="true" id="app">';
    const start = result.body.indexOf(marker);
    if (start < 0 || !result.body.endsWith('</div>'))
        throw new Error('Unexpected Inertia HTML wrapper.');
    const html = result.body.slice(start + marker.length, -6);
    if (!html.includes('lgp-public') || !html.includes('findward'))
        throw new Error('Homepage markup missing.');
    await writeFile('public/build/' + page.name + '.html', html);
    await writeFile(
        'public/build/' + page.name + '-head.html',
        result.head.join('\n'),
    );
}
console.log(
    'Built production homepage and consultation HTML/metadata; no server Node runtime required.',
);
