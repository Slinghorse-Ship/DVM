'use strict';
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require('playwright');
(async()=>{
 const file=process.env.VEREIN_AUTO_UI_FIXTURE;assert(file&&fs.existsSync(file));
 const root=path.resolve(__dirname,'../public');const browser=await chromium.launch({headless:true,channel:'msedge'});let checks=0;
 try{for(const width of [1280,390]){
  const context=await browser.newContext({viewport:{width,height:960}});await context.route('**/*',r=>r.abort());
  const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.setContent(fs.readFileSync(file,'utf8'),{waitUntil:'domcontentloaded'});
  for(const css of ['style.css','tax-preparation.css','tax-filings.css','tax-year-notes.css','tax-guided.css'])if(fs.existsSync(path.join(root,css)))await page.addStyleTag({content:fs.readFileSync(path.join(root,css),'utf8')});
  await page.addStyleTag({content:'*,*::before,*::after{box-sizing:border-box}body{margin:16px}textarea{max-width:100%;width:100%}'});
  await page.addScriptTag({content:fs.readFileSync(path.join(root,'tax-year-notes.js'),'utf8')});
  assert.equal(await page.getByRole('heading',{level:1,name:'Steuererklärung vorbereiten'}).count(),1);checks++;
  assert.equal(await page.getByRole('button',{name:'KSt 1 und Anlage Gem übernehmen'}).count(),0);checks++;
  assert.equal(await page.getByRole('button',{name:'Zwischenstand speichern'}).isVisible(),true);checks++;
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),true,'no horizontal page overflow');checks++;
  await page.addScriptTag({content:fs.readFileSync(path.join(root,'tax-guided.js'),'utf8')});
  await page.locator('#tax-auto-tax_number').evaluate(el=>{for(let p=el.parentElement;p;p=p.parentElement)if(p.tagName==='DETAILS')p.open=true;});
  assert.equal(await page.locator('input[name="auto_answers[tax_number]"]').inputValue(),'9198011310010');checks++;
  await page.getByRole('link',{name:'2. Buchführung & Nachweise',exact:true}).click();
  const year=page.locator('details[data-tax-notes-year]');await year.evaluate(el=>el.open=true);
  const reserve=page.locator('select[name="tax_year_notes[2025][reserves_status]"]');await reserve.evaluate(el=>{for(let p=el.parentElement;p;p=p.parentElement)if(p.tagName==='DETAILS')p.open=true;});await reserve.selectOption('listed');
  assert.equal(await page.getByRole('button',{name:'Weitere Rücklage hinzufügen'}).isVisible(),true);checks++;
  await page.getByRole('button',{name:'Weitere Rücklage hinzufügen'}).click();
  assert.equal(await page.locator('[data-tax-notes-list="reserves"] > [data-tax-notes-row]').count(),2);checks++;
  await reserve.selectOption('none');
  assert.equal(await page.locator('[data-tax-notes-list="reserves"] input').first().isDisabled(),true);checks++;
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),true,'expanded form fits mobile');checks++;
  const fields=await page.locator('input:not([type=hidden]),select,textarea').evaluateAll(nodes=>nodes.filter(n=>!n.closest('template')).every(n=>n.labels&&n.labels.length));assert(fields,'all controls labelled');checks++;
  assert.equal(errors.length,0,errors.join('; '));checks++;
  await page.evaluate(()=>{document.querySelectorAll('details').forEach(d=>d.open=false);scrollTo(0,0);});
  await page.screenshot({path:path.join(path.dirname(file),'tax-auto-'+width+'.png'),fullPage:false});await context.close();
 }}finally{await browser.close();}
 console.log(checks+' UI checks passed; desktop/mobile, synthetic fixture, no requests');
})().catch(e=>{console.error(e);process.exit(1);});
