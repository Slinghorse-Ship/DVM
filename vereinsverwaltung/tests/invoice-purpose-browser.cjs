'use strict';
// An authenticated, isolated local fixture is required. No production endpoint or mail is used.
// KULTURHUS_INVOICE_PURPOSE_FIXTURE points to a JSON file containing base, session, doc,
// supplier, account, and two synthetic event IDs. The fixture also contains two matching
// inventory-history positions and matching artist-history positions for events[0].
const {chromium}=require('playwright');
const fs=require('node:fs');
const assert=require('node:assert/strict');

(async()=>{
 const path=process.env.KULTURHUS_INVOICE_PURPOSE_FIXTURE;
 assert(path,'Set KULTURHUS_INVOICE_PURPOSE_FIXTURE to an isolated local fixture.');
 const fixture=JSON.parse(fs.readFileSync(path,'utf8').replace(/^\uFEFF/,''));
 const base=new URL(fixture.base||'http://127.0.0.1:8091');
 assert(['127.0.0.1','localhost','[::1]'].includes(base.hostname),'Local test only.');
 assert(fixture.session&&fixture.doc&&fixture.supplier&&fixture.account&&fixture.events?.length===2,'Complete local fixture required.');
 const browser=await chromium.launch({headless:true,channel:process.env.KULTURHUS_BROWSER_CHANNEL||'msedge'});
 const checks=[];
 try{
  const context=await browser.newContext({viewport:{width:1280,height:1000}});
  await context.addCookies([{name:'KH_VEREIN_DEMO',value:fixture.session,url:base.origin+'/'}]);
  const page=await context.newPage(),errors=[],suggestions=[];
  page.on('pageerror',error=>errors.push(error.message));
  page.on('response',async response=>{
   if(!response.url().includes('invoice_accounting_suggestions=1'))return;
   try{suggestions.push({status:response.status(),data:await response.json()});}catch{}
  });
  const form=page.locator('#rechnung-erfassen form');
  const purpose=()=>form.locator('[data-purpose-group]');
  const target=()=>form.locator('[name=purpose]');
  const rows=()=>form.locator('[data-position-editor] > fieldset');
  const field=(row,key)=>row.locator('[data-field='+key+']');
  const open=async()=>{
   await page.goto(base.origin+'/?view=finance&tab=inbox&inbox_doc='+encodeURIComponent(fixture.doc));
   await form.waitFor();
   assert.equal(await page.locator('.error[role=alert]').count(),0,'No unexpected page error.');
   await page.waitForFunction(()=>document.querySelectorAll('[data-invoice-purpose] select').length>=2);
  };
  const choose=async value=>{await purpose().selectOption(value);};
  const chooseCategory=async value=>{
   const category=form.locator('[name=category]'),details=category.locator('..').locator('..');
   if(await details.evaluate(e=>e.tagName==='DETAILS'&&!e.open))await details.locator(':scope > summary').click();
   await category.selectOption(value);
  };
  const setupRows=async(type='artist',event=fixture.events[0])=>{
   await open();await choose(type);
   if(event)await target().selectOption(type+'|'+event);
   await form.locator('[name=supplier_contact]').selectOption(fixture.supplier);
   await form.locator('[name=document_date]').fill('2026-09-08');
   await form.locator('[name=amount]').fill('20,00');
   const details=form.locator('[data-position-editor]').locator('..');
   if(!await details.evaluate(e=>e.open))await details.locator(':scope > summary').click();
   for(const description of ['Synthetisches Bühnenbauteil','Synthetisches Zubehör']){
    await form.locator('[data-position-add]').click();
    const row=rows().last();
    await field(row,'description').fill(description);await field(row,'amount').fill('10,00');
    await field(row,'tax_rate').selectOption('0');
   }
   await page.waitForFunction(account=>{
    const rows=[...document.querySelectorAll('[data-position-editor] > fieldset')];
    return rows.length===2&&rows.every(row=>row._accounting?.dataset.proposed==='1'&&row.querySelector('[data-field=account]').value===account);
   },fixture.account);
   assert.equal(await field(rows().nth(0),'sphere').inputValue(),'3');
   assert.equal(await field(rows().nth(1),'booking_key').inputValue(),'','No historical BU is inferred.');
  };
  const manualFirst=async()=>{
   const row=rows().first();await row.locator('[data-accounting-notice]').locator('..').evaluate(e=>e.open=true);
   await field(row,'sphere').selectOption('1');
   await form.locator('[name=positions_reviewed]').check();
  };
  const assertInvalidation=async label=>{
   await page.waitForFunction(()=>{
    const rows=[...document.querySelectorAll('[data-position-editor] > fieldset')];
    return rows[1].querySelector('[data-field=account]').value==='';
   });
   assert.equal(await field(rows().first(),'sphere').inputValue(),'1',label+': manual sphere remains.');
   assert.equal(await field(rows().first(),'account').inputValue(),fixture.account,label+': manual account remains.');
   for(const key of ['sphere','account','booking_key'])assert.equal(await field(rows().nth(1),key).inputValue(),'',label+': automatic '+key+' cleared.');
   assert.match(await rows().first().locator('[data-accounting-notice]').innerText(),/prüfen/i,label+': review notice.');
   assert.equal(await form.locator('[name=positions_reviewed]').isChecked(),false,label+': prior approval cleared.');
  };

  await open();
  assert.equal(await purpose().inputValue(),'','Fresh invoice requires a deliberate purpose selection.');
  assert(await purpose().evaluate(e=>e.required&&!e.checkValidity()),'Missing purpose is a required-field error.');
  assert(await form.evaluate(form=>{
   const purpose=form.querySelector('[data-invoice-purpose]');
   return [form.querySelector('[data-invoice-supplier]'),form.querySelector('[data-position-editor]')].every(other=>!!other&&!!(purpose.compareDocumentPosition(other)&Node.DOCUMENT_POSITION_FOLLOWING));
  }),'Purpose precedes supplier and invoice positions in reading and keyboard order.');
  checks.push('Rechnungszweck zuerst und bewusst auswählen; fehlt die Auswahl, ist das Feld ungültig');
  await choose('new_item');assert.equal(await form.locator('[name=category]').inputValue(),'inventory','Inventory purpose proposes inventory cost category.');
  await chooseCategory('office');await choose('insurance');
  assert.equal(await form.locator('[name=category]').inputValue(),'office','Explicit category survives purpose change.');
  checks.push('Kostenart passend vorbelegt; eigene Auswahl bleibt erhalten');

  await setupRows();await manualFirst();await choose('insurance');await assertInvalidation('Purpose change');
  checks.push('Zweckwechsel verwirft automatische Kontierung und erhält manuelle Auswahl mit Prüfhinweis');

  await setupRows();await choose('new_item');
  await page.waitForFunction(()=>[...document.querySelectorAll('[data-position-editor] [data-field=account]')].every(field=>field.value===''));
  for(const value of ['general','insurance','new_item']){await choose(value);await chooseCategory('inventory');await page.waitForTimeout(650);for(const row of await rows().all())for(const key of ['sphere','account','booking_key'])assert.equal(await field(row,key).inputValue(),'','No non-event cross-purpose proposal from same supplier/category history.');}
  checks.push('Allgemein, Versicherung und Inventar übernehmen trotz gleicher Firma/Kostenart keine unklaren früheren Zwecke');

  await setupRows('artist',fixture.events[0]);await manualFirst();
  await target().selectOption('artist|'+fixture.events[1]);await assertInvalidation('Event change');
  checks.push('Veranstaltungswechsel wird als neuer Kontierungskontext behandelt');

  await setupRows();await choose('mixed');await chooseCategory('inventory');
  await page.waitForFunction(()=>[...document.querySelectorAll('[data-position-editor] [data-field=account]')].every(field=>field.value===''));
  const mixed=await form.evaluate(async form=>{
   const recognition=form.querySelector('[data-invoice-recognition]'),payload=new FormData();
   for(const name of ['csrf','category','purpose'])payload.set(name,form.querySelector('[name='+name+']').value);
   payload.set('doc',recognition.dataset.doc);payload.set('supplier',form.querySelector('[name=supplier_contact]').value);payload.set('date',form.querySelector('[name=document_date]').value);
   payload.set('positions',JSON.stringify([...form.querySelectorAll('[data-position-editor] > fieldset')].map(row=>({description:row.querySelector('[data-field=description]').value,tax_rate:row.querySelector('[data-field=tax_rate]').value}))));
   const response=await fetch(recognition.dataset.accountingUrl,{method:'POST',body:payload,credentials:'same-origin'});
   return {status:response.status,data:await response.json()};
  });
  assert.equal(mixed.status,200);assert.deepEqual(mixed.data.suggestions,[null,null],'Server also declines global classification for mixed invoices.');
  assert.equal(await form.locator('select[name=sphere],select[name=account],select[name=booking_key]').count(),0,'Classification is per invoice position.');
  checks.push('Gemischte Rechnung erhält keine pauschale Sphäre, kein Steuerkonto und keinen BU-Schlüssel');

  const help=form.locator('[data-accounting-help]');
  assert.equal(await help.count(),1,'One inline accounting help is present.');
  if(!await help.evaluate(e=>e.open))await help.locator(':scope > summary').click();
  const helpText=await help.innerText();
  for(const term of [/Sphäre/i,/Steuerkonto|Sachkonto/i,/BU[- ]Schlüssel/i,/0|7|19/,/Vorsteuer/i])assert.match(helpText,term,'Help explains '+term);
  checks.push('Hilfe erklärt Sphäre, Konto, Steuersatz und BU getrennt');

  for(const width of [1280,390]){
   await page.setViewportSize({width,height:900});await page.waitForTimeout(400);
   await form.locator('[data-invoice-purpose]').scrollIntoViewIfNeeded();
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),width+'px: no horizontal page overflow.');
   if(width===390){
    const group=purpose(),combo=group.locator('..').locator('input[role=combobox]');
    if(await combo.count()){
     await combo.fill('Inventar');await combo.press('ArrowDown');await combo.press('Enter');
     assert.equal(await group.inputValue(),'new_item','Purpose selectable by keyboard.');
    }else{
     await group.focus();await group.press('Home');await group.press('ArrowDown');
     assert.equal(await group.evaluate(e=>e===document.activeElement),true,'Native purpose select is keyboard reachable.');
    }
    assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Open/used choice does not overflow mobile page.');
   }
  }
  checks.push('Desktop und 390px-Mobilansicht ohne Seitenüberlauf; Auswahl per Tastatur');
  await open();await choose('insurance');await chooseCategory('office');
  await form.locator('[name=supplier_contact]').selectOption(fixture.supplier);
  await form.locator('[name=description]').fill('Synthetischer Entwurf bleibt bei Fehler erhalten');
  await form.locator('[name=document_date]').fill('2026-09-08');await form.locator('[name=due_date]').fill('2026-09-30');
  await form.locator('[name=amount]').fill('20,00');
  await form.locator('[name=inbox_version]').evaluate(e=>e.value='999');
  await form.getByRole('button',{name:'Rechnung speichern',exact:true}).click();
  await page.waitForLoadState();await form.waitFor();
  assert(await page.locator('.error[role=alert]').count()>0,'A deliberately stale original causes a real server error.');
  assert.match((await page.locator('.error[role=alert]').allTextContents()).join(' '),/Dokument oder Ordner wurde inzwischen geändert/i,'Failure is the intended original-version conflict.');
  assert.equal(await purpose().inputValue(),'insurance','Chosen purpose survives failed POST.');
  assert.equal(await form.locator('[name=category]').inputValue(),'office','Explicit cost category survives failed POST.');
  assert.equal(await form.locator('[name=description]').inputValue(),'Synthetischer Entwurf bleibt bei Fehler erhalten','Invoice draft survives failed POST.');
  checks.push('Bei abgelehntem Speichern bleiben Rechnungszweck, eigene Kostenart und Eingaben erhalten');
  const noScript=await browser.newContext({javaScriptEnabled:false,viewport:{width:1280,height:900}});
  await noScript.addCookies([{name:'KH_VEREIN_DEMO',value:fixture.session,url:base.origin+'/'}]);
  const plain=await noScript.newPage();await plain.goto(base.origin+'/?view=finance&tab=inbox&inbox_doc='+encodeURIComponent(fixture.doc));
  const plainPurpose=plain.locator('#rechnung-erfassen [name=purpose]');
  assert.equal(await plainPurpose.inputValue(),'','No-script form does not silently preselect a purpose.');
  assert(await plainPurpose.evaluate(e=>e.required&&!e.checkValidity()),'Native HTML required field works without JavaScript.');
  await plainPurpose.selectOption('insurance');
  assert(await plainPurpose.evaluate(e=>e.checkValidity()),'Native purpose choice works without JavaScript.');
  await noScript.close();
  checks.push('Ohne JavaScript bleibt Rechnungszweck eine bedienbare HTML-Pflichtauswahl');
  assert(suggestions.some(r=>r.status===200)&&suggestions.every(r=>r.status<500),'Real local accounting endpoint has successful results and no server failures.');
  assert.deepEqual(errors,[],'No JavaScript runtime errors.');
  console.log(JSON.stringify({passed:checks.length,checks,scope:'Isolated local browser and actual local suggestion endpoint; no production writes or mail.'},null,2));
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
