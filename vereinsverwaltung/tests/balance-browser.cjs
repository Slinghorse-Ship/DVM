'use strict';
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require('playwright');
(async()=>{
 const directory=process.env.VEREIN_BALANCE_UI_OUTPUT;assert(directory&&fs.existsSync(directory),'Private synthetic rendered fixture required');
 const browser=await chromium.launch({headless:true,channel:process.env.KULTURHUS_BROWSER_CHANNEL||'msedge'});let checks=0;
 try{
  for(const width of [1280,390]){
   const context=await browser.newContext({viewport:{width,height:1000}});await context.route('**/*',r=>r.abort());const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
   for(const name of ['balance-opening','balance-open','balance-closed']){
    await page.setContent(fs.readFileSync(path.join(directory,name+'.html'),'utf8'),{waitUntil:'domcontentloaded'});
    assert.equal(await page.getByRole('heading',{level:1,name:'Jahresabschluss · Bilanz'}).count(),1);checks++;
    assert((await page.locator('body').innerText()).includes('Grundlage: Bilanzjournal'));checks++;
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),true,'no overflow '+name+' '+width);checks++;
    if(name==='balance-opening'){assert.equal(await page.getByRole('button',{name:'Anfangsbestände übernehmen'}).count(),1);assert.equal(await page.getByRole('button',{name:'Buchung fest erfassen'}).count(),0);checks++;}
    if(name==='balance-open'){
     await page.getByText('Buchhaltungsdetails: manuelle Buchung',{exact:true}).click();
     assert.equal(await page.getByRole('button',{name:'Buchung fest erfassen'}).isVisible(),true);
     assert.equal(await page.locator('select[name$="[account]"]').count(),4);
     assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),true,'posting form remains usable on mobile');checks+=3;
    }
    if(name==='balance-closed'){assert.equal(await page.locator('form[method="post"]').count(),0);assert((await page.locator('body').innerText()).includes('Bilanzjournal festgeschrieben'));checks+=2;}
    await page.screenshot({path:path.join(directory,name+'-'+width+'.png'),fullPage:false});
   }
   assert.equal(errors.length,0);checks++;await context.close();
  }
  console.log(checks+' balance UI checks passed on desktop and mobile; no requests/submissions');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
