import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = fileURLToPath(new URL('../../', import.meta.url));
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_EXECUTABLE || '/usr/bin/chromium' });
let checks = 0, saves = 0;
const check = (ok, label) => { assert(ok, label); checks++; console.log('PASS ' + label); };
try {
  for (const mode of ['integrated', 'standalone']) {
    const origin = mode === 'integrated' ? 'http://127.0.0.1:8111' : 'http://127.0.0.1:8107';
    const fixture = JSON.parse(await readFile(root + '.local/browser-tests/page-hierarchy-' + mode + '.json', 'utf8'));
    const errors = [], external = [];
    const context = await browser.newContext();
    const restrict = async (client) => client.route('**/*', r => new URL(r.request().url()).origin === origin ? r.continue() : (external.push(r.request().url()), r.abort()));
    await restrict(context);
    const page = await context.newPage(); page.on('pageerror', e => errors.push(e.message));
    const credentials = mode === 'integrated' ? JSON.parse(await readFile(root + '.local/native-cms-integration/integrated-manager-runtime/browser-credentials.json', 'utf8')) : null;
    const login = async (clientPage, role = 'admin') => {
      if (mode === 'standalone') return;
      await clientPage.goto(origin + '/login/');
      await clientPage.locator('[name=email]').fill(credentials[role].email);
      await clientPage.locator('[name=password]').fill(credentials[role].password);
      await Promise.all([clientPage.waitForURL('**/vereinsverwaltung/**'), clientPage.getByRole('button', { name: 'Anmelden und Vereinsmanager öffnen', exact: true }).click()]);
    };
    await login(page);
    const url = (id, view = 'website') => origin + (mode === 'integrated' ? '/vereinsverwaltung/?' : '/?') + new URLSearchParams(mode === 'integrated' ? { view: 'website', tab: ({ website: 'pages', media: 'gallery', news: 'news', events: 'events' })[view], native_item: id } : { view, item: id });
    const open = async (p, row, view) => { await p.goto(url(row.id, view)); await p.locator('.page-hierarchy-panel summary').click(); return p.locator('.page-hierarchy-panel form'); };
    const submit = async (p, form) => {
      const response = p.waitForResponse(r => r.request().method() === 'POST' && r.request().postData()?.includes('page-place'));
      await form.getByRole('button', { name: 'Anordnung als Entwurf speichern', exact: true }).click();
      const posted = await response;
      await p.waitForLoadState('networkidle');
      if (posted.status() === 200 && mode === 'standalone') {
        const result = await posted.json();
        check(typeof result.html === 'string' && result.html.includes('page-hierarchy-panel') && result.url.startsWith('/?'), mode + ' actual AJAX save returns updated editor');
      } else {
        check(posted.status() === 303, mode + ' actual saved placement redirects');
      }
      saves++;
    };
    const publicBefore = {};
    const publicUrl = row => origin + '/site/' + row.slug + (mode === 'integrated' ? '/' : '');
    for (const [view, row] of Object.entries(fixture.source)) {
      const response = await context.request.get(publicUrl(row));
      check(response.status() === 200, mode + ' published original ' + view);
      publicBefore[view] = await response.body();
    }
    for (const width of [1440, 820, 390]) {
      await page.setViewportSize({ width, height: 1000 });
      for (const [view, row] of Object.entries(fixture.source)) {
        const form = await open(page, row, view);
        check(await form.locator('[name=id]').inputValue() === row.id, mode + ' original editor identity ' + view + ' ' + width);
        const picker = form.locator('[data-reference-picker]');
        await picker.locator('[data-reference-query]').fill(fixture.right.title);
        await picker.locator('[data-reference-action=search]').click();
        await page.waitForFunction(id => document.querySelector('.page-hierarchy-panel select option[value="' + id + '"]'), fixture.right.id);
        await form.locator('[name=hierarchy_parent]').selectOption(fixture.right.id);
        await form.locator('[name=hierarchy_position]').fill(String(width === 1440 ? 30 : width === 820 ? -10 : -40));
        const overflow = await form.evaluate(f => f.getBoundingClientRect().right > innerWidth + 1 || f.scrollWidth > f.clientWidth + 1);
        check(!overflow, mode + ' actual Bootstrap form fits ' + view + ' ' + width);
        await form.getByRole('button', { name: 'Anordnung als Entwurf speichern', exact: true }).focus();
        check(await form.getByRole('button', { name: 'Anordnung als Entwurf speichern', exact: true }).evaluate(b => b === document.activeElement), mode + ' keyboard submit focus ' + view + ' ' + width);
        await submit(page, form);
        const saved = page.locator('.page-hierarchy-panel form');
        check(await saved.locator('[name=hierarchy_parent]').inputValue() === fixture.right.id, mode + ' actual saved new parent ' + view + ' ' + width);
        check(await saved.locator('[name=id]').inputValue() === row.id, mode + ' stays in correct content editor ' + view + ' ' + width);
        const after = await context.request.get(publicUrl(row));
        check((await after.body()).equals(publicBefore[view]), mode + ' exact public original retained ' + view + ' ' + width);
      }
    }
    await page.goto(url(fixture.right.id));
    for (const row of Object.values(fixture.source)) check(await page.locator('[data-child-page="' + row.id + '"]').count() === 1, mode + ' new parent lists actual saved child');
    await page.goto(url(fixture.left.id));
    for (const row of Object.values(fixture.source)) check(await page.locator('[data-child-page="' + row.id + '"]').count() === 0, mode + ' former parent no longer lists moved child');

    // An actual stale second tab cannot replace work from the first tab.
    const row = fixture.source.website, second = await context.newPage();
    const stale = await open(second, row, 'website');
    const originalVersion = await stale.locator('[name=version]').inputValue();
    const fresh = await open(page, row, 'website'); await fresh.locator('[name=hierarchy_position]').fill('51'); await submit(page, fresh);
    await stale.locator('[name=hierarchy_position]').fill('52');
    const conflict = second.waitForResponse(r => r.request().method() === 'POST');
    await stale.getByRole('button', { name: 'Anordnung als Entwurf speichern' }).click();
    check((await conflict).status() === (mode === 'standalone' ? 409 : 422), mode + ' stale original rejected through real HTTP');
    await second.waitForLoadState('networkidle');
    check(await second.locator('.page-hierarchy-panel').getAttribute('open') !== null, mode + ' conflict correction stays open');
    check(await second.locator('.page-hierarchy-panel [name=version]').inputValue() === originalVersion, mode + ' failed form cannot silently adopt newer version');
    check(await second.locator('.page-hierarchy-panel [name=hierarchy_position]').inputValue() === '52', mode + ' proposed order retained after conflict');
    await second.close();

    const actual = await open(page, row, 'website');
    const values = await actual.evaluate(f => Object.fromEntries(new FormData(f)));
    const cycle = await context.request.post(url(row.id), { form: { ...values, hierarchy_parent: fixture.children.website.id } });
    check(cycle.status() === 422, mode + ' forged descendant destination cannot create a cycle');
    const noCsrf = await context.request.post(url(row.id), { form: { ...values, csrf: 'wrong' } });
    check(noCsrf.status() === 422 || noCsrf.status() === 403, mode + ' forged CSRF cannot move original');

    // Native form really works with JavaScript disabled and the same authentication cookies.
    const noJs = await browser.newContext({ javaScriptEnabled: false, storageState: await context.storageState() });
    await restrict(noJs); const basic = await noJs.newPage();
    const basicForm = await open(basic, row, 'website'); await basicForm.locator('[name=hierarchy_position]').fill('60'); await submit(basic, basicForm);
    check(await basic.locator('.page-hierarchy-panel [name=hierarchy_position]').inputValue() === '60', mode + ' actual JavaScript-free form saves exact position');
    await noJs.close();

    if (mode === 'integrated') {
      const memberContext = await browser.newContext(); await restrict(memberContext); const member = await memberContext.newPage(); await login(member, 'member');
      const denied = await member.goto(url(row.id));
      check(denied.status() === 403 || await member.locator('.page-hierarchy-panel').count() === 0, mode + ' member has no hierarchy editor');
      await memberContext.close();
    }
    check(errors.length === 0, mode + ' no browser script errors');
    check(external.length === 0, mode + ' no external browser requests');
    await context.close();
  }
  const result = { suite: 'native-page-hierarchy-browser', checks, saves, widths: [1440, 820, 390], external_actions: 0 };
  await writeFile(root + '.local/browser-tests/page-hierarchy-result.json', JSON.stringify(result) + '\n', { mode: 0o600 });
  console.log(JSON.stringify(result));
} finally { await browser.close(); }
