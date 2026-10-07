import { existsSync } from 'node:fs';
import { mkdir, readFile, readdir, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { zipSync } from 'fflate';

const root = path.resolve(import.meta.dirname, '..');
const sourceOnly = process.argv.includes('--source-only');
for (const file of ['public/build/manifest.json', 'public/build/homepage.html', 'public/build/homepage-head.html', 'public/build/consultation.html', 'public/build/consultation-head.html']) {
    if (!existsSync(path.join(root, file))) throw new Error('Run npm run build first: missing ' + file);
}
if (!sourceOnly && !existsSync(path.join(root, 'vendor/autoload.php'))) throw new Error('Composer vendor is missing. Use the owner PHP runner to install dependencies; --source-only creates a non-runnable handoff.');
const entries = {};
async function add(local, archived) {
    const source = path.join(root, local);
    const stat = (await import('node:fs/promises')).lstat;
    if ((await stat(source)).isSymbolicLink()) throw new Error('Symlink excluded: ' + local);
    if ((await stat(source)).isDirectory()) {
        for (const item of await readdir(source)) await add(path.posix.join(local, item), path.posix.join(archived, item));
    } else {
        if (path.basename(local) === '.env') throw new Error('Environment secrets must never be packaged.');
        entries[archived] = new Uint8Array(await readFile(source));
    }
}
for (const item of ['app', 'config', 'routes', 'resources/views', 'artisan', 'composer.json', 'composer.lock', '.env.example', 'README.md', 'AGENTS.md', 'docs']) await add(item, 'findward-app/' + item);
for (const item of ['bootstrap/app.php', 'bootstrap/providers.php']) await add(item, 'findward-app/' + item);
for (const dir of ['bootstrap/cache', 'storage/app/private', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/consultation', 'storage/framework/views', 'storage/logs']) entries['findward-app/' + dir + '/.gitignore'] = new TextEncoder().encode('*\n!.gitignore\n');
if (!sourceOnly) await add('vendor', 'findward-app/vendor');
for (const item of ['build', '.htaccess', 'robots.txt']) await add('public/' + item, 'public_html/' + item);
const controller = await readFile(path.join(root, 'public/index.php'), 'utf8');
const marker = '$appRoot = dirname(__DIR__);';
if (!controller.includes(marker)) throw new Error('Front-controller layout changed; review the package path.');
entries['public_html/index.php'] = new TextEncoder().encode(controller.replace(marker, "$appRoot = dirname(__DIR__).'/findward-app';"));
entries['DEPLOYMENT_STATUS.txt'] = new TextEncoder().encode(sourceOnly ? 'SOURCE-ONLY HANDOFF. NOT RUNNABLE: Composer vendor is absent. Do not upload as a ready site. See findward-app/docs/HOSTINGER.md. No credentials or runtime state included.\n' : 'Prepared package with locked dependencies and build assets. Hosting, private configuration and activation still require owner approval. No credentials/runtime state included.\n');
const output = path.join(root, 'deployment', sourceOnly ? 'findward-hostinger-source-only.zip' : 'findward-hostinger.zip');
await mkdir(path.dirname(output), { recursive: true });
await writeFile(output, zipSync(entries, { level: 6 }));
console.log((sourceOnly ? 'SOURCE-ONLY (Composer pending): ' : 'Prepared package: ') + output);
console.log(Object.keys(entries).length + ' files; private application/public_html separated; no environment secrets or runtime state.');
