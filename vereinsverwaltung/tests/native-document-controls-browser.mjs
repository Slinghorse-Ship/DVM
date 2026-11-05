import assert from 'node:assert/strict';
import {readFile, writeFile} from 'node:fs/promises';
import {createHash} from 'node:crypto';
import {spawnSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
const {chromium} = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = fileURLToPath(new URL('../../', import.meta.url)), origin = 'http://127.0.0.1:8111';
const fixture = JSON.parse(await readFile(root + '.local/browser-tests/document-controls.json', 'utf8'));
const credentials = JSON.parse(await readFile(root + '.local/native-cms-integration/integrated-manager-runtime/browser-credentials.json', 'utf8'));
const browser = await chromium.launch({executablePath: process.env.CHROMIUM_EXECUTABLE || '/usr/bin/chromium', args: ['--no-sandbox']});
let checks = 0;
const errors = [], external = [], writes = [], staged = [];
const check = (ok, label) => { assert(ok, label); ++checks; console.log('PASS ' + label); };
async function client(role, javaScriptEnabled = true, state = null) {
  let storageState = state;
  if (!storageState && process.env.DOCUMENT_CONTROLS_STORAGE_DIR) storageState = JSON.parse(await readFile(process.env.DOCUMENT_CONTROLS_STORAGE_DIR + '/' + role + '.json', 'utf8'));
  const context = await browser.newContext({storageState: storageState || undefined, hasTouch: true, javaScriptEnabled});
  await context.route('**/*', route => {
    const request = route.request();
    if (new URL(request.url()).origin !== origin) { external.push(request.url()); return route.abort(); }
    if (request.method() === 'POST' && new URL(request.url()).pathname !== '/login/') {
      const data = request.postData() || '';
      writes.push(new URLSearchParams(data).get('action') || data.match(/name="action"\r\n\r\n([^\r\n]+)/)?.[1] || 'unknown');
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
async function audit(page, label) {
  const result = await page.evaluate(() => {
    const fields = [...document.querySelectorAll('main :is(input,select,textarea)')].filter(node => node.type !== 'hidden');
    const visible = node => node.checkVisibility() && !node.closest('[hidden]');
    return {
      fields: fields.length,
      missing: fields.filter(node => !node.classList.contains(['checkbox', 'radio'].includes(node.type) ? 'check' : 'form-control')).map(node => node.name),
      small: [...document.querySelectorAll('main button, main a.btn')].filter(visible).filter(node => { const b = node.getBoundingClientRect(); return b.width < 43.5 || b.height < 43.5; }).map(node => node.textContent.trim()),
      font: fields.filter(visible).filter(node => ['text', 'date', 'search', 'select-one', 'textarea'].includes(node.type) && parseFloat(getComputedStyle(node).fontSize) < 16).map(node => node.name),
      unnamed: fields.filter(visible).filter(node => node.getAttribute('aria-hidden') !== 'true' && !node.labels?.length && !node.getAttribute('aria-label') && !node.getAttribute('aria-labelledby')).map(node => node.name),
      smallChecks: fields.filter(visible).filter(node => ['checkbox', 'radio'].includes(node.type) && node.closest('label')?.getBoundingClientRect().height < 44).map(node => node.name),
      overflow: document.documentElement.scrollWidth > innerWidth + 2,
    };
  });
  check(result.fields > 5, label + ' contains actual upload/edit/source fields');
  check(result.missing.length === 0, label + ' has server Bootstrap controls: ' + result.missing.join(','));
  check(result.small.length === 0, label + ' has usable actual touch actions: ' + result.small.join(','));
  check(result.font.length === 0, label + ' has readable field text: ' + result.font.join(','));
  check(result.unnamed.length === 0, label + ' has accessible field labels: ' + result.unnamed.join(','));
  check(result.smallChecks.length === 0, label + ' has large labeled checkbox targets: ' + result.smallChecks.join(','));
  check(!result.overflow, label + ' stays inside viewport');
}
try {
  const admin = await client('admin');
  const sourceFile = await admin.context.request.get(origin + '/vereinsverwaltung/?document_file=' + fixture.document);
  assert.equal(sourceFile.status(), 200);
  const sourceBytes = await sourceFile.body();
  for (const javascript of [true, false]) {
    const {context, page} = javascript ? admin : await client('admin', false, await admin.context.storageState());
    for (const width of [1440, 820, 390]) {
      await page.setViewportSize({width, height: 1050});
      await page.goto(origin + '/vereinsverwaltung/?view=documents&new_document=1&source_type=appointment&source_id=' + fixture.event);
      await page.locator('[name=title]').waitFor({state: 'attached'});
      let upload;
      if (javascript) {
        await page.locator('[name="document_files[]"]').setInputFiles({name: 'Eigene-Formularauswahl.txt', mimeType: 'text/plain', buffer: sourceBytes});
        await page.locator('.live-upload-list .upload-ready').waitFor();
        upload = {context, token: await page.locator('[name="live_uploads[document_files][]"]').inputValue(), csrf: await page.locator('form.doc-form [name=csrf]').inputValue(), endpoint: await page.locator('form.doc-form').getAttribute('action') || page.url(), removed: false};
        staged.push(upload);
        check(true, 'actual own temporary upload reaches ready state ' + width);
      }
      await audit(page, `original-source upload ${width} JS=${javascript}`);
      check(await page.locator(`input[type=checkbox][value="appointment:${fixture.event}"]`).isChecked(), 'upload retains actual original source selection ' + width + ' JS=' + javascript);
      check(await page.locator('[name=kind]').locator('option[value=record]').count() === 0, 'upload cannot impersonate a generated original ' + width + ' JS=' + javascript);
      if (upload) {
        await page.locator('.live-upload-list').getByRole('button', {name: 'Entfernen', exact: true}).tap();
        await page.waitForFunction(() => !document.querySelector('.live-upload-list li'));
        upload.removed = true;
        check(true, 'actual touch cancellation removes own temporary upload ' + width);
      }
      await page.goto(origin + '/vereinsverwaltung/?view=documents&doc=' + fixture.document + '&edit_archive=1');
      await page.locator('#dokument-status').waitFor();
      await audit(page, `populated original metadata ${width} JS=${javascript}`);
      check(await page.locator('#dokument-status [name=title]').inputValue() === 'Eigene Dokumentformular-Prüfung', 'actual original title retained ' + width + ' JS=' + javascript);
      check(await page.locator('#dokument-status [name=document_date]').inputValue() === '2026-10-25', 'actual original date retained ' + width + ' JS=' + javascript);
      check(await page.locator(`#dokument-status [name="links[]"][value="appointment:${fixture.event}"]`).isChecked(), 'edit form retains actual original source binding ' + width + ' JS=' + javascript);
      const sourceChoice = page.locator(`#dokument-status [name="links[]"][value="appointment:${fixture.event}"]`);
      await sourceChoice.evaluate(node => { node.closest('details').open = true; });
      await sourceChoice.focus();
      await page.keyboard.press('Space');
      check(!await sourceChoice.isChecked() && await sourceChoice.evaluate(node => document.activeElement === node), 'actual original source is keyboard selectable ' + width + ' JS=' + javascript);
      await page.keyboard.press('Space');
      check(await sourceChoice.isChecked(), 'keyboard restores original source without saving ' + width + ' JS=' + javascript);
      await page.screenshot({path: root + `.local/browser-tests/document-controls-${width}-${javascript ? 'js' : 'no-js'}.png`, fullPage: false});
    }
    if (!javascript) await context.close();
  }
  await admin.page.evaluate(() => document.documentElement.style.zoom = '2');
  check(await admin.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 2), 'actual populated original form fits two-hundred-percent zoom');
  check(await admin.page.locator('.topbar .breadcrumb').evaluate(node => {
    const header = node.closest('header').getBoundingClientRect(), text = node.getBoundingClientRect();
    return text.width > 80 && text.bottom <= header.bottom + 1;
  }), 'zoomed club name stays readable inside the manager header');
  const original = await admin.context.request.get(origin + '/vereinsverwaltung/?document_file=' + fixture.document);
  check(original.status() === 200 && createHash('sha256').update(await original.body()).digest('hex') === fixture.sha256, 'actual protected download retains exact original file bytes');
  check(original.headers()['x-content-type-options'] === 'nosniff', 'original download preserves its protected content contract');
  const member = await client('member');
  check((await member.context.request.get(origin + '/vereinsverwaltung/?document_file=' + fixture.document)).status() === 403, 'actual member receives no protected original file');
  check(errors.length === 0 && external.length === 0 && writes.length === 6 && writes.filter(action => action === 'live-upload-stage').length === 3 && writes.filter(action => action === 'live-upload-remove').length === 3, 'actual writes are only three owned temporary uploads and their cancellations');
  const proof = spawnSync(process.env.PHP_BINARY || 'php', [root + 'vereinsverwaltung/tests/support/document-controls-browser-fixture.php', 'check'], {cwd: root, env: {...process.env, KH_NATIVE_TEST_CONFIG: root + '.local/native-cms-integration/integrated-manager.json'}, encoding: 'utf8'});
  check(proof.status === 0, 'post-browser original records, links and byte proof succeeds');
  await writeFile(root + '.local/browser-tests/document-controls-result.json', JSON.stringify({checks, widths: [1440, 820, 390], javascript: [true, false], temporary_uploads: 3, cancelled_uploads: 3, business_writes: 0, external_actions: 0}) + '\n', {mode: 0o600});
  console.log(JSON.stringify({checks, temporary_uploads: 3, cancelled_uploads: 3, business_writes: 0, external_actions: 0}));
} finally {
  for (const upload of staged.filter(upload => !upload.removed)) {
    const result = await upload.context.request.post(new URL(upload.endpoint, origin).href, {form: {action: 'live-upload-remove', csrf: upload.csrf, token: upload.token}});
    assert(result.ok(), 'owned temporary upload cleanup succeeded');
  }
  await browser.close();
}
