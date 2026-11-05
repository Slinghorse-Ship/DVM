'use strict';
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'C:/Users/wehla/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const assert=require('node:assert/strict'),fs=require('node:fs'),os=require('node:os'),path=require('node:path'),{execFileSync}=require('node:child_process');
(async()=>{
 const dir=fs.mkdtempSync(path.join(os.tmpdir(),'kulturhus-website-state-')),file=path.join(dir,'fixture.html');
 const root=path.resolve(__dirname,'../..'),php=process.env.PHP_BINARY||path.join(root,'.local/php-8.5.10/php.exe');
 let browser;
 try{
  execFileSync(php,[path.join(__dirname,'website-native-form-state.php')],{env:{...process.env,WEBSITE_STATE_FIXTURE:file}});
  browser=await chromium.launch({channel:'msedge',headless:true});const page=await browser.newPage();
  await page.route('**/*',route=>route.abort());
  await page.setContent(fs.readFileSync(file,'utf8'));
  await page.addStyleTag({path:path.join(root,'vereinsverwaltung/public/style.css')});
  await page.addStyleTag({path:path.join(root,'vereinsverwaltung/public/website-native.css')});
  for(const width of [1280,390]){
   await page.setViewportSize({width,height:960});
   assert.equal(await page.locator('[name="fields[workflow_status]"]').inputValue(),'review');
   assert.equal(await page.locator('[name="fields[channels][]"][value="rss"]').isChecked(),true);
   assert.equal(await page.locator('[name="fields[channels][]"][value="website"]').isChecked(),false);
   assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
   console.log('PASS '+width+'px: restored article status/channels visible, no page overflow');
  }
 }finally{if(browser)await browser.close();fs.rmSync(dir,{recursive:true,force:true});}
})().catch(error=>{console.error(error);process.exit(1);});
