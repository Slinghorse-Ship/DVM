'use strict';
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require('playwright');
(async()=>{
 const dir=process.env.VEREIN_TAX_ASSET_UI_OUTPUT;assert(dir&&fs.existsSync(dir));
 const browser=await chromium.launch({headless:true,channel:'msedge'});let checks=0;
 try{for(const width of [1280,390]){
  const context=await browser.newContext({viewport:{width,height:1100}});await context.route('**/*',r=>r.abort());
  const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
  for(const state of ['unchanged','changed','readonly','inherited','invalid','scrapped','scrapped-before','sold','sold-zero','sold-before','mixed']){
   await page.setContent(fs.readFileSync(path.join(dir,state+'.html'),'utf8'),{waitUntil:'domcontentloaded'});
   const card=page.locator('details.card');if(await card.getAttribute('open')===null)await card.locator(':scope > summary').click();
   const item=card.locator(':scope > details').first();if(await item.getAttribute('open')===null)await item.locator(':scope > summary').click();
   assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),true,'no overflow '+state+' '+width);checks++;
   const canEdit=!['readonly','inherited'].includes(state);
   assert.equal(await page.getByRole('button',{name:'Steuerwerte berechnen',exact:true}).count(),canEdit?(state==='mixed'?2:1):0);checks++;
   if(canEdit){
    const form=page.locator('form').first();const life=form.getByLabel('Steuerliche Nutzungsdauer · Jahre');
    await life.fill('2');await form.getByLabel('Grund und Nachweis').fill('Synthetischer Nachweis');
    const confirmed=form.getByLabel('Die abweichende steuerliche Nutzungsdauer ist fachlich bestätigt.');
    await confirmed.uncheck();assert.equal(await form.evaluate(f=>f.checkValidity()),false);checks++;
    await confirmed.check();assert.equal(await form.evaluate(f=>f.checkValidity()),true);checks++;
    await life.fill('2.5');assert.equal(await form.evaluate(f=>f.checkValidity()),false);checks++;
    await life.fill('2');
    const names=await form.evaluate(f=>Array.from(new FormData(f).keys()));
    for(const name of ['csrf','filing','version','asset_item','tax_years','tax_depreciation_account','tax_plan_reason','tax_plan_confirmed']){assert.ok(names.includes(name));checks++;}
   }
   if(state==='scrapped'){
    assert.equal(await page.getByText('Ausgebuchter Restwert im Jahr 2025: 40,00',{exact:false}).count(),1);checks++;
    assert.equal(await page.getByText('Steuerlich ausgebuchter Restwert im Jahr 2025: 80,00',{exact:false}).count(),1);checks++;
   }
   if(state.startsWith('sold')){
    assert.equal(await page.getByText('Verkauft am 15.09.2025',{exact:false}).count(),1);checks++;
    assert.equal(await page.getByText('Verkaufsergebnis: Buch',{exact:false}).count(),state==='sold-before'?0:1);checks++;
   }
   if(state==='mixed'){
    assert.equal(await page.getByText('Verkauft am 15.09.2025',{exact:false}).count(),1);checks++;
    assert.equal(await page.getByText('Ausgemustert am 15.09.2025',{exact:false}).count(),1);checks++;
    assert.deepEqual(await page.locator('form').filter({has:page.getByRole('button',{name:'Steuerwerte berechnen',exact:true})}).evaluateAll(forms=>forms.map(f=>new FormData(f).get('asset_item'))),['scrapped','sold']);checks++;
   }
   if(['changed','scrapped','scrapped-before','sold','sold-zero','sold-before','mixed'].includes(state))await page.screenshot({path:path.join(dir,state+'-'+width+'.png'),fullPage:true});
  }
  assert.equal(errors.length,0);checks++;await context.close();
 }}finally{await browser.close();}
 console.log(checks+' tax asset browser checks passed; network blocked, no form submitted.');
})().catch(e=>{console.error(e);process.exit(1);});
