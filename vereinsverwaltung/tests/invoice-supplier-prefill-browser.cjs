'use strict';
// Real local application/scripts, synthetic OCR data, all POSTs intercepted.
const {chromium}=require('playwright'),fs=require('node:fs'),assert=require('node:assert/strict');
(async()=>{
 const fixture=JSON.parse(fs.readFileSync(process.env.KULTURHUS_SUPPLIER_FIXTURE||'.local/new-invoice-audit/ui-fixture.json','utf8').replace(/^\uFEFF/,''));
 const base=new URL(fixture.base);assert(['127.0.0.1','localhost','[::1]'].includes(base.hostname));
 const browser=await chromium.launch({headless:true,channel:'msedge'}),checks=[];
 try{
  const context=await browser.newContext({viewport:{width:1280,height:900}});
  await context.addCookies([{name:'KH_VEREIN_DEMO',value:fixture.session,url:base.origin+'/'}]);
  const page=await context.newPage(),errors=[],blocked=[],submitted=[];
  page.on('pageerror',e=>errors.push(e.message));
  const company={Rechnungssteller:'Synthetischer Druckhandel GmbH','Anschrift Rechnungssteller':'Testweg 42\n12345 Beispielstadt','E-Mail Rechnungssteller':'kontakt@druckhandel.example','Telefon Rechnungssteller':'+49 (0) 123 / 456 789 - 20',Währung:'EUR',Belegart:'Rechnung',Rechnungsdatum:'2025-07-23','Rechnungsbetrag brutto':'49.98'};
  const make=(fields={},bank_accounts=[])=>({state:'ready',fields:{...company,...fields},items:[],warnings:[],bank_accounts});
  let seed=make(),response=seed;
  await page.route('**/*',async route=>{
   const req=route.request();assert(['127.0.0.1','localhost','[::1]'].includes(new URL(req.url()).hostname),'Local only');
   if(req.method()==='POST'){
    if(req.url().includes('invoice_recognition=1'))return route.fulfill({contentType:'application/json',body:JSON.stringify({result:response})});
    if(req.url().includes('invoice_supplier_create=1')){submitted.push(req.postData());return route.fulfill({status:422,contentType:'application/json',body:JSON.stringify({error:'Synthetischer Test: Es wird nichts gespeichert.'})});}
    blocked.push(req.url());return route.fulfill({status:200,contentType:'application/json',body:'{"suggestions":[]}'});
   }
   if(req.isNavigationRequest()&&req.url().includes('inbox_doc='+fixture.doc)){
    const original=await route.fetch(),html=await original.text(),escaped=JSON.stringify(seed).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    assert.equal((html.match(/data-seed="[^"]*"/g)||[]).length,1,'Real invoice recognition markup');
    return route.fulfill({response:original,body:html.replace(/data-seed="[^"]*"/,'data-seed="'+escaped+'"')});
   }
   return route.continue();
  });
  const visit=async()=>{await page.goto(base.origin+'/?view=finance&tab=inbox&inbox_doc='+fixture.doc);await page.locator('[data-supplier-create]').waitFor();};
  const open=async()=>{await page.locator('[data-supplier-create]').click();return page.getByRole('dialog',{name:'Neue Firma anlegen'});};
  const cancel=async()=>page.getByRole('dialog',{name:'Neue Firma anlegen'}).getByRole('button',{name:'Abbrechen',exact:true}).click();
  const contactValues=async dialog=>{for(const [field,key]of[['name','Rechnungssteller'],['email','E-Mail Rechnungssteller'],['phone','Telefon Rechnungssteller'],['address','Anschrift Rechnungssteller']])assert.equal(await dialog.locator('[name='+field+']').inputValue(),company[key],field+' copied from native seed');};
  await visit();let dialog=await open();await contactValues(dialog);await page.waitForTimeout(150);await contactValues(dialog);
  assert.equal(await page.evaluate(()=>Boolean(window.KulturhusFormValidation&&window.KulturhusSelectSearch)),true);
  checks.push('Initial data-seed transfers name, email, phone and address with real validation/search scripts');
  await cancel();seed=make({'E-Mail Rechnungssteller':'','Telefon Rechnungssteller':''});await visit();dialog=await open();assert.equal(await dialog.locator('[name=email]').inputValue(),'');await cancel();
  response=make();await page.locator('[data-recognition-run]').click();await page.waitForFunction(()=>document.querySelector('[data-invoice-supplier]')._recognitionSupplier?.email==='kontakt@druckhandel.example');
  dialog=await open();await contactValues(dialog);checks.push('Reopening after improved recognition fills missing contact fields');
  await cancel();response=make({'E-Mail Rechnungssteller':'','Telefon Rechnungssteller':''});await page.locator('[data-recognition-run]').click();await page.waitForFunction(()=>document.querySelector('[data-invoice-supplier]')._recognitionSupplier?.email==='');
  dialog=await open();assert.equal(await dialog.locator('[name=email]').inputValue(),'');assert.equal(await dialog.locator('[name=phone]').inputValue(),'');checks.push('Withdrawn recognition values clear untouched old proposals');
  await cancel();response=make();await page.locator('[data-recognition-run]').click();await page.waitForFunction(()=>document.querySelector('[data-invoice-supplier]')._recognitionSupplier?.email==='kontakt@druckhandel.example');dialog=await open();
  await dialog.locator('[name=email]').fill('von-hand@example.invalid');await dialog.locator('[name=phone]').fill('');await cancel();
  response=make({'E-Mail Rechnungssteller':'neu@example.invalid','Telefon Rechnungssteller':'+49 000 999999'});await page.locator('[data-recognition-run]').click();await page.waitForFunction(()=>document.querySelector('[data-invoice-supplier]')._recognitionSupplier?.email==='neu@example.invalid');
  dialog=await open();assert.equal(await dialog.locator('[name=email]').inputValue(),'von-hand@example.invalid');assert.equal(await dialog.locator('[name=phone]').inputValue(),'');checks.push('Manual edits and deliberately cleared values survive reopening and newer recognition');await cancel();
  const bank={iban:'DE89370400440532013000',bic:'COBADEFFXXX',bank_name:'Synthetische Beispielbank'};
  for(const payment of ['Klarna','PayPal']){
   // A legacy cached field must not override a separate payment instruction.
   seed=make({Zahlungsart:payment,...(payment==='PayPal'?{IBAN:bank.iban,BIC:bank.bic}:{})},[bank]);await visit();dialog=await open();assert.equal(await dialog.locator('[name=iban]').inputValue(),'');assert.equal(await dialog.locator('[name=bic]').inputValue(),'');assert.equal(await dialog.locator('[data-supplier-bank-choice]').inputValue(),'');
   await dialog.locator('[data-supplier-bank-choice]').selectOption('0');assert.equal(await dialog.locator('[name=iban]').inputValue(),bank.iban);await cancel();dialog=await open();assert.equal(await dialog.locator('[name=iban]').inputValue(),bank.iban);await cancel();checks.push(payment+': a sole footer bank stays unselected until explicit choice');
  }
  seed=make({IBAN:bank.iban,BIC:bank.bic,Zahlungsart:'Überweisung'},[bank]);await visit();dialog=await open();assert.equal(await dialog.locator('[name=iban]').inputValue(),bank.iban);checks.push('An explicit single transfer account remains available');
  await page.setViewportSize({width:390,height:844});assert.equal(await dialog.evaluate(d=>d.scrollWidth<=d.clientWidth+1),true);assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);await contactValues(dialog);
  await dialog.locator('[name=email]').fill('ungültig');await dialog.getByRole('button',{name:'Firma anlegen und auswählen',exact:true}).click();await page.waitForFunction(()=>document.querySelector('dialog [name=email]').getAttribute('aria-invalid')==='true');assert.equal(submitted.length,0);
  await dialog.locator('[name=email]').fill(company['E-Mail Rechnungssteller']);await dialog.getByRole('button',{name:'Firma anlegen und auswählen',exact:true}).click();await dialog.locator('[data-create-error]').filter({hasText:'Synthetischer Test'}).waitFor();assert.equal(submitted.length,1);await contactValues(dialog);assert.match(submitted[0],/kontakt@druckhandel\.example/);assert.match(submitted[0],/456 789 - 20/);
  await cancel();await page.locator('[data-invoice-supplier]').evaluate(box=>box.dispatchEvent(new CustomEvent('invoice-supplier-proposal',{detail:{name:'  SYNTHETISCHER DRUCKHANDEL GMBH  '}})));dialog=page.getByRole('dialog',{name:'Neue Firma anlegen'});assert.equal(await dialog.locator('[name=email]').inputValue(),company['E-Mail Rechnungssteller']);assert.equal(await dialog.locator('[name=iban]').inputValue(),bank.iban);
  await cancel();await page.locator('[data-invoice-supplier]').evaluate(box=>box.dispatchEvent(new CustomEvent('invoice-supplier-proposal',{detail:{name:'Anderer synthetischer Lieferant'}})));dialog=page.getByRole('dialog',{name:'Neue Firma anlegen'});assert.equal(await dialog.locator('[name=name]').inputValue(),'Anderer synthetischer Lieferant');for(const field of ['email','phone','address','iban','bic'])assert.equal(await dialog.locator('[name='+field+']').inputValue(),'');checks.push('Same supplier retains its draft; another named supplier never inherits contact or bank data');
  assert.deepEqual(errors,[]);assert.deepEqual(blocked,[]);checks.push('390px, validation, payload and failed save preserve contact data; zero database writes or mail');
  console.log(JSON.stringify({passed:checks.length,checks},null,2));
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
