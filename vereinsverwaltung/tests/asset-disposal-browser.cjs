'use strict';
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require('playwright');
(async()=>{
 const dir=process.env.VEREIN_DISPOSAL_UI_OUTPUT;assert(dir&&fs.existsSync(dir));
 const root=path.resolve(__dirname,'../public'),browser=await chromium.launch({headless:true,channel:'msedge'});let checks=0;
 try{for(const width of [1280,390]){
  const context=await browser.newContext({viewport:{width,height:1100}});await context.route('**/*',route=>route.abort());
  const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  const load=async name=>{
   await page.setContent(fs.readFileSync(path.join(dir,name+'.html'),'utf8'));
   for(const css of ['style.css','finance-workflows.css','tax-guided.css'])await page.addStyleTag({content:fs.readFileSync(path.join(root,css),'utf8')});
   await page.addScriptTag({content:fs.readFileSync(path.join(root,'asset-guided.js'),'utf8')});
  };
  await load('disposal-open');
  await page.getByText('Jahreswert prüfen und abschließen',{exact:true}).click();
  await page.getByText('Gegenstand verkauft oder entsorgt',{exact:true}).click();
  assert.equal(await page.locator('[name=disposal_date]').isVisible(),false);checks++;
  await page.locator('[name=disposal_kind]').selectOption('scrap');
  assert.equal(await page.locator('[name=disposal_date]').isVisible(),true);checks++;
  assert.equal(await page.locator('[name=disposal_confirmed]').evaluate(e=>e.required),true);checks++;
  await page.locator('[name=disposal_date]').fill('2025-06-15');
  const text=await page.locator('[data-disposal-amounts]').innerText();assert.match(text,/Abgangsmonat: 60,00/);assert.match(text,/Restwert: 60,00/);checks+=2;
  await page.locator('[name=disposal_document]').selectOption({label:'Synthetic signed scrapping record'});
  await page.locator('[name=disposal_reason]').fill('Synthetischer Test: vollständig entsorgt.');
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),true);checks++;
  await page.screenshot({path:path.join(dir,'disposal-'+width+'.png'),fullPage:true});
  await page.locator('[name=disposal_kind]').selectOption('');
  assert.equal(await page.locator('[name=disposal_date]').isDisabled(),true);checks++;
  assert.equal(await page.locator('[name=disposal_confirmed]').evaluate(e=>e.required),false);checks++;
  await load('disposal-following');
  assert.equal(await page.locator('[name=disposal_kind]').count(),0);checks++;
  assert.match(await page.locator('body').innerText(),/Ausgemustert am 2026-03-10/);checks++;
  assert.equal(errors.length,0);checks++;
  await context.close();
 }}finally{await browser.close();}
 console.log(checks+' disposal browser checks passed; network blocked, no form submitted.');
})().catch(e=>{console.error(e);process.exit(1);});
