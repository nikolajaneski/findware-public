// Built public UI only. All booking requests are mocks; external network and writes are blocked.
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import http from 'node:http';
import path from 'node:path';
import { chromium } from 'playwright';

const root = path.resolve(import.meta.dirname, '../..');
const build = path.join(root, 'public/build');
const manifest = JSON.parse(await readFile(path.join(build, 'manifest.json'), 'utf8'));
const html = await readFile(path.join(build, 'homepage.html'), 'utf8');
const head = await readFile(path.join(build, 'homepage-head.html'), 'utf8');
assert.match(head, /personally managed client acquisition/);
const css = [...new Set([manifest['resources/css/app.css'].file, manifest['resources/js/pages/welcome.css'].file, ...(manifest['resources/js/app.tsx'].css || [])])];
const app = manifest['resources/js/app.tsx'].file;
const bookingHtml = await readFile(path.join(build, 'consultation.html'), 'utf8');
const bookingHead = await readFile(path.join(build, 'consultation-head.html'), 'utf8');
assert.match(bookingHtml, /Free 20-minute consultation/);
assert.doesNotMatch(bookingHtml, /id="hero-title"/);
assert.match(bookingHead, /Free 20-minute consultation - findward/);
const pageData = (url) => ({ component: 'welcome', props: url === '/consultation' ? { consultation: true } : {}, url, version: null, clearHistory: false, encryptHistory: false });
const fixture = (url) => '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' + (url === '/consultation' ? bookingHead : head) + css.map(file => '<link rel="stylesheet" href="/build/' + file + '">').join('') + '<script type="module" src="/build/' + app + '"></script></head><body class="font-sans antialiased"><script data-page="app" type="application/json">' + JSON.stringify(pageData(url)) + '</script><div data-server-rendered="true" id="app">' + (url === '/consultation' ? bookingHtml : html) + '</div></body></html>';
const server = http.createServer(async (request, response) => {
    try {
        const url = new URL(request.url, 'http://localhost');
        if (['/', '/approach', '/criteria', '/examples', '/consultation'].includes(url.pathname)) {
            if (request.headers['x-inertia'] === 'true') {
                response.setHeader('X-Inertia', 'true');
                response.setHeader('Content-Type', 'application/json');
                return response.end(JSON.stringify(pageData(url.pathname)));
            }
            response.setHeader('Content-Type', 'text/html; charset=utf-8');
            return response.end(fixture(url.pathname));
        }
        const file = path.resolve(root, 'public', '.' + decodeURIComponent(url.pathname));
        if (!file.startsWith(build + path.sep)) { response.writeHead(404); return response.end(); }
        response.setHeader('Content-Type', file.endsWith('.js') ? 'application/javascript' : file.endsWith('.css') ? 'text/css' : 'application/octet-stream');
        response.end(await readFile(file));
    } catch { response.writeHead(404); response.end(); }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const origin = 'http://127.0.0.1:' + server.address().port;
const browser = await chromium.launch({ channel: process.env.FINDWARD_BROWSER_CHANNEL || 'msedge', headless: true });
let cases = 0;
try {
    for (const scenario of [{ width: 1180, zone: 'Europe/Skopje', label: '12:00 GMT+2' }, { width: 390, zone: 'America/New_York', label: '06:00 GMT-4' }, { width: 1180, zone: 'Europe/Skopje', label: '12:00 GMT+2', uuidMode: 'fallback' }, { width: 1180, zone: 'Europe/Skopje', label: '12:00 GMT+2', uuidMode: 'unavailable' }, { width: 1180, zone: 'Europe/Skopje', label: '12:00 GMT+2', bookingMode: 'csrf' }, { width: 1180, zone: 'Europe/Skopje', label: '12:00 GMT+2', bookingMode: 'uncertain' }, { width: 1180, zone: 'Europe/Skopje', label: '12:00 GMT+2', bookingMode: 'missing' }]) {
        const context = await browser.newContext({ viewport: { width: scenario.width, height: 900 }, timezoneId: scenario.zone, colorScheme: 'dark', serviceWorkers: 'block' });
        const posts = [], errors = [];
        let record;
        await context.route('**/*', async route => {
            const request = route.request(), url = new URL(request.url());
            if (url.origin !== origin) return route.abort();
            const reply = (body, status = 200) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });
            if (url.pathname === '/consultation/settings') return reply({ configured: true, timezone: 'Europe/Skopje', duration_minutes: 20, first_date: '2026-10-06', last_date: '2026-11-05' });
            if (url.pathname === '/consultation/availability') return reply({ date: url.searchParams.get('date'), timezone: 'Europe/Skopje', duration_minutes: 20, slots: url.searchParams.get('date') === '2026-10-06' ? [{ start: '2026-10-06T10:00:00Z', end: '2026-10-06T10:20:00Z', label: 'Owner fixture label' }] : [] });
            if (url.pathname === '/consultation/bookings' && request.method() === 'POST') {
                const data = request.postDataJSON(); posts.push(data);
                if (scenario.bookingMode === 'csrf') return reply({ message: 'CSRF token mismatch.' }, 419);
                if (scenario.bookingMode === 'uncertain') return reply({}, 500);
                record = { reference: data.idempotency_key, retry_reference: 'SIGNED-OFFLINE-FIXTURE', status: 'confirmed', start: data.start, end: '2026-10-06T10:20:00Z', duration_minutes: 20, timezone: 'Europe/Skopje', meeting_url: 'https://meet.google.com/fixture' };
                return reply(record, 201);
            }
            if (url.pathname.startsWith('/consultation/bookings/')) {
                if (scenario.bookingMode === 'missing' || scenario.bookingMode === 'uncertain') return reply({ code: 'booking_not_found', message: 'The booking reference could not be verified. Keep it and contact us before making another booking.' }, 404);
                assert.equal(request.headers()['x-consultation-reference'], 'SIGNED-OFFLINE-FIXTURE');
                return reply(record);
            }
            if (url.pathname.startsWith('/consultation/') || request.method() !== 'GET') return route.abort();
            if (url.pathname.endsWith('.js')) await new Promise(resolve => setTimeout(resolve, 700));
            return route.continue();
        });
        const page = await context.newPage();
        page.on('pageerror', error => errors.push(error.message));
        await page.addInitScript(({ uuidMode, bookingMode }) => {
            if (bookingMode === 'missing' && !sessionStorage.getItem('lgp:consultation:request')) sessionStorage.setItem('lgp:consultation:request', JSON.stringify({ key: '11111111-1111-4111-8111-111111111111', start: '2026-10-06T10:00:00Z' }));
            if (uuidMode) Object.defineProperty(crypto, 'randomUUID', { value: undefined, configurable: true });
            if (uuidMode === 'unavailable') Object.defineProperty(crypto, 'getRandomValues', { value: undefined, configurable: true });
            const OriginalDate = Date;
            window.Date = class extends OriginalDate {
                constructor(...args) { if (args.length) super(...args); else super('2026-10-06T06:00:00Z'); }
                static now() { return OriginalDate.parse('2026-10-06T06:00:00Z'); }
            };
            localStorage.setItem('appearance', 'dark');
            window.paintSamples = [];
            const sample = () => {
                const root = document.querySelector('.lgp-public'), nav = document.querySelector('.lgp-nav');
                if (root && nav && performance.getEntriesByType('paint').some(p => p.name === 'first-contentful-paint')) window.paintSamples.push({ background: getComputedStyle(root).backgroundColor, nav: getComputedStyle(nav).display, scheme: getComputedStyle(document.documentElement).colorScheme });
                if (window.paintSamples.length < 180) requestAnimationFrame(sample);
            };
            requestAnimationFrame(sample);
        }, { uuidMode: scenario.uuidMode, bookingMode: scenario.bookingMode });
        await page.goto(origin);
        assert.equal(await page.locator('.lgp-faq details').count(), 6);
        assert.equal(await page.locator('a[href="/login"], a[href="/dashboard"]').count(), 0);
        await page.waitForFunction(() => window.paintSamples.length > 0);
        const samples = await page.evaluate(() => window.paintSamples);
        assert.ok(samples.length);
        for (const sample of samples) { assert.equal(sample.background, 'rgb(249, 251, 248)'); assert.equal(sample.nav, 'flex'); assert.equal(sample.scheme, 'light'); }
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
        assert.equal(await page.locator('a[href="/consultation"]').count(), 6);
        assert.equal(await page.locator('a[href="#consultation"]').count(), 0);
        await page.locator('#nav-discuss-campaign').click();
        await page.getByRole('heading', { name: 'Book your free consultation', exact: true }).waitFor();
        assert.equal(new URL(page.url()).pathname, '/consultation');
        assert.equal(new URL(page.url()).hash, '');
        assert.equal(await page.title(), 'Free 20-minute consultation - findward');
        await page.goBack();
        await page.locator('#nav-discuss-campaign').waitFor();
        assert.equal(new URL(page.url()).pathname, '/');
        await page.goForward();
        await page.getByRole('heading', { name: 'Book your free consultation', exact: true }).waitFor();
        assert.equal(new URL(page.url()).pathname, '/consultation');
        assert.match(await page.locator('.lgp-booking-timezone').innerText(), new RegExp(scenario.zone.replaceAll('_', ' ')));
        assert.equal(await page.locator('select').count(), 0);
        await page.getByRole('button', { name: 'Back to homepage', exact: true }).click();
        await page.waitForFunction(() => document.activeElement?.id === 'nav-discuss-campaign');
        assert.equal(new URL(page.url()).pathname, '/');
        assert.equal(await page.title(), 'Tailored campaigns to find your next clients - findward');
        await page.locator('#criteria-discuss-campaign').click();
        if (scenario.bookingMode === 'missing') {
            await page.getByRole('heading', { name: 'Checking your booking', exact: true }).waitFor();
            await page.getByRole('button', { name: 'Check booking status', exact: true }).click();
            await page.getByRole('alert').filter({ hasText: 'The booking reference could not be verified.' }).waitFor();
            assert.ok(await page.evaluate(() => sessionStorage.getItem('lgp:consultation:request')));
            assert.equal(await page.getByRole('button', { name: 'Confirm free consultation', exact: true }).count(), 0);
            assert.equal(posts.length, 0);
            assert.deepEqual(errors, []);
            await context.close(); cases++;
            continue;
        }
        await page.getByRole('button', { name: scenario.label, exact: true }).click();
        await page.getByLabel('Name', { exact: true }).fill('Offline Fixture');
        await page.getByLabel('Email', { exact: true }).fill('fixture@example.test');
        await page.getByLabel(/I agree to share/).check();
        await page.getByRole('button', { name: 'Confirm free consultation', exact: true }).click();
        if (scenario.uuidMode === 'unavailable') {
            await page.getByText('This browser cannot create a secure booking reference. Please use a current browser.', { exact: true }).waitFor();
            assert.equal(posts.length, 0);
            assert.equal(await page.getByRole('button', { name: 'Confirm free consultation', exact: true }).isEnabled(), true);
            assert.deepEqual(errors, []);
            await context.close(); cases++;
            continue;
        }
        if (scenario.bookingMode === 'csrf') {
            await page.getByRole('alert').filter({ hasText: 'Your session expired. Reload this page before trying again.' }).waitFor();
            assert.equal(await page.evaluate(() => sessionStorage.getItem('lgp:consultation:request')), null);
            assert.equal(await page.getByRole('button', { name: 'Confirm free consultation', exact: true }).isEnabled(), true);
            assert.equal(posts.length, 1);
            await page.reload();
            await page.getByRole('heading', { name: 'Book your free consultation', exact: true }).waitFor();
            assert.equal(await page.getByRole('heading', { name: 'Checking your booking', exact: true }).count(), 0);
            assert.equal(await page.evaluate(() => sessionStorage.getItem('lgp:consultation:request')), null);
            assert.equal(posts.length, 1, 'A CSRF rejection must not cause an automatic retry');
            assert.deepEqual(errors, []);
            await context.close(); cases++;
            continue;
        }
        if (scenario.bookingMode === 'uncertain') {
            await page.getByRole('heading', { name: 'Checking your booking', exact: true }).waitFor();
            const saved = await page.evaluate(() => sessionStorage.getItem('lgp:consultation:request'));
            assert.ok(saved);
            await page.reload();
            await page.getByRole('heading', { name: 'Checking your booking', exact: true }).waitFor();
            assert.equal(await page.evaluate(() => sessionStorage.getItem('lgp:consultation:request')), saved);
            assert.equal(posts.length, 1, 'An uncertain write must not cause another POST');
            assert.equal(await page.getByRole('button', { name: 'Confirm free consultation', exact: true }).count(), 0);
            assert.deepEqual(errors, []);
            await context.close(); cases++;
            continue;
        }
        await page.getByRole('heading', { name: 'Your consultation is confirmed', exact: true }).waitFor();
        assert.equal(posts.length, 1);
        assert.match(posts[0].idempotency_key, /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
        assert.equal(posts[0].start, '2026-10-06T10:00:00Z');
        await page.reload();
        await page.getByRole('heading', { name: 'Your consultation is confirmed', exact: true }).waitFor();
        assert.equal(new URL(page.url()).pathname, '/consultation');
        assert.equal(await page.title(), 'Free 20-minute consultation - findward');
        assert.equal(posts.length, 1, 'Recovery must not submit another booking');
        assert.deepEqual(errors, []);
        await context.close(); cases++;
    }
    const context = await browser.newContext({ serviceWorkers: 'block' });
    const errors = [];
    await context.route('**/*', route => {
        const request = route.request(), url = new URL(request.url());
        if (url.origin !== origin || request.method() !== 'GET') return route.abort();
        const reply = body => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(body) });
        if (url.pathname === '/consultation/settings') return reply({ configured: true, timezone: 'Europe/Skopje', duration_minutes: 20, first_date: '2026-10-07', last_date: '2026-11-05' });
        if (url.pathname === '/consultation/availability') return reply({ date: url.searchParams.get('date'), duration_minutes: 20, slots: [] });
        if (url.pathname.startsWith('/consultation/')) return route.abort();
        return route.continue();
    });
    const page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(origin + '/consultation');
    await page.getByRole('heading', { name: 'Book your free consultation', exact: true }).waitFor();
    assert.equal(await page.title(), 'Free 20-minute consultation - findward');
    await page.reload();
    await page.getByRole('heading', { name: 'Book your free consultation', exact: true }).waitFor();
    await page.getByRole('button', { name: 'Back to homepage', exact: true }).click();
    await page.locator('#nav-discuss-campaign').waitFor();
    assert.equal(new URL(page.url()).pathname, '/');
    await page.goBack();
    await page.getByRole('heading', { name: 'Book your free consultation', exact: true }).waitFor();
    assert.equal(new URL(page.url()).pathname, '/consultation');
    await page.goForward();
    await page.locator('#nav-discuss-campaign').waitFor();
    for (const section of ['approach', 'criteria', 'examples']) {
        await page.goto(origin + '/' + section);
        await page.waitForFunction(id => Math.abs(document.getElementById(id).getBoundingClientRect().top) < 2, section);
        await page.reload();
        await page.waitForFunction(id => Math.abs(document.getElementById(id).getBoundingClientRect().top) < 2, section);
        assert.equal(new URL(page.url()).hash, '');
    }
    await page.goto(origin);
    await page.locator('.lgp-nav a[href="/criteria"]').click();
    await page.waitForFunction(() => Math.abs(document.getElementById('criteria').getBoundingClientRect().top) < 2);
    assert.equal(new URL(page.url()).pathname, '/criteria');
    await page.locator('#faq-talk').click();
    await page.getByRole('heading', { name: 'Book your free consultation', exact: true }).waitFor();
    assert.equal(new URL(page.url()).pathname, '/consultation');
    await page.goto(origin + '/#consultation');
    await page.getByRole('heading', { name: 'Book your free consultation', exact: true }).waitFor();
    assert.equal(new URL(page.url()).pathname, '/consultation');
    assert.equal(new URL(page.url()).hash, '');
    assert.deepEqual(errors, []);
    await context.close(); cases++;
    console.log('PASS: ' + cases + ' built UI cases; delayed-JS first paint, light theme, desktop/mobile, FAQ, CTAs/back focus, visitor timezone, UTC submission/reload recovery, secure UUID fallback, no-randomness error cleanup, definite CSRF rejection cleanup, uncertain/missing-reference retention, clean consultation CTAs/direct GET/refresh/back/forward/titles and legacy hash replacement. No live provider calls.');
} finally {
    await browser.close();
    await new Promise(resolve => server.close(resolve));
}
