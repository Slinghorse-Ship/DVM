/* Browser DOM tests with intercepted fixtures only: no server, DB, or PushManager calls. */
const { chromium } = require(process.env.KH_PLAYWRIGHT_MODULE || 'C:/Users/wehla/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const source = fs.readFileSync(path.resolve(__dirname, '../native/Web/assets/chat.js'), 'utf8');
let checks = 0;
const check = (ok, label) => { assert.ok(ok, label); checks++; };
const thread = 'a'.repeat(32), device = 'b'.repeat(32);
async function fixture(browser, markup) {
  const page = await browser.newPage();
  await page.route('**/*', route => route.fulfill({ contentType: 'text/html', body: '<!doctype html><title>Lokaler Test</title>' + markup }));
  await page.goto('http://127.0.0.1:8199/');
  await page.evaluate(async ({ thread, device }) => {
    localStorage.setItem('kh-native-chat-synthetic-device', device);
    localStorage.setItem('kh-native-chat-synthetic-enabled', '1');
    const hash = [...new Uint8Array(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(device)))].map(b => b.toString(16).padStart(2, '0')).join('');
    window.__state = { stamp: 'one', events: [], actor_id: 'synthetic', threads: [{ id: thread, unread: 0 }], notifications: { enabled: true, devices: [{ device_key: hash, enabled: 1 }] } };
    window.__requests = []; window.__notices = []; window.__permission = 0; window.__httpStatus = 200;
    window.Notification = class { static permission = 'granted'; static requestPermission() { window.__permission++; return Promise.resolve('granted'); } constructor(title, options) { window.__notices.push({ title, options }); } close() {} };
    window.setInterval = callback => { window.__tick = callback; return 1; };
    window.fetch = async url => {
      window.__requests.push(url);
      if (url.startsWith('/chat-status')) return { ok: window.__httpStatus === 200, status: window.__httpStatus, json: async () => structuredClone(window.__state) };
      window.__htmlRequested = true;
      return { ok: true, status: 200, headers: new Headers({'Content-Type':'application/json'}), json: () => new Promise(resolve => { window.__release = resolve; }) };
    };
  }, { thread, device });
  return page;
}
(async () => {
  const browser = await chromium.launch({ headless: true, ...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE ? {executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE} : {}) });
  try {
    const policy = await fixture(browser, '<main>Eigenes anderes Modul</main>');
    await policy.evaluate(() => { __state.notifications.account_devices={linked:true,enabled:false,count:1}; });
    await policy.addScriptTag({ content: source });await policy.waitForTimeout(100);
    await policy.evaluate(thread => { __state.events.push({thread_id:thread,message_seq:101});window.__tick(); },thread);await policy.waitForTimeout(100);
    check(await policy.evaluate(() => __notices.length===0), 'actual account chat opt-out also blocks the current open-browser notice');
    await policy.evaluate(thread => { __state.notifications.account_devices.enabled=true;__state.events.push({thread_id:thread,message_seq:102});window.__tick(); },thread);await policy.waitForTimeout(100);
    check(await policy.evaluate(() => __notices.length===1), 'explicit later account opt-in permits only a new native event');
    await policy.evaluate(() => window.__tick());await policy.waitForTimeout(100);check(await policy.evaluate(() => __notices.length===1), 'account opt-in never replays the skipped original event');await policy.close();
    const global = await fixture(browser, '<main>Ein anderes Modul</main>');
    await global.addScriptTag({ content: source });
    await global.evaluate(() => window.__tick());
    check(await global.evaluate(() => __requests[0] === '/chat-status?item='), 'global polling works outside chat module');
    check(await global.evaluate(() => __permission === 0), 'mount never requests notification permission');
    await global.evaluate(thread => { __state.events = [{ thread_id: thread, message_seq: 1 }]; __state.threads[0].unread = 1; }, thread);
    await global.evaluate(() => __tick());
    check(await global.evaluate(() => __notices.length === 1 && !JSON.stringify(__notices).includes('synthetic')), 'opted in local device receives generic system hint');
    check(await global.title() === '(1) Lokaler Test', 'global document title reflects unread count');
    await global.evaluate(() => __tick());
    check(await global.evaluate(() => __notices.length === 1), 'same pending event is not notified twice');
    await global.evaluate(thread => { __state.notifications.devices[0].enabled = 0; __state.notifications.devices.push({ device_key: 'c'.repeat(64), enabled: 1 }); __state.events.push({ thread_id: thread, message_seq: 2 }); }, thread);
    await global.evaluate(() => __tick());
    check(await global.evaluate(() => __notices.length === 1), 'another enabled device does not reactivate this revoked device');
    await global.evaluate(() => __httpStatus = 403); await global.evaluate(() => __tick());
    const deniedCalls = await global.evaluate(() => __requests.length); await global.evaluate(() => __tick());
    check(await global.evaluate(() => __requests.length) === deniedCalls, 'authorization denial stops polling');
    await global.close();

    const markup = `<div data-native-chat data-thread="${thread}" data-actor="synthetic" data-history="0"><p data-chat-live></p><div data-chat-scroll><div class="messages" data-native-chat-messages><article data-chat-message="1"><textarea aria-label="Alte Nachricht">Alt</textarea></article></div></div><form data-chat-compose><input name="reply_to"><div data-chat-reply-box hidden><span data-chat-reply-label></span></div><textarea name="body" aria-label="Neue Nachricht">Noch nicht gesendeter Text</textarea></form></div>`;
    const race = await fixture(browser, markup);
    await race.addScriptTag({ content: source });
    await race.waitForFunction(() => __requests.length === 1);
    await race.evaluate(() => { __state.stamp = 'two'; window.__pendingTick = __tick(); });
    await race.waitForFunction(() => __htmlRequested === true);
    await race.getByLabel('Alte Nachricht').fill('Wichtig: ungespeichert');
    await race.evaluate(() => __release({ html: '<div data-native-chat-messages><article data-chat-message="1">Neue Serverfassung</article></div>' }));
    await race.evaluate(() => __pendingTick);
    check(await race.getByLabel('Alte Nachricht').inputValue() === 'Wichtig: ungespeichert', 'edit started during fetch is not overwritten');
    check(await race.getByLabel('Neue Nachricht').inputValue() === 'Noch nicht gesendeter Text', 'message refresh preserves composer');
    await race.evaluate(() => { document.activeElement.blur(); document.querySelector('[data-local-edit]').removeAttribute('data-local-edit'); __htmlRequested = false; __pendingTick = __tick(); });
    await race.waitForFunction(() => __htmlRequested === true);
    await race.evaluate(() => __release({ html: '<div data-native-chat-messages><article data-chat-message="2">Neue Serverfassung</article></div>' }));
    await race.evaluate(() => __pendingTick);
    check(await race.locator('[data-native-chat-messages]').innerText() === 'Neue Serverfassung', 'unblocked refresh applies new message history');
    check(await race.getByLabel('Neue Nachricht').inputValue() === 'Noch nicht gesendeter Text', 'successful refresh also preserves unsent composer');
    await race.close();
    console.log(`Native chat client: ${checks} isolated Chromium DOM checks passed; no real server or push requests.`);
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
