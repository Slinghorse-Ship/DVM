'use strict';
// Requires an isolated local fixture and its read-only PHP state reader.
// No production URL, mail, OCR mock or synthetic production record is allowed.
const {chromium}=require('playwright');
const fs=require('node:fs');
const assert=require('node:assert/strict');
const {spawnSync}=require('node:child_process');

(async()=>{
 const fixtureFile=process.env.KULTURHUS_INVENTORY_INLINE_FIXTURE,php=process.env.KULTURHUS_TEST_PHP;
 assert(fixtureFile&&php,'Set KULTURHUS_INVENTORY_INLINE_FIXTURE and KULTURHUS_TEST_PHP.');
 const fixture=JSON.parse(fs.readFileSync(fixtureFile,'utf8').replace(/^\uFEFF/,''));
 const base=new URL(fixture.base||'http://127.0.0.1:8091');
 assert(['127.0.0.1','localhost','[::1]'].includes(base.hostname),'Local browser test only.');
 assert(fixture.readback&&fixture.location&&fixture.supplier&&fixture.existing,'Complete synthetic fixture required.');
 const state=()=>{
  const result=spawnSync(php,[fixture.readback,fixtureFile],{encoding:'utf8',env:{...process.env,KULTURHUS_DISABLE_MAIL:'1',SMTP_DSN:''}});
  assert.equal(result.status,0,result.stderr||result.stdout);return JSON.parse(result.stdout.replace(/^\uFEFF/,''));
 };
 const before=state();assert.equal(before.entries.length,0,'Use a fresh, unbooked fixture.');assert.equal(before.items.length,1,'One existing synthetic item is required.');
 const checks=[],browser=await chromium.launch({headless:true,channel:process.env.KULTURHUS_BROWSER_CHANNEL||'msedge'});
 try{
  const context=await browser.newContext({viewport:{width:1280,height:1000}});
  await context.addCookies([{name:'KH_VEREIN_DEMO',value:fixture.session,url:base.origin+'/'}]);
  const page=await context.newPage(),errors=[],writes=[];page.on('pageerror',e=>errors.push(e.message));
  page.on('request',request=>{if(request.method()==='POST'&&!request.url().includes('invoice_accounting_suggestions='))writes.push(request.url());});
  const url=base.origin+'/?view=finance&tab=inbox&inbox_doc='+encodeURIComponent(fixture.doc);
  await page.goto(url);
  const form=page.locator('#rechnung-erfassen form'),rows=()=>form.locator('[data-position-editor] > fieldset');
  const field=(row,key)=>row.locator('[data-field='+key+']'),draft=(row,key)=>row.locator('[data-inventory-field='+key+']');
  const toggle=row=>row.locator('[data-inventory-create]');
  await form.waitFor();await form.locator('[data-purpose-group]').selectOption('new_item');
  await form.locator('[name=supplier_contact]').selectOption(fixture.supplier);
  await form.locator('[name=description]').fill('Synthetische Rechnung mit zwei neuen Inventargegenständen');
  await form.locator('[name=supplier_reference]').fill('SYNTH-INLINE-1');
  await form.locator('[name=document_date]').fill('2026-09-08');await form.locator('[name=due_date]').fill('2026-09-30');
  await form.locator('[name=amount]').fill('1336,00');
  const positionSection=form.locator('[data-position-editor]').locator('..');
  if(!await positionSection.evaluate(e=>e.open))await positionSection.locator(':scope > summary').click();
  const addPosition=async({name,quantity,unit,price,amount})=>{
   await form.locator('[data-position-add]').click();const row=rows().last();
   await field(row,'description').fill(name);await field(row,'amount').fill(amount);await field(row,'tax_rate').selectOption('19');
   await field(row,'quantity').fill(quantity);await field(row,'unit').fill(unit);await field(row,'unit_price').fill(price);await field(row,'price_basis').selectOption('gross');return row;
  };
  await addPosition({name:'Synthetisches Bühnenpodest',quantity:'4',unit:'Stück',price:'299,00',amount:'1196,00'});
  await addPosition({name:'Synthetische Podestfüße',quantity:'4',unit:'Satz',price:'35,00',amount:'140,00'});
  for(const row of [rows().nth(0),rows().nth(1)]){
   assert(await toggle(row).isVisible(),'Direct create button is visible.');
   assert.equal(await toggle(row).getAttribute('aria-expanded'),'false');
   assert(!await row.locator('[data-inventory-draft]').isVisible());
   assert(await row.locator('[data-inventory-field]').evaluateAll(fields=>fields.every(e=>e.disabled&&!e.name)),'Inactive draft fields cannot block/save anything.');
  }
  assert.deepEqual(state(),before,'Opening positions changes no inventory, invoice or original.');checks.push('Direkter Button pro Position; ohne bewusste Neuanlage weder Pflichtfalle noch Datenänderung');

  const first=rows().nth(0);await toggle(first).focus();await toggle(first).press('Enter');
  assert.equal(await draft(first,'name').inputValue(),'Synthetisches Bühnenpodest','Current entered description is inherited.');
  assert.equal(await draft(first,'unit').inputValue(),'Stück');
  assert.match(await first.locator('[data-inventory-quantity]').innerText(),/4 Stück/);
  assert.match(await first.locator('[data-inventory-quantity]').innerText(),/299,00/,'Unit price, not invoice total, is shown.');
  assert(await draft(first,'name').isVisible()&&await draft(first,'home_location').locator('..').isVisible(),'Name and required location are exposed immediately.');
  assert(await first.locator('[data-existing-inventory-add]').isDisabled(),'Existing-item assignment and a new item cannot compete.');
  await form.locator('[name=positions_reviewed]').check();const beforeInvalidWrites=writes.length;
  await form.locator('[data-invoice-save]').click();
  await page.waitForFunction(()=>document.querySelector('[data-inventory-field=home_location]')?.closest('label')?.querySelector('[aria-invalid=true]'));
  assert.equal(writes.length,beforeInvalidWrites,'Missing location is blocked before submitting.');
  assert(await first.locator('[data-inventory-draft]').isVisible());
  assert.match(await form.locator('.form-validation-summary').innerText(),/aufbewahrt|Lagerort/i,'Error clearly names the location.');
  assert.deepEqual(state(),before,'Missing location causes no mutations.');checks.push('Name/Menge/Stückwert übernommen; Lagerortfehler sichtbar und lokal blockiert');

  await draft(first,'name').fill('Geprüftes Bühnenpodest');await toggle(first).click();
  assert(await first.locator('[data-inventory-field]').evaluateAll(fields=>fields.every(e=>e.disabled&&!e.name)));
  const existing=first.locator('[data-existing-inventory]');await existing.locator(':scope > summary').click();
  await existing.locator('select').selectOption(fixture.existing+'|1');await existing.locator('[data-existing-inventory-add]').click();
  assert(await toggle(first).isDisabled(),'A linked existing item prevents accidental duplicate creation.');
  await existing.getByRole('button',{name:'Zuordnung entfernen',exact:true}).click();assert(await toggle(first).isEnabled());
  await toggle(first).click();assert.equal(await draft(first,'name').inputValue(),'Geprüftes Bühnenpodest','Toggling preserves custom draft name.');
  checks.push('Neuanlage zurücknehmen und bestehendes Inventar wählen; keine doppelte Zuordnung');

  const temp=await addPosition({name:'Synthetische vorübergehende Position',quantity:'1',unit:'Stück',price:'1',amount:'1'});
  await toggle(temp).click();await temp.getByRole('button',{name:'Position entfernen',exact:true}).click();
  assert.equal(await rows().count(),2);assert.equal(await form.locator('[name^="invoice_positions[2]"]').count(),0,'Removing a position removes its inventory draft from submission.');
  await form.locator('[name=use_invoice_positions]').uncheck();
  assert(await first.locator('[data-inventory-field]').evaluateAll(fields=>fields.every(e=>e.disabled&&!e.name)));
  await form.locator('[name=use_invoice_positions]').check();assert.equal(await toggle(first).getAttribute('aria-expanded'),'true');
  assert.deepEqual(state(),before);checks.push('Entfernte und ausgeschaltete Positionen senden keine Inventarneuanlage');

  await draft(first,'home_location').selectOption(fixture.location);
  assert.equal(await draft(first,'location_version').inputValue(),String(fixture.location_version));
  const firstExtra=first.locator('[data-inventory-draft] details');if(!await firstExtra.evaluate(e=>e.open))await firstExtra.locator(':scope > summary').click();
  await draft(first,'category').fill('Synthetische Bühnentechnik');
  const second=rows().nth(1);await toggle(second).click();
  assert.equal(await draft(second,'name').inputValue(),'Synthetische Podestfüße');
  assert.equal(await draft(second,'unit').inputValue(),'Satz','Current source unit is inherited.');
  await draft(second,'name').fill('Geprüfte Podestfüße');await draft(second,'home_location').selectOption(fixture.location);
  await second.locator('[data-inventory-draft] details > summary').click();
  await draft(second,'content_quantity').fill('4');await draft(second,'content_unit').selectOption('Stück');
  assert.match(await second.locator('[data-inventory-quantity]').innerText(),/4 Sätze.*je 4 Stück = 16 Stück/);
  assert.match(await second.locator('[data-inventory-quantity]').innerText(),/35,00/);
  assert.match(await form.locator('[data-invoice-save]').innerText(),/2 neue Gegenstände/);
  for(const width of [1280,390]){await page.setViewportSize({width,height:900});await page.waitForTimeout(350);await second.locator('[data-inventory-draft]').scrollIntoViewIfNeeded();assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),width+'px has no horizontal page overflow.');}
  checks.push('Vier Sätze mit je vier Teilen verständlich angezeigt; Desktop/Mobil/Tastatur bedienbar');

  await form.locator('[name=inbox_version]').evaluate(e=>e.value='999');await form.locator('[name=positions_reviewed]').check();
  await form.locator('[data-invoice-save]').click();await page.waitForLoadState();await form.waitFor();
  assert.match((await page.locator('.error[role=alert]').allTextContents()).join(' '),/Dokument oder Ordner wurde inzwischen geändert/i);
  assert.equal(await rows().count(),2);
  for(const [i,name]of [[0,'Geprüftes Bühnenpodest'],[1,'Geprüfte Podestfüße']]){
   assert.equal(await toggle(rows().nth(i)).getAttribute('aria-expanded'),'true');
   assert.equal(await draft(rows().nth(i),'name').inputValue(),name);assert.equal(await draft(rows().nth(i),'home_location').inputValue(),fixture.location);
  }
  assert.equal(await draft(rows().nth(1),'content_quantity').inputValue(),'4');
  assert.deepEqual(state(),before,'Failed save rolls back invoice, both items, stocks and all links.');checks.push('Echter Fehler-POST erhält beide Inventarentwürfe und hinterlässt keine Teilbuchung');

  await form.locator('[name=inbox_version]').evaluate(e=>e.value='1');await form.locator('[name=positions_reviewed]').check();
  const payload=await form.evaluate(form=>new URLSearchParams([...new FormData(form)].map(([key,value])=>[key,String(value)])).toString());
  await form.locator('[data-invoice-save]').click();await page.waitForLoadState();
  assert(page.url().includes('status=assigned'),(await page.locator('.error[role=alert]').allTextContents()).join(' '));
  const saved=state();assert.equal(saved.entries.length,1);assert.equal(saved.items.length,3);assert.equal(saved.inbox_status,'assigned');
  assert.equal(saved.entries[0].amount_cents,133600);assert.equal(saved.entries[0].positions.length,2);assert.equal(saved.entries[0].items.length,2);
  for(const [name,unit,price,contents]of [['Geprüftes Bühnenpodest','Stück',29900,1],['Geprüfte Podestfüße','Satz',3500,4]]){
   const item=saved.items.find(item=>item.name===name);assert(item,name+' exists');assert.equal(item.quantity,4);assert.equal(item.unit,unit);assert.equal(item.unit_value_cents,price);assert.equal(item.content_quantity,contents);assert.equal(item.source_entry,saved.entries[0].id);assert.equal(item.stock[0].location_id,fixture.location);
  }
  assert.equal(saved.original_hash,fixture.original_hash);assert.equal(saved.integrity,'ok');assert.deepEqual(saved.foreign_keys,[]);
  checks.push('Echt gespeichert: genau eine Rechnung, zwei Gegenstände, korrekte Mengen/Stückpreise und unverändertes Original');
  const replay=await context.request.post(url,{data:payload,headers:{'Content-Type':'application/x-www-form-urlencoded'},maxRedirects:0});
  assert.equal(replay.status(),303,'Identical request is idempotently redirected to the existing invoice.');assert.deepEqual(state(),saved,'Retry produces no duplicate item, stock or invoice.');
  assert.deepEqual(errors,[]);checks.push('Identischer zweiter POST erzeugt keine Duplikate');
  console.log(JSON.stringify({passed:checks.length,checks,scope:'Isolated local SQLite, actual browser and form saves. No production actions or mail.'},null,2));
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
