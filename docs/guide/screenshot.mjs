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

import { readFile, mkdir } from 'node:fs/promises';
import path from 'node:path';
import puppeteer from 'puppeteer-core';

const manifestPath = process.env.GUIDE_MANIFEST ?? 'docs/guide/screenshots.json';
const chrome = process.env.GUIDE_CHROME ?? '/usr/bin/google-chrome';
const password = process.env.GUIDE_PASSWORD ?? 'admin123';
const filter = process.argv[2] ?? '';

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
        const response = await page.goto(`${base}${shot.url}`, { waitUntil: 'networkidle0' });
        if (!response || !response.ok()) {
            throw new Error(`HTTP ${response?.status() ?? '?'} for ${shot.url}`);
        }
        await page.evaluate(() => document.fonts.ready);
        if (shot.waitFor) await page.waitForSelector(shot.waitFor);

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
