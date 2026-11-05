'use strict';
// Real browser, real production scripts, synthetic HTML only. No application DB or mail.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const {chromium} = require('playwright');

const publicDirectory = path.resolve(__dirname, '../public');
const escape = value => String(value).replace(/[&<>"']/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[character]));
const places = {
  '':'Bitte auswählen', free:'Synthetic free storage', partial:'Synthetic partly reserved storage',
  reserved:'Synthetic fully reserved storage', lent:'Synthetic loan only storage',
  blocked:'Synthetic blocked only storage', empty:'Synthetic empty storage',
  never:'Synthetic storage without stock row'
};
const stock = {
  free:{shelf:4,reserved:0,lent:0,blocked:0}, partial:{shelf:5,reserved:3,lent:0,blocked:0},
  reserved:{shelf:3,reserved:3,lent:0,blocked:0}, lent:{shelf:0,reserved:0,lent:3,blocked:0},
  blocked:{shelf:0,reserved:0,lent:0,blocked:4}, empty:{shelf:0,reserved:0,lent:0,blocked:0},
  inactive:{shelf:9,reserved:0,lent:0,blocked:0}
};
const optionHtml = (options, selected) => Object.entries(options).map(([value,label]) => '<option value="'+escape(value)+'"'+(value === selected ? ' selected' : '')+'>'+escape(label)+'</option>').join('');
function html(config) {
  const c = {mode:'receive', selected:'', posted:false, searchable:true, places, stock, ...config};
  // Mirror the server's initial transfer render: eligible places only; no silent POST reassignment.
  const sourcePlaces = c.mode === 'transfer'
    ? Object.fromEntries(Object.entries(c.places).filter(([id]) => !id || Number(c.stock[id]?.shelf || 0) - Number(c.stock[id]?.reserved || 0) > 0))
    : c.places;
  const select = (name, label, options, selected, required = false) => '<label>'+label+'<select name="data['+name+']"'+(required?' required':'')+(c.searchable && ['location','destination'].includes(name)?' data-iv-searchable':'')+'>'+optionHtml(options, selected)+'</select></label>';
  const scripts = c.searchFirst ? ['select-search.js','inventory-stock-change.js'] : ['inventory-stock-change.js','select-search.js'];
  return '<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'+
    '<link rel="stylesheet" href="/select-search.css"><title>Synthetic transfer source regression</title><body><main>'+
    '<section id="inventory-stock-change" data-unit="Satz" data-stock-rows="'+escape(JSON.stringify(c.stock))+'"'+
    ' data-location-options="'+escape(JSON.stringify(c.places))+'" data-move-posted="'+(c.posted?'1':'0')+'" data-original-source="'+escape(c.selected)+'">'+
    '<h1>Menge und Lagerort ändern</h1><form method="post" action="/capture">'+
    '<input type="hidden" name="version" value="7"><input type="hidden" name="request_key" value="1234567890abcdef1234567890abcdef">'+
    select('kind','Vorgang',{receive:'Zugang',consume:'Verbrauch',transfer:'Umlagerung',block:'Sperren',repair:'Reparatur',discard:'Aussonderung',count:'Bestand zählen'},c.mode)+
    select('location','Lagerort / Ausgangsort',sourcePlaces,c.selected,true)+
    select('destination','Zielort',c.places,c.destination || '')+
    '<label>Menge<input required type="number" min="0" name="data[quantity]" value="'+escape(c.quantity || '2')+'"></label>'+
    '<label>Grund<textarea required name="data[reason]">'+escape(c.reason || 'Synthetic existing reason')+'</textarea></label>'+
    '<button type="submit">Lagerbewegung bestätigen</button></form></section></main>'+
    scripts.map(file => '<script defer src="/'+file+'"></script>').join('')+'</body></html>';
}

(async () => {
  let fixture = {}, payload = null;
  const server = http.createServer((request, response) => {
    const pathname = new URL(request.url, 'http://127.0.0.1').pathname;
    response.setHeader('Content-Security-Policy', "default-src 'none'; script-src 'self'; style-src 'self'; form-action 'self'; base-uri 'none'");
    if (pathname === '/capture' && request.method === 'POST') {
      let body = '';
      request.on('data', part => {body += part;});
      request.on('end', () => {payload = Object.fromEntries(new URLSearchParams(body)); response.setHeader('Content-Type','text/html; charset=utf-8'); response.end('<!doctype html><title>Captured synthetic payload</title><p>Browser request captured; no business data saved.</p>');});
      return;
    }
    if (pathname === '/') {response.setHeader('Content-Type','text/html; charset=utf-8'); response.end(html(fixture)); return;}
    if (['/inventory-stock-change.js','/select-search.js','/select-search.css'].includes(pathname)) {
      response.setHeader('Content-Type',pathname.endsWith('.js')?'text/javascript; charset=utf-8':'text/css; charset=utf-8');
      response.end(fs.readFileSync(path.join(publicDirectory, pathname.slice(1)))); return;
    }
    response.statusCode = 404; response.end();
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const base = 'http://127.0.0.1:'+server.address().port;
  const browser = await chromium.launch({headless:true,channel:process.env.KULTURHUS_BROWSER_CHANNEL || 'msedge'});
  const context = await browser.newContext({viewport:{width:1100,height:900}});
  await context.route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
  const page = await context.newPage(), failures = [], checks = [], errors = [];
  page.on('pageerror', error => errors.push(error.message));
  const field = name => page.locator('[name="data['+name+']"]');
  const options = name => field(name).locator('option').evaluateAll(nodes => nodes.filter(node => node.value && !node.disabled && !node.hidden).map(node => node.value));
  const load = async config => {fixture = config; payload = null; await page.goto(base); await page.locator('[data-transfer-guidance]').waitFor({state:'attached'});};
  const changeMode = async mode => {await field('kind').selectOption(mode);};
  const run = async (name, callback) => {
    try {await callback(); checks.push(name); console.log('PASS '+name);}
    catch (error) {failures.push({name,error:error.message}); console.error('FAIL '+name+': '+error.message);}
  };
  try {
    await run('Umlagerung zeigt nur aktive Orte mit frei verfügbarem Bestand', async () => {
      await load({mode:'transfer'});
      assert.deepEqual(await options('location'), ['free','partial']);
      assert.equal(await field('location').inputValue(), '', 'Multiple possible source places require a choice.');
      assert.deepEqual(await options('destination'), Object.keys(places).filter(Boolean));
      assert.equal(await field('destination').isDisabled(), false);
    });
    await run('Ein verbleibender Ausgangsort wird ausgewählt; keine übertragene Mengenänderung', async () => {
      await load({mode:'transfer',stock:{...stock,partial:{...stock.partial,reserved:5}}});
      assert.deepEqual(await options('location'), ['free']);
      assert.equal(await field('location').inputValue(), 'free');
      assert.equal(await field('quantity').inputValue(), '2');
      assert.equal(await field('reason').inputValue(), 'Synthetic existing reason');
    });
    await run('Kein freier Bestand lässt die Pflichtauswahl leer und erklärt den Zustand', async () => {
      await load({mode:'transfer',stock:{...stock,free:{...stock.free,reserved:4},partial:{...stock.partial,reserved:5}}});
      assert.deepEqual(await options('location'), []);
      assert.equal(await field('location').inputValue(), '');
      assert.equal(await field('location').evaluate(node => node.validity.valueMissing), true);
      assert.match(await page.locator('[data-transfer-guidance]').innerText(), /kein|nicht.*verfügbar/i);
      assert.equal(await field('destination').isDisabled(), false);
      assert.deepEqual(await options('destination'), Object.keys(places).filter(Boolean));
    });
    await run('Vorgangswechsel erhält alle Orte für Zugang, Inventur, Reparatur und Aussonderung', async () => {
      await load({mode:'transfer',selected:'partial'});
      for (const mode of ['receive','count','consume','block','repair','discard']) {
        await changeMode(mode);
        assert.deepEqual(await options('location'), Object.keys(places).filter(Boolean), mode+' locations');
        assert.equal(await field('location').inputValue(), 'partial', mode+' legitimate selection retained');
        assert.equal(await field('destination').isDisabled(), true);
      }
      await changeMode('transfer');
      assert.deepEqual(await options('location'), ['free','partial']);
      assert.equal(await field('location').inputValue(), 'partial');
    });
    await run('Vorher gewählter leerer Ort wird bei Umlagerung nicht übernommen', async () => {
      await load({mode:'receive',selected:'empty'});
      await changeMode('transfer');
      assert.equal(await field('location').inputValue(), '');
      assert.deepEqual(await options('location'), ['free','partial']);
      await changeMode('receive');
      await field('location').selectOption('never',{force:true});
      await changeMode('transfer');
      assert.equal(await field('location').inputValue(), '');
    });
    await run('Fehlgeschlagener POST erhält eine gültige Quelle und alle übrigen Eingaben', async () => {
      await load({mode:'transfer',posted:true,selected:'partial',destination:'never',quantity:'3',reason:'Synthetic retained failed submission'});
      assert.equal(await field('location').inputValue(), 'partial');
      assert.equal(await field('destination').inputValue(), 'never');
      assert.equal(await field('quantity').inputValue(), '3');
      assert.equal(await field('reason').inputValue(), 'Synthetic retained failed submission');
      assert.equal(await page.locator('[name="version"]').inputValue(), '7');
      assert.equal(await page.locator('[name="request_key"]').inputValue(), '1234567890abcdef1234567890abcdef');
    });
    await run('Fehlgeschlagener POST ersetzt eine inzwischen unzulässige Quelle niemals still', async () => {
      await load({mode:'transfer',posted:true,selected:'empty',stock:{...stock,partial:{...stock.partial,reserved:5}},quantity:'3',reason:'Synthetic retry reason'});
      assert.deepEqual(await options('location'), ['free']);
      assert.equal(await field('location').inputValue(), '', 'Do not silently redirect a failed movement to another warehouse.');
      assert.match(await page.locator('[data-transfer-guidance]').innerText(), /auswählen|erneut|nicht mehr/i);
      // Typing a corrected amount/reason must not later trigger the suppressed auto-selection.
      await field('quantity').fill('1'); await field('reason').fill('Synthetic corrected retry reason');
      assert.equal(await field('location').inputValue(), '');
      assert.equal(await field('reason').inputValue(), 'Synthetic corrected retry reason');
    });
    await run('Fehlgeschlagener POST ohne Quelle bleibt trotz einzig möglichem Ort unausgewählt', async () => {
      await load({mode:'transfer',posted:true,selected:'',stock:{...stock,partial:{...stock.partial,reserved:5}}});
      assert.equal(await field('location').inputValue(), '');
    });
    await run('Suchauswahl zeigt dieselben Quellen und synchronisiert beide Ladereihenfolgen', async () => {
      for (const searchFirst of [false,true]) {
        await load({mode:'receive',selected:'empty',searchFirst});
        const input = page.getByRole('combobox',{name:'Lagerort / Ausgangsort',exact:true});
        assert.equal(await input.inputValue(), 'Synthetic empty storage');
        await changeMode('transfer');
        await input.click();
        const popup = page.locator('#'+await input.getAttribute('aria-controls'));
        const labels = await popup.getByRole('option').allTextContents();
        assert(labels.includes(places.free) && labels.includes(places.partial));
        for (const id of ['empty','reserved','blocked','lent','never']) assert(!labels.includes(places[id]), id+' absent in search');
        await input.fill('partly');
        await popup.getByRole('option',{name:places.partial,exact:true}).click();
        assert.equal(await field('location').inputValue(), 'partial');
        assert.equal(await input.inputValue(), places.partial);
        await changeMode('receive');
        await input.click(); await input.fill('without stock');
        await popup.getByRole('option',{name:places.never,exact:true}).click();
        assert.equal(await field('location').inputValue(), 'never');
      }
    });
    await run('Browser sendet genau gewählten Ausgangsort, Ziel, Menge und Versionsschutz', async () => {
      await load({mode:'transfer',selected:'partial',destination:'never',quantity:'2'});
      await Promise.all([page.waitForURL(base+'/capture'),page.getByRole('button',{name:'Lagerbewegung bestätigen',exact:true}).click()]);
      assert.deepEqual(payload, {'version':'7','request_key':'1234567890abcdef1234567890abcdef','data[kind]':'transfer','data[location]':'partial','data[destination]':'never','data[quantity]':'2','data[reason]':'Synthetic existing reason'});
    });
    await run('Native Auswahl und Mobilgerät funktionieren ohne Suchfeld sowie ohne JavaScript-Fehler', async () => {
      await page.setViewportSize({width:390,height:844});
      await load({mode:'receive',selected:'empty',searchable:false});
      await changeMode('transfer');
      assert.deepEqual(await options('location'), ['free','partial']);
      await field('location').selectOption('free');
      assert.equal(await field('location').inputValue(), 'free');
      assert.deepEqual(errors, []);
    });
    console.log(JSON.stringify({checks,failures,scope:'Synthetic local HTML; real production scripts, native/select-search UI, request payload. No DB write or mail.'},null,2));
    if (failures.length) process.exitCode = 1;
  } finally {await browser.close(); await new Promise(resolve => server.close(resolve));}
})().catch(error => {console.error(error); process.exitCode = 1;});
