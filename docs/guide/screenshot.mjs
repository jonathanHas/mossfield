#!/usr/bin/env node
// Regenerates the user-guide screenshots in docs/guide/images from docs/guide/screenshots.json.
//
//   node docs/guide/screenshot.mjs             # every shot
//   node docs/guide/screenshot.mjs order-page  # only shots whose name contains "order-page"
//
// Dev box only: it signs in to the local site with the seeded test accounts
// (password admin123, or GUIDE_PASSWORD) and drives the installed Google Chrome
// headless through puppeteer-core. Each shot is taken at a fixed viewport so a
// re-run after a UI change produces the same crop.
//
// Per shot: name, as, url, width, height, scale, mobile, fullPage, clip,
// waitFor, before.
//
// - url may hold placeholders filled from docs/guide/demo-data.json (written by
//   demo-data.php): {GUIDE-A.id}, {GUIDE-A.date}, {GUIDE-A.number} and
//   {GUIDE-A.item.1L Bottle} (the order line's id). A placeholder with no value
//   fails that shot rather than shooting the wrong order.
// - before is a list of actions run after the page loads and before the
//   capture, each one of {"select": css, "value": v}, {"type": css, "text": t},
//   {"click": css}, {"waitFor": css}, {"wait": ms}. A click that submits a form
//   or follows a link should be followed by a waitFor on the next page.
// - blur is a list of CSS selectors blurred for the capture only (customers'
//   email addresses and phone numbers — the pictures are committed and shown
//   to every role that can open Help).
// - fullPage shots get the sticky sidebar un-stuck for the capture, so it runs
//   the full height of the picture instead of ending after one viewport.

import { readFile, mkdir } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import path from 'node:path';
import puppeteer from 'puppeteer-core';

const manifestPath = process.env.GUIDE_MANIFEST ?? 'docs/guide/screenshots.json';
const chrome = process.env.GUIDE_CHROME ?? '/usr/bin/google-chrome';
const password = process.env.GUIDE_PASSWORD ?? 'admin123';
const demoPath = process.env.GUIDE_DEMO_DATA ?? 'docs/guide/demo-data.json';
const filter = process.argv[2] ?? '';

// The layout's sidebar is the one <aside> with h-screen (product pages have a
// second, inner aside). Un-stuck, it stretches with the flex row it sits in.
const fullPageCss = 'aside.h-screen { position: static !important; height: auto !important; min-height: 100% !important; }';

const demo = existsSync(demoPath) ? JSON.parse(await readFile(demoPath, 'utf8')) : null;

function fillPlaceholders(url) {
    return url.replace(/\{(GUIDE-[A-Z])\.([^}]+)\}/g, (whole, ref, key) => {
        const order = demo?.orders?.[ref];
        const value = key.startsWith('item.') ? order?.items?.[key.slice(5)] : order?.[key];
        if (value === undefined || value === null || typeof value === 'object') {
            const why = demo ? `${demoPath} has no value for it` : `${demoPath} is missing`;
            throw new Error(`placeholder ${whole}: ${why} — run "php artisan tinker docs/guide/demo-data.php"`);
        }
        return String(value);
    });
}

async function runBefore(page, action) {
    if ('select' in action) {
        await page.waitForSelector(action.select);
        const chosen = await page.select(action.select, String(action.value));
        if (chosen.length === 0) throw new Error(`before: "${action.select}" has no option "${action.value}"`);
    } else if ('type' in action) {
        await page.waitForSelector(action.type);
        await page.type(action.type, String(action.text));
    } else if ('click' in action) {
        await page.waitForSelector(action.click);
        await page.click(action.click);
    } else if ('waitFor' in action) {
        await page.waitForSelector(action.waitFor, { visible: true });
    } else if ('wait' in action) {
        await new Promise((resolve) => setTimeout(resolve, action.wait));
    } else {
        throw new Error(`before: unknown action ${JSON.stringify(action)}`);
    }
}

const manifest = JSON.parse(await readFile(manifestPath, 'utf8'));
const base = manifest.base.replace(/\/$/, '');
const outDir = manifest.out;
await mkdir(outDir, { recursive: true });

const shots = manifest.shots.filter((s) => s.name.includes(filter));
if (shots.length === 0) {
    console.error(`No shot matches "${filter}".`);
    process.exit(1);
}

const browser = await puppeteer.launch({
    executablePath: chrome,
    headless: true,
    args: ['--hide-scrollbars', '--force-device-scale-factor=1'],
});

// One signed-in page per account, created on first use.
const pages = new Map();

async function pageFor(role) {
    if (pages.has(role)) return pages.get(role);
    const username = manifest.accounts[role];
    if (!username) throw new Error(`No account configured for role "${role}".`);

    const context = await browser.createBrowserContext();
    const page = await context.newPage();
    await page.goto(`${base}/login`, { waitUntil: 'networkidle0' });
    await page.type('#login', username);
    await page.type('#password', password);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.click('button[type=submit]'),
    ]);
    if (page.url().endsWith('/login')) {
        throw new Error(`Login failed for ${username} — check the password (GUIDE_PASSWORD).`);
    }
    pages.set(role, page);
    return page;
}

let failed = 0;
for (const shot of shots) {
    const file = path.join(outDir, `${shot.name}.png`);
    try {
        const page = await pageFor(shot.as ?? 'office');
        await page.setViewport({
            width: shot.width ?? 1280,
            height: shot.height ?? 800,
            deviceScaleFactor: shot.scale ?? 1.5,
            isMobile: shot.mobile ?? false,
            hasTouch: shot.mobile ?? false,
        });
        const url = fillPlaceholders(shot.url);
        const response = await page.goto(`${base}${url}`, { waitUntil: 'networkidle0' });
        if (!response || !response.ok()) {
            throw new Error(`HTTP ${response?.status() ?? '?'} for ${url}`);
        }
        await page.evaluate(() => document.fonts.ready);
        if (shot.waitFor) await page.waitForSelector(shot.waitFor);

        for (const action of shot.before ?? []) {
            await runBefore(page, action);
        }
        if (shot.before?.length) {
            await page.waitForNetworkIdle({ idleTime: 300 }).catch(() => {});
            await page.evaluate(() => document.fonts.ready);
        }
        if (shot.fullPage) await page.addStyleTag({ content: fullPageCss });
        if (shot.blur?.length) {
            for (const selector of shot.blur) {
                if (!(await page.$(selector))) throw new Error(`blur: nothing matches "${selector}"`);
            }
            await page.addStyleTag({ content: `${shot.blur.join(', ')} { filter: blur(7px) !important; }` });
        }

        if (shot.clip) {
            const el = await page.$(shot.clip);
            if (!el) throw new Error(`Nothing matches clip selector "${shot.clip}"`);
            await el.screenshot({ path: file });
        } else {
            await page.screenshot({ path: file, fullPage: shot.fullPage ?? false });
        }
        console.log(`✓ ${file}`);
    } catch (err) {
        failed++;
        console.error(`✗ ${shot.name}: ${err.message}`);
    }
}

await browser.close();
process.exit(failed ? 1 : 0);
