'use strict';
// Actual isolated localhost form saves only. Original invoice year is separate
// from the explicitly retained internal SKR42 reference catalog (2026).
const {chromium}=require('playwright');
const fs=require('node:fs'),assert=require('node:assert/strict'),{spawnSync}=require('node:child_process');
(async()=>{
 const fixtureFile=process.env.KULTURHUS_HISTORICAL_INVOICE_FIXTURE,php=process.env.KULTURHUS_TEST_PHP;
 assert(fixtureFile&&php,'Local fixture and PHP executable required.');
 const fixture=JSON.parse(fs.readFileSync(fixtureFile,'utf8').replace(/^\uFEFF/,'')),base=new URL(fixture.base);
 assert(['127.0.0.1','localhost','[::1]'].includes(base.hostname),'No production browser tests.');
 const state=()=>{const r=spawnSync(php,[fixture.readback,fixtureFile],{encoding:'utf8',env:{...process.env,KULTURHUS_DISABLE_MAIL:'1',SMTP_DSN:''}});assert.equal(r.status,0,r.stderr||r.stdout);return JSON.parse(r.stdout);};
 const before=state();assert.equal(before.entries.length,0,'Use a fresh fixture.');
 const checks=[],browser=await chromium.launch({headless:true,channel:process.env.KULTURHUS_BROWSER_CHANNEL||'msedge'});
 try{
  const context=await browser.newContext({viewport:{width:1280,height:900}});
  await context.addCookies([{name:'KH_VEREIN_DEMO',value:fixture.session,url:base.origin+'/'}]);
  const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  const url=base.origin+'/?view=finance&tab=inbox&inbox_doc='+fixture.doc;
  await page.goto(url);const form=page.locator('#rechnung-erfassen form');await form.waitFor();
  await form.locator('[data-purpose-group]').selectOption('general');await form.locator('[name=supplier_contact]').selectOption(fixture.supplier);
  await form.locator('[name=description]').fill('Synthetischer historischer Beleg mit geprüfter Kontierung');
  await form.locator('[name=supplier_reference]').fill('SYNTH-2022');await form.locator('[name=amount]').fill('42,00');
  await form.locator('[name=document_date]').fill('2022-04-04');await form.locator('[name=due_date]').fill('2022-04-30');
  assert.match(await form.locator('[data-invoice-document-year]').innerText(),/Rechnungsjahr 2022/);
  await form.locator('[name=document_date]').fill('2024-04-04');assert.match(await form.locator('[data-invoice-document-year]').innerText(),/Rechnungsjahr 2024/);
  await form.locator('[name=document_date]').fill('2022-04-04');checks.push('Rechnungsjahr folgt dem Originaldatum sofort und unabhängig vom Kontenplan');
  const positions=form.locator('[data-position-editor]').locator('..');if(!await positions.evaluate(e=>e.open))await positions.locator(':scope > summary').click();
  await form.locator('[data-position-add]').click();const row=form.locator('[data-position-editor] > fieldset').first();
  await row.locator('[data-field=description]').fill('Synthetisches Büromaterial');await row.locator('[data-field=amount]').fill('42,00');await row.locator('[data-field=tax_rate]').selectOption('0');
  const accounting=row.locator('[data-field=sphere]').locator('xpath=ancestor::details[1]');if(!await accounting.evaluate(e=>e.open))await accounting.locator(':scope > summary').click();
  await row.locator('[data-field=sphere]').selectOption('1');await row.locator('[data-field=account]').selectOption('68150');
  for(const width of [1280,390]){await page.setViewportSize({width,height:900});await form.locator('[data-invoice-document-year]').scrollIntoViewIfNeeded();assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),width+'px no horizontal overflow');}
  assert.deepEqual(state(),before,'Draft changes do not write finance or original records.');checks.push('Kontierung möglich; 390px und Desktop ohne horizontalen Überlauf, Entwurf schreibt nichts');
  await form.locator('[name=inbox_version]').evaluate(e=>e.value='999');await form.locator('[name=positions_reviewed]').check();await form.locator('[data-invoice-save]').click();await page.waitForLoadState();await form.waitFor();
  assert.match((await page.locator('.error[role=alert]').allTextContents()).join(' '),/Dokument oder Ordner wurde inzwischen geändert/i);
  assert.equal(await form.locator('[name=document_date]').inputValue(),'2022-04-04');assert.match(await form.locator('[data-invoice-document-year]').innerText(),/Rechnungsjahr 2022/);
  assert.equal(await form.locator('[data-field=account]').first().inputValue(),'68150');assert.deepEqual(state(),before);checks.push('Fehler-POST erhält Originaljahr und Kontierung, keine Teilbuchung');
  await form.locator('[name=inbox_version]').evaluate(e=>e.value='1');await form.locator('[name=positions_reviewed]').check();
  const payload=await form.evaluate(f=>new URLSearchParams([...new FormData(f)].map(([k,v])=>[k,String(v)])).toString());
  await form.locator('[data-invoice-save]').click();await page.waitForLoadState();assert(page.url().includes('status=assigned'),(await page.locator('.error[role=alert]').allTextContents()).join(' '));
  const saved=state();assert.equal(saved.entries.length,1);const entry=saved.entries[0];assert.equal(entry.document_date,'2022-04-04');assert.equal(entry.record_date,'2022-04-04');assert.equal(entry.positions[0].catalog_year,2026);assert.equal(entry.positions[0].account_code,'68150');assert.equal(entry.positions[0].sphere,'1');
  assert.equal(saved.document.document_date,'2022-04-04');assert.match(saved.document.path,/^\/Finanzen\/2022\/Ausgaben\//);assert.equal(saved.document.folder_id,entry.source.folder_id);assert.equal(saved.original_hash,fixture.original_hash);assert.equal(saved.integrity,'ok');assert.deepEqual(saved.foreign_keys,[]);
  const linked=saved.history.find(h=>h.action==='expense-linked'),audit=JSON.parse(linked.details);assert.equal(audit.before.document_date,'2026-09-08');assert.equal(audit.after.document_date,'2022-04-04');checks.push('Echter Save: Beleg/Nachweis/Original 2022, Kontenplanbasis ausdrücklich 2026, richtiger Jahresordner, Original unverändert');
  const replay=await context.request.post(url,{data:payload,headers:{'Content-Type':'application/x-www-form-urlencoded'},maxRedirects:0});assert.equal(replay.status(),303);assert.deepEqual(state(),saved);checks.push('Identischer zweiter POST erzeugt weder Duplikat noch zweite Metadatenänderung');
  await page.goto(base.origin+'/?view=finance&tab=export');const datev=page.locator('#datev-form');await datev.locator('[name=from]').fill('2022-01-01');await datev.locator('[name=to]').fill('2022-12-31');await datev.locator('[name=advisor]').fill('1001');await datev.locator('[name=client]').fill('1');await datev.getByRole('button',{name:'DATEV-Vorschau erstellen'}).click();await page.waitForLoadState();
  assert.match((await page.locator('.error[role=alert]').allTextContents()).join(' '),/2026/);assert.deepEqual(state(),saved);checks.push('Historischer DATEV-Stapel bleibt gesperrt; keine falsche Jahreskennzeichnung');
  assert.deepEqual(errors,[]);console.log(JSON.stringify({passed:checks.length,checks,scope:'Local isolated SQLite only, no mail or production actions.'},null,2));
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
