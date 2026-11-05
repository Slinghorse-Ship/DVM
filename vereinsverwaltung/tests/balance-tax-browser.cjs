'use strict';
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require('playwright');
(async()=>{
 const dir=process.env.VEREIN_BALANCE_UI_OUTPUT;assert(dir&&fs.existsSync(dir));
 const browser=await chromium.launch({headless:true,channel:'msedge'});let checks=0;
 try{for(const width of [1280,390]){
  const context=await browser.newContext({viewport:{width,height:1000}});await context.route('**/*',r=>r.abort());const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
  for(const state of ['approve','approved','sent','unknown']){
   await page.setContent(fs.readFileSync(path.join(dir,'balance-tax-'+state+'.html'),'utf8'),{waitUntil:'domcontentloaded'});
   assert.equal(await page.getByRole('heading',{name:'E-Bilanz 2025',exact:true}).count(),1);checks++;
   assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),true,'no overflow '+state+' '+width);checks++;
   assert.equal(await page.getByRole('button',{name:'Test jetzt senden',exact:true}).count(),state==='approved'?1:0);checks++;
   assert.equal(await page.getByRole('button',{name:'Entwurf für den Test freigeben',exact:true}).count(),state==='approve'?1:0);checks++;
   if(await page.locator('.balance-tax-info').getAttribute('open')===null)await page.getByText('1. Angaben prüfen',{exact:true}).click();
   await page.getByText('Konten und Zuordnung prüfen',{exact:true}).click();
   for(const rate of ['generalRateVAT','reducedRateVAT']){
    assert.ok(await page.locator('select[name^="mapping["] option[value$=".'+rate+'"]').count()>0,'reviewed net revenue mapping is available');checks++;
   }
   assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),true,'mapping remains readable on mobile');checks++;
   await page.getByText('1. Angaben prüfen',{exact:true}).click();
   await page.screenshot({path:path.join(dir,'balance-tax-'+state+'-'+width+'.png'),fullPage:true});
  }assert.equal(errors.length,0);checks++;await context.close();
 }}finally{await browser.close();}
 console.log(checks+' E-Bilanz UI checks passed; no requests or form submissions.');
})().catch(e=>{console.error(e);process.exit(1);});
