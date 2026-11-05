'use strict';
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require(process.env.KH_PLAYWRIGHT_MODULE || 'playwright');
const origin = process.env.KH_NATIVE_BROWSER_ORIGIN || 'http://127.0.0.1:8111';
if (origin !== 'http://127.0.0.1:8111') throw new Error('Own loopback browser fixture required.');
const root = path.resolve(__dirname, '../..');
const runtime = path.join(root, '.local/native-cms-integration/integrated-manager-runtime');
const credentials = JSON.parse(fs.readFileSync(path.join(runtime, 'browser-credentials.json'))).admin;
const fixture = JSON.parse(fs.readFileSync(path.join(runtime, 'pos-browser.json')));
const out = path.join(root, '.local/cloud-results'); fs.mkdirSync(out, {recursive: true});
let checks = 0;
function check(value, label) {if (!value) throw new Error(label); checks++; console.log(`PASS ${label}`);}
(async () => {
  const browser = await chromium.launch({headless: true});
  try {
    const context = await browser.newContext();
    await context.route('**/*', route => {const url = new URL(route.request().url()); if (url.origin !== origin) return route.abort(); return route.continue();});
    const page = await context.newPage(); const errors = []; page.on('pageerror', e => errors.push(e.message));
    await page.goto(`${origin}/login/`);
    await page.locator('input[name=email]').fill(credentials.email); await page.locator('input[name=password]').fill(credentials.password);
    await Promise.all([page.waitForURL('**/vereinsverwaltung/**'), page.locator('button').filter({hasText: 'Anmelden'}).click()]);
    for (const width of [1440, 820, 390]) {
      await page.setViewportSize({width, height: 1000});
      const response = await page.goto(`${origin}/vereinsverwaltung/?view=pos&shift=${fixture.shift}`);
      check(response.status() === 200, `POS HTTP 200 at ${width}`);
      check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `no horizontal overflow at ${width}`);
      check(await page.locator('[data-product]').count() >= 2, `populated customizable article buttons at ${width}`);
      await page.locator('[data-product]').filter({hasText: 'Testgetränk'}).click(); await page.locator('[data-product]').filter({hasText: 'Testgetränk'}).click();
      check(await page.locator('#pos-cart').innerText().then(text => text.includes('2 × Testgetränk') && text.includes('6,00')), `touch cart quantity and total at ${width}`);
      await page.screenshot({path: path.join(out, `pos-${width}.png`), fullPage: true});
    }
    await page.locator('#pos-save-cart').click(); await page.waitForLoadState('domcontentloaded');
    await page.locator('input[name=cash]').fill('6.00');await page.locator('input[name=received]').fill('10.00');
    await page.getByRole('button', {name: 'Zahlungsaufteilung übernehmen'}).click();await page.waitForLoadState('domcontentloaded');
    check(await page.getByRole('link', {name: 'Originalbeleg anzeigen & drucken'}).count() === 1, 'cash sale creates actual original receipt');
    await page.getByRole('link', {name: 'Originalbeleg anzeigen & drucken'}).click();await page.waitForLoadState('domcontentloaded');
    const text = await page.locator('.pos-receipt').innerText();check(text.includes('6,00') && text.includes('4,00') && text.includes('Testgetränk'), 'frozen receipt contains goods and correct change');
    await page.screenshot({path: path.join(out, 'pos-receipt.png'), fullPage: true});
    check(errors.length === 0, 'no browser script errors in populated POS workflow');
    console.log(`${checks} POS browser checks passed; synthetic cash only, all external requests blocked.`);
  } finally {await browser.close();}
})().catch(error => {console.error(error.message); process.exitCode = 1;});
