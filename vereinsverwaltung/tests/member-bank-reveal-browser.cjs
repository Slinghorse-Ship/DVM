'use strict';
// Synthetic form only; no server, login, real bank values or network requests.
const {chromium}=require('playwright');const assert=require('node:assert/strict');const path=require('node:path');const {spawnSync}=require('node:child_process');
(async()=>{
 const php=process.env.KULTURHUS_TEST_PHP;assert(php,'Provide KULTURHUS_TEST_PHP.');
 const source=`require 'vereinsverwaltung/src/Web/View.php'; $_SESSION=['csrf'=>str_repeat('c',64)]; $bankSummary=['version'=>1,'status'=>'saved','iban_masked'=>'DE •••• 3000','holder_masked'=>'S…','mandate_ref'=>'']; $bankRevealed=true; $bankContact=str_repeat('a',32); $bankOwn=true; $bankDraft=['holder'=>'Synthetic Holder','iban'=>'DE89370400440532013000','bic'=>'COBADEFFXXX']; require 'vereinsverwaltung/views/People/Members/member-bank.php';`;
 const rendered=spawnSync(php,['-r',source],{encoding:'utf8'});assert.equal(rendered.status,0,rendered.stderr);
 const html=rendered.stdout.replace(/<script\b[^>]*>[\s\S]*?<\/script>/g,'');const browser=await chromium.launch({headless:true,channel:process.env.KULTURHUS_BROWSER_CHANNEL||'msedge'});let checks=0;
 try{
  for(const trigger of ['timeout','pagehide','bfcache']){
   const context=await browser.newContext({viewport:{width:trigger==='timeout'?390:1280,height:900}});const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
   await page.route('**/*',route=>route.fulfill({status:200,contentType:'text/html',body:html}));await page.goto('http://member-bank.test/');await page.clock.install();await page.addScriptTag({path:path.resolve('vereinsverwaltung/public/member-bank.js')});
   const form=page.locator('[data-bank-revealed-form]');assert.equal(await form.count(),1);assert.equal(await form.locator('[name="data[iban]"]').inputValue(),'DE89370400440532013000');assert.equal(await form.locator('[type=password]').inputValue(),'');assert.equal(await form.locator('[type=password]').getAttribute('required'),'');checks++;
   await form.locator('[type=password]').fill('synthetic-current-password');
   if(trigger==='timeout'){await page.clock.fastForward(299999);assert.equal(await form.isVisible(),true);await page.clock.fastForward(1);}else if(trigger==='pagehide'){await page.evaluate(()=>window.dispatchEvent(new PageTransitionEvent('pagehide')));}else await page.evaluate(()=>window.dispatchEvent(new PageTransitionEvent('pageshow',{persisted:true})));
   assert.equal(await form.isVisible(),false);const state=await form.locator('input[name^="data["], input[type=password]').evaluateAll(inputs=>inputs.map(e=>({value:e.value,defaultValue:e.defaultValue,attribute:e.getAttribute('value')})));assert(state.every(e=>e.value===''&&e.defaultValue===''&&e.attribute===null),'Current, default and HTML attribute values are cleared on '+trigger);assert.equal(await page.locator('[data-bank-reveal-reset]').textContent(),'Bankdatenbereich erneut öffnen');assert((await page.locator('[data-bank-reveal-reset]').getAttribute('href')).includes('tab=bank'));checks++;
   await form.evaluate(e=>e.reset());assert.equal(await form.locator('[name="data[iban]"]').inputValue(),'','Browser form reset cannot recover full IBAN');const storage=await page.evaluate(()=>({session:sessionStorage.length,local:localStorage.length}));assert.deepEqual(storage,{session:0,local:0});assert.deepEqual(errors,[]);checks++;await context.close();
  }
  console.log(`PASS ${checks} member bank form/browser checks: actual PHP view, blank fresh password, exact five-minute expiry, pagehide/BFCache erasure, no form-reset recovery or browser storage; synthetic only`);
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exit(1);});
