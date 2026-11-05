import assert from 'node:assert/strict';
import {readFile, writeFile} from 'node:fs/promises';
import {spawnSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const {chromium} = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = fileURLToPath(new URL('../../', import.meta.url));
const origin = 'http://127.0.0.1:8111';
const fixture = JSON.parse(await readFile(root + '.local/browser-tests/hse-measures.json', 'utf8'));
const credentials = JSON.parse(await readFile(root + '.local/native-cms-integration/integrated-manager-runtime/browser-credentials.json', 'utf8'));
const browser = await chromium.launch({executablePath: process.env.CHROMIUM_EXECUTABLE || '/usr/bin/chromium', args: ['--no-sandbox']});
const errors = [], external = [], writes = [];
let checks = 0;
const check = (ok, label) => { assert(ok, label); ++checks; console.log('PASS ' + label); };
const docURL = origin + '/vereinsverwaltung/?view=documents&tab=workflows&wf_doc=' + fixture.document;
const calendarURL = date => origin + '/vereinsverwaltung/?view=calendar&mode=agenda&calendar_filter=workflows&date=' + date;
const name = (index, column) => `[name="wf_values[hazards][${index}][${column}]"]`;
const ownedLink = page => page.locator('.calendar-agenda a').filter({hasText: 'Eigene Maßnahmenfrist-Browserbeurteilung'});
async function client(role, javaScriptEnabled = true, state = null) {
  let storageState = state;
  if (!storageState && process.env.HSE_MEASURES_STORAGE_DIR) {
    storageState = JSON.parse(await readFile(process.env.HSE_MEASURES_STORAGE_DIR + '/' + role + '.json', 'utf8'));
  }
  const context = await browser.newContext({storageState: storageState || undefined, hasTouch: true, javaScriptEnabled});
  await context.route('**/*', route => {
    if (new URL(route.request().url()).origin !== origin) { external.push(route.request().url()); return route.abort(); }
    const request = route.request();
    if (request.method() === 'POST' && !new URL(request.url()).pathname.startsWith('/login')) {
      const data = new URLSearchParams(request.postData());
      writes.push({action: data.get('action'), document: data.get('wf_id')});
    }
    return route.continue();
  });
  const page = await context.newPage();
  page.on('pageerror', error => errors.push(error.message));
  if (!storageState) {
    await page.goto(origin + '/login/');
    await page.locator('[name=email]').fill(credentials[role].email);
    await page.locator('[name=password]').fill(credentials[role].password);
    await Promise.all([page.waitForURL('**/vereinsverwaltung/**'), page.getByRole('button', {name: 'Anmelden und Vereinsmanager öffnen', exact: true}).click()]);
  }
  return {context, page};
}
async function save(page, expected) {
  await Promise.all([page.waitForNavigation(), page.getByRole('button', {name: 'Entwurf speichern', exact: true}).click()]);
  await page.locator('.dwf-document-form').waitFor();
  check(Number(await page.locator('.dwf-document-form [name=wf_version]').inputValue()) === expected, 'actual form save reaches original revision ' + expected);
}
async function fits(page, label) {
  check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 2), label + ' stays inside viewport');
}
try {
  const {context, page} = await client('admin');
  for (const width of [1440, 820, 390]) {
    await page.setViewportSize({width, height: 1050});
    const response = await page.goto(docURL);
    check(response.ok(), 'actual authenticated populated HSE document ' + width);
    check(await page.locator(name(0, 'action_deadline')).inputValue() === fixture.date, 'original structured due date ' + width);
    check(await page.getByLabel('Zeile 1: Erledigt am', {exact: true}).inputValue() === '', 'open measure has no invented completion ' + width);
    await fits(page, 'populated HSE table ' + width);
    check(await page.locator('[data-dwf-register-rows] input[type=date]').count() === 4, 'each original row has both typed date controls ' + width);
    check(await page.locator('[data-dwf-register-rows] input[type=date], [data-dwf-register-rows] textarea').evaluateAll(nodes => nodes.every(node => parseFloat(getComputedStyle(node).fontSize) >= 16)), 'original row fields have sixteen-pixel text ' + width);
    const date = page.locator(name(0, 'action_deadline'));
    await date.scrollIntoViewIfNeeded();
    check(await date.evaluate(node => node.getBoundingClientRect().height >= 44), 'typed date target has forty-four-pixel height ' + width);
    const down = page.locator('[data-dwf-register-row]').first().locator('[data-dwf-register-move=down]');
    await down.scrollIntoViewIfNeeded();
    check(await down.evaluate(node => { const box = node.getBoundingClientRect(); return box.width >= 44 && box.height >= 44; }), 'actual row action has forty-four-pixel touch target ' + width);
    check((await page.locator('.dwf-live-preview').innerText()).includes('Maßnahmenfrist: ' + fixture.date), 'actual live print preview includes due date ' + width);
  }
  const stale = await context.newPage();
  stale.on('pageerror', error => errors.push(error.message));
  await stale.goto(docURL);
  const down = page.locator('[data-dwf-register-row]').first().locator('[data-dwf-register-move=down]');
  await down.focus();
  await page.keyboard.press('Enter');
  check(await page.locator(name(1, 'action_deadline')).inputValue() === fixture.date, 'keyboard row move retains exact due date with the original hazard');
  check(await page.locator(name(1, 'hazard')).inputValue() === 'Eigene Kabelprüfung', 'moving a dated row retains its original hazard');
  check(await page.getByLabel('Zeile 2: Maßnahmenfrist', {exact: true}).count() === 1, 'row move updates the accessible date label');
  await page.locator('[data-dwf-register-add]').tap();
  check(await page.locator('[data-dwf-register-row]').count() === 3, 'actual touch creates one private draft row');
  check(await page.getByLabel('Zeile 3: Maßnahmenfrist', {exact: true}).inputValue() === '', 'cloned row starts with an empty typed date');
  await page.locator(name(2, 'hazard')).fill('Eigene ergänzte Gefährdung');
  await page.locator(name(2, 'action_deadline')).fill(fixture.next_date);
  check((await page.locator('.dwf-live-preview').innerText()).includes('Maßnahmenfrist: ' + fixture.next_date), 'changing a dynamic date updates the actual print preview');
  await save(page, fixture.revision + 1);
  check(await page.locator(name(1, 'action_deadline')).inputValue() === fixture.date && await page.locator(name(2, 'action_deadline')).inputValue() === fixture.next_date, 'real saved form retains moved and added row dates');

  await stale.locator(name(0, 'action_deadline')).fill('2026-10-28');
  await Promise.all([stale.waitForNavigation(), stale.getByRole('button', {name: 'Entwurf speichern', exact: true}).click()]);
  const conflict = stale.getByRole('alert').filter({hasText: 'Änderung nicht gespeichert'});
  await conflict.waitFor();
  check(await conflict.count() === 1, 'real stale-tab save is rejected');
  check(await stale.locator(name(0, 'action_deadline')).inputValue() === '2026-10-28', 'failed form retains the proposed date for correction');
  check(Number(await stale.locator('.dwf-document-form [name=wf_version]').inputValue()) === fixture.revision, 'conflict form retains its originally submitted version');
  await page.reload();
  check(await page.locator(name(1, 'action_deadline')).inputValue() === fixture.date, 'stale proposal leaves actual current original date unchanged');
  await page.locator(name(1, 'action_completed_on')).fill('2026-10-26');
  check((await page.locator('.dwf-live-preview').innerText()).includes('Erledigt am: 2026-10-26'), 'completion date appears in the live original preview');
  await save(page, fixture.revision + 2);
  await page.goto(calendarURL(fixture.date));
  check(await ownedLink(page).count() === 1, 'actual calendar retains one independent document review');
  check(!(await ownedLink(page).innerText()).includes('Maßnahmenfrist ·') && (await ownedLink(page).innerText()).includes('Nächster Prüftermin'), 'actual completed measure leaves calendar while document review remains');
  await page.goto(calendarURL(fixture.next_date));
  check(await ownedLink(page).count() === 1 && (await ownedLink(page).innerText()).includes('Zeile 3'), 'actual next-month calendar contains the newly added typed measure');

  const without = await client('admin', false, await context.storageState());
  await without.page.setViewportSize({width: 390, height: 1050});
  await without.page.goto(docURL);
  check(await without.page.locator(name(2, 'action_deadline')).inputValue() === fixture.next_date, 'JavaScript-free original form has actual current date');
  await without.page.locator(name(2, 'action_deadline')).fill('2026-11-07');
  await save(without.page, fixture.revision + 3);
  check(await without.page.locator(name(2, 'action_deadline')).inputValue() === '2026-11-07', 'JavaScript-free form saves the new typed date');
  await fits(without.page, 'JavaScript-free populated HSE document');

  for (const width of [1440, 820, 390]) {
    await page.setViewportSize({width, height: 1050});
    await page.goto(calendarURL('2026-11-07'));
    check(await ownedLink(page).count() === 1, 'one canonical action entry at ' + width);
    const target = new URL(await ownedLink(page).getAttribute('href'), origin);
    check(target.searchParams.get('wf_doc') === fixture.document && Number(target.searchParams.get('wf_revision')) === fixture.revision + 3, 'action entry pins actual latest original revision at ' + width);
    await ownedLink(page).focus();
    await page.keyboard.press('Enter');
    await page.locator('.dwf-document-form').waitFor();
    check(await page.locator(name(2, 'action_deadline')).inputValue() === '2026-11-07' && await page.locator(name(2, 'action_deadline')).isDisabled(), 'keyboard calendar link opens exact protected original at ' + width);
    await fits(page, 'protected original ' + width);
  }
  await page.goto(docURL + '&wf_revision=4');
  check(await page.locator(name(0, 'action_deadline')).inputValue() === fixture.date && await page.locator(name(0, 'action_completed_on')).inputValue() === '', 'old approved document retains original open measure');
  check(await page.locator(name(0, 'action_deadline')).isDisabled(), 'approved original dates remain read-only');
  await page.evaluate(() => document.documentElement.style.zoom = '2');
  await fits(page, 'original document at two-hundred-percent zoom');
  const member = await client('member');
  const denied = await member.context.request.get(docURL + '&wf_revision=4');
  check(denied.status() === 403, 'actual current member cannot read original HSE date fields');
  check(errors.length === 0, 'no browser script errors');
  check(external.length === 0, 'no external provider calls');
  check(writes.length === 4 && writes.every(write => write.action === 'document-workflow-document-save' && write.document === fixture.document), 'all actual browser writes stay inside the explicitly owned draft');
  const proof = spawnSync(process.env.PHP_BINARY || 'php', [root + 'vereinsverwaltung/tests/support/hse-measures-browser-fixture.php', 'check'], {
    cwd: root, env: {...process.env, KH_NATIVE_TEST_CONFIG: root + '.local/native-cms-integration/integrated-manager.json'}, encoding: 'utf8',
  });
  check(proof.status === 0, 'post-browser original-byte proof succeeds');
  await writeFile(root + '.local/browser-tests/hse-measures-result.json', JSON.stringify({checks, widths: [1440, 820, 390], owned_saves: 3, rejected_stale_save: 1, external_actions: 0}) + '\n', {mode: 0o600});
  console.log(JSON.stringify({checks, external_actions: 0}));
} finally { await browser.close(); }
