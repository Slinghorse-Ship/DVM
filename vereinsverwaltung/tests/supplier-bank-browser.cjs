'use strict';
// Actual local form/HTTP and read-only local SQLite checks; only public IBAN example values.
const {chromium}=require('playwright');const fs=require('node:fs');const assert=require('node:assert/strict');const {spawnSync}=require('node:child_process');
(async()=>{
 const fixtureFile=process.env.KULTURHUS_SUPPLIER_BANK_FIXTURE,php=process.env.KULTURHUS_TEST_PHP;
 assert(fixtureFile&&php,'Provide an isolated supplier bank fixture and PHP runtime.');
 const fixture=JSON.parse(fs.readFileSync(fixtureFile,'utf8').replace(/^\uFEFF/,'')),base=new URL(fixture.base);
 assert(['127.0.0.1','localhost','[::1]'].includes(base.hostname),'Local test only.');
 const state=()=>{const r=spawnSync(php,[fixture.readback,fixtureFile],{encoding:'utf8',env:{...process.env,KULTURHUS_DISABLE_MAIL:'1',SMTP_DSN:''}});assert.equal(r.status,0,r.stderr||r.stdout);return JSON.parse(r.stdout.replace(/^\uFEFF/,''));};
 const before=state();assert.equal(before.contact_count,1);assert.equal(before.bank.iban,'','Use a fresh supplier fixture without bank details.');
 const IBAN1='DE89370400440532013000',IBAN2='DE12500105170648489890',IBAN3='GB82WEST12345698765432',BIC1='COBADEFFXXX',BIC2='INGDDEFFXXX';
 const browser=await chromium.launch({headless:true,channel:process.env.KULTURHUS_BROWSER_CHANNEL||'msedge'}),checks=[];
 try{
  const context=await browser.newContext({viewport:{width:1280,height:1000}});await context.addCookies([{name:'KH_VEREIN_DEMO',value:fixture.sessions.admin,url:base.origin+'/'}]);
  const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  const url=base.origin+'/?view=members&id='+encodeURIComponent(fixture.supplier)+'#supplier-bank';
  const section=p=>p.locator('#supplier-bank');
  const saveForm=p=>section(p).locator('form').filter({has:p.locator('[name=action][value="supplier-bank-save"]')});
  const removeForm=p=>section(p).locator('form').filter({has:p.locator('[name=action][value="supplier-bank-remove"]')});
  const unchangedBusiness=current=>{
   assert.equal(current.contact_count,1,'No extra supplier created.');assert.deepEqual(current.entry,before.entry,'Saved invoice stays unchanged.');assert.deepEqual(current.supplier_snapshot,before.supplier_snapshot,'Historic supplier snapshot stays unchanged.');assert.equal(current.original_hash,before.original_hash,'Original stays unchanged.');
  };
  const save=async p=>{await saveForm(p).getByRole('button').click();await p.waitForLoadState();await section(p).waitFor();};
  await page.goto(url);assert.equal(await section(page).count(),1);assert.equal(await saveForm(page).count(),1);assert.equal(await section(page).locator('#profile-form').count(),0,'Independent form is outside the generic profile form.');
  assert.equal(await saveForm(page).locator('form').count(),0,'No nested form.');
  await saveForm(page).getByRole('button').click();await page.waitForFunction(()=>document.querySelector('[name=supplier_iban]')?.getAttribute('aria-invalid')==='true');
  assert.equal(await saveForm(page).locator('[name=supplier_iban]').inputValue(),'');assert.deepEqual(state(),before,'Empty IBAN creates no change.');
  await saveForm(page).locator('[name=supplier_iban]').fill('DE89370400440532013001');assert.equal(await saveForm(page).locator('[name=supplier_iban]').evaluate(e=>e.checkValidity()),false);assert.match(await saveForm(page).locator('[name=supplier_iban]').evaluate(e=>e.validationMessage),/Prüfziffer/i);
  checks.push('Eigener Bankdatenabschnitt; fehlende oder ungültige IBAN wird sichtbar markiert');
  await saveForm(page).locator('[name=supplier_iban]').fill('de89 3704 0044 0532 0130 00');await saveForm(page).locator('[name=supplier_bic]').fill(BIC1.toLowerCase());await save(page);
  let current=state();assert.equal(current.bank.iban,IBAN1);assert.equal(current.bank.bic,BIC1);assert.equal(current.contact.version,before.contact.version+1);unchangedBusiness(current);await page.reload();assert.equal(await saveForm(page).locator('[name=supplier_iban]').inputValue(),IBAN1);
  checks.push('IBAN und BIC hinzugefügt, normalisiert und nach Neuladen vorhanden');
  const stale=await context.newPage();await stale.goto(url);
  await saveForm(page).locator('[name=supplier_iban]').fill(IBAN2);await saveForm(page).locator('[name=supplier_bic]').fill(BIC2);await save(page);
  const changed=state();assert.equal(changed.bank.iban,IBAN2);assert.equal(changed.bank.bic,BIC2);assert.equal(changed.contact.version,before.contact.version+2);unchangedBusiness(changed);
  await saveForm(stale).locator('[name=supplier_iban]').fill(IBAN3);await saveForm(stale).locator('[name=supplier_bic]').fill('NWBKGB2L');await save(stale);
  assert.match((await stale.locator('.error[role=alert]').allTextContents()).join(' '),/geändert|aktuell|Version/i);
  assert.equal(await saveForm(stale).locator('[name=supplier_iban]').inputValue(),IBAN3,'Rejected stale draft stays visible.');assert.deepEqual(state(),changed,'Stale form cannot overwrite current bank details.');
  assert.equal(await saveForm(stale).locator('[name=version]').inputValue(),String(before.contact.version+1),'Error page must not silently upgrade a stale draft to the current contact version.');
  await save(stale);assert.deepEqual(state(),changed,'Re-submitting the stale draft still cannot overwrite newer bank details.');
  await stale.close();checks.push('Ändern funktioniert; veralteter POST erhält den Entwurf und überschreibt nichts');
  const invoice=await context.newPage();await invoice.goto(base.origin+'/?view=finance&tab=inbox&status=assigned&inbox_doc='+fixture.doc);
  assert((await invoice.content()).replace(/\s/g,'').includes(IBAN2),'Linked invoice shows current supplier master bank details.');
  const bankLink=invoice.locator('a[href*="#supplier-bank"]');assert.equal(await bankLink.count(),1);await bankLink.click();await section(invoice).waitFor();
  assert.equal(new URL(invoice.url()).searchParams.get('id'),fixture.supplier);assert.equal(new URL(invoice.url()).hash,'#supplier-bank');await invoice.close();
  checks.push('Verknüpfte Rechnung zeigt aktuelle Lieferantenbankdaten und führt direkt zur Bankpflege');

  for(const role of ['reader','denied']){
   const limited=await browser.newContext({viewport:{width:1280,height:1000}});await limited.addCookies([{name:'KH_VEREIN_DEMO',value:fixture.sessions[role],url:base.origin+'/'}]);const p=await limited.newPage();await p.goto(url);
   assert.equal(await saveForm(p).count(),0,role+' has no edit form');assert.equal(await removeForm(p).count(),0,role+' has no remove form');
   const html=await p.content(),compact=html.replace(/\s/g,'');
   if(role==='reader')assert(compact.includes(IBAN2),'Authorized finance reader sees saved IBAN.');else assert(!compact.includes(IBAN2)&&!compact.includes(BIC2),'Missing finance rights exposes neither IBAN nor BIC.');
   const response=await limited.request.post(url,{form:{action:'supplier-bank-save',id:fixture.supplier,version:String(changed.contact.version),supplier_iban:IBAN3,supplier_bic:'NWBKGB2L',csrf:fixture.csrf[role]},maxRedirects:0});
   assert.notEqual(response.status(),303,role+' cannot save through a crafted request');assert.deepEqual(state(),changed,role+' crafted POST changes nothing');
   await limited.close();
  }
  checks.push('Finanzleserecht zeigt Daten ohne Bearbeitung; ohne Finanzrecht keine Bankdaten oder Schreibmöglichkeit');
  for(const width of [1280,390]){await page.setViewportSize({width,height:900});await page.waitForTimeout(350);await section(page).scrollIntoViewIfNeeded();assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),width+'px: no horizontal page overflow.');}
  checks.push('Bankdatenabschnitt auf Desktop und 390px ohne Seitenüberlauf');
  await page.reload();const remove=removeForm(page);assert.equal(await remove.count(),1);
  const parentDetails=remove.locator('xpath=ancestor::details[1]');if(await parentDetails.count()&&!await parentDetails.evaluate(e=>e.open))await parentDetails.locator(':scope > summary').click();
  page.once('dialog',dialog=>dialog.accept());await remove.getByRole('button').click();await page.waitForLoadState();
  const removed=state();assert.deepEqual(removed.bank,{iban:'',bic:''});assert.equal(removed.contact.version,before.contact.version+3);unchangedBusiness(removed);assert.equal(await saveForm(page).locator('[name=supplier_iban]').inputValue(),'');
  assert(!JSON.stringify(removed.audit).includes(IBAN1)&&!JSON.stringify(removed.audit).includes(IBAN2)&&!JSON.stringify(removed.audit).includes(IBAN3),'Audit contains no IBAN values.');assert(removed.audit.length>=before.audit.length+3,'Save, change and removal are auditable.');assert.equal(removed.integrity,'ok');assert.deepEqual(removed.foreign_keys,[]);
  checks.push('Bankdaten separat entfernt; Firma/Rechnung/Original bleiben, Änderungen ohne Kontonummer protokolliert');
  assert.deepEqual(errors,[]);console.log(JSON.stringify({passed:checks.length,checks,scope:'Synthetic local contacts and public example IBANs only; no bank connection, production actions or mail.'},null,2));
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
