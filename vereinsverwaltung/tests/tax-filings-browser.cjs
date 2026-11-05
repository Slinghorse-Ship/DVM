'use strict';
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require('playwright');
(async()=>{
 const directory=process.env.VEREIN_ERIC_UI_OUTPUT;assert(directory&&fs.existsSync(directory),'Private synthetic rendered fixture required');
 const browser=await chromium.launch({headless:true,channel:process.env.KULTURHUS_BROWSER_CHANNEL||'msedge'});let checks=0;
 try{
  for(const width of [1280,390]){
   const context=await browser.newContext({viewport:{width,height:1000}});await context.route('**/*',r=>r.abort());const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
   for(const name of ['overview','form','gem']){
    await page.setContent(fs.readFileSync(path.join(directory,name+'.html'),'utf8'),{waitUntil:'domcontentloaded'});
    assert.equal(await page.locator('h1').count(),1);assert((await page.locator('body').innerText()).includes('Steuererklärungen'));checks++;
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),true,'no horizontal overflow '+name+' '+width);checks++;
    if(name==='overview'){assert.equal(await page.locator('.tax-family').count(),11);assert(!(await page.locator('.tax-family').allTextContents()).join(' ').includes('ELStAM'));checks++;}
    else {assert((await page.locator('.tax-environment').innerText()).includes('TEST'));assert(await page.getByRole('button',{name:'Angaben speichern',exact:true}).count());checks+=2;}
    await page.screenshot({path:path.join(directory,name+'-'+width+'.png'),fullPage:false});
   }
   assert.equal(errors.length,0);checks++;await context.close();
  }
  console.log(checks+' browser rendering checks passed on desktop and mobile; no requests/submissions');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
