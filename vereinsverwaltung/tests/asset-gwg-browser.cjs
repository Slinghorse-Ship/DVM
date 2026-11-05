'use strict';
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require('playwright');
(async()=>{
 const dir=process.env.VEREIN_USABILITY_UI_OUTPUT;assert(dir&&fs.existsSync(dir));
 const root=path.resolve(__dirname,'../public'),browser=await chromium.launch({headless:true,channel:'msedge'});let checks=0;
 try{for(const width of [1280,390]){
  const context=await browser.newContext({viewport:{width,height:1100}});await context.route('**/*',route=>route.abort());
  const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.setContent(fs.readFileSync(path.join(dir,'asset-book.html'),'utf8'));
  for(const name of ['style.css','finance-workflows.css','tax-guided.css'])await page.addStyleTag({content:fs.readFileSync(path.join(root,name),'utf8')});
  await page.addScriptTag({content:fs.readFileSync(path.join(root,'asset-guided.js'),'utf8')});
  assert.equal(await page.locator('[data-asset-gwg]').isVisible(),false);checks++;
  await page.locator('[data-asset-method]').selectOption('gwg_immediate');
  assert.equal(await page.locator('[data-asset-gwg]').isVisible(),true);checks++;
  assert.equal(await page.locator('[data-asset-linear]').isVisible(),false);checks++;
  assert.equal(await page.locator('[name=account]').inputValue(),'06700');checks++;
  assert.equal(await page.locator('[name=gwg_no_pool]').evaluate(e=>e.required),true);checks++;
  await page.locator('[name=gwg_net]').fill('100');await page.locator('[name=gwg_vat]').fill('19');
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),true,'no horizontal overflow');checks++;
  await page.screenshot({path:path.join(dir,'asset-gwg-'+width+'.png'),fullPage:true});
  await page.locator('[data-asset-method]').selectOption('linear_monthly');
  assert.equal(await page.locator('[name=gwg_net]').isDisabled(),true);checks++;
  assert.equal(await page.locator('[data-asset-linear]').isVisible(),true);checks++;
  assert.equal(await page.locator('[name=account]').inputValue(),'');checks++;
  await page.locator('[data-asset-method]').selectOption('degressive');
  assert.equal(await page.locator('[data-asset-degressive]').isVisible(),true);checks++;
  assert.equal(await page.locator('[data-asset-linear]').isVisible(),true);checks++;
  assert.equal(await page.locator('[name=degressive_same_values]').evaluate(e=>e.required),true);checks++;
  assert.equal(await page.locator('[name=degressive_switch]').evaluate(e=>e.required),false);checks++;
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),true);checks++;
  await page.screenshot({path:path.join(dir,'asset-degressive-'+width+'.png'),fullPage:true});
  await page.locator('[data-asset-method]').selectOption('linear_monthly');
  assert.equal(await page.locator('[name=degressive_rate]').isDisabled(),true);checks++;
  assert.equal(errors.length,0);checks++;
  await context.close();
 }}finally{await browser.close();}
 console.log(checks+' GWG browser checks passed; no network or form submissions.');
})().catch(e=>{console.error(e);process.exit(1);});
