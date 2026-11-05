'use strict';
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'C:/Users/wehla/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const assert=require('node:assert/strict');
(async()=>{
 const base='http://127.0.0.1:8092';const url=base+'/vereinsverwaltung/?view=website&tab=navigation';
 const browser=await chromium.launch({channel:'msedge',headless:true});
 const page=await browser.newPage({viewport:{width:1280,height:960}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
 let original,changed=false;
 const snapshot=()=>page.locator('[data-website-menu]').evaluate(el=>({auto:el.querySelector('[data-menu-auto]').checked,rows:[...el.querySelectorAll('[data-menu-row]')].map(row=>({id:row.querySelector('input[type=text]').id,label:row.querySelector('input[type=text]').value,position:row.querySelector('[data-menu-position]').value,visible:row.querySelector('input[type=checkbox]').checked}))}));
 const save=async()=>{await Promise.all([page.waitForURL(url),page.getByRole('button',{name:'Menü speichern',exact:true}).click()]);await page.getByText('Menü gespeichert. Die Änderung ist auf der Website sichtbar.',{exact:true}).waitFor();};
 try{
  assert.equal((await page.goto(url)).status(),200);await page.getByRole('button',{name:'Menü speichern',exact:true}).waitFor();
  assert.equal(await page.locator('[data-menu-row]').count(),6);original=await snapshot();
  await page.locator('[data-menu-auto]').uncheck();await page.locator('#menu-label-program').fill('Programm 2027');
  await page.locator('[data-menu-row]').filter({has:page.locator('#menu-label-program')}).locator('[data-menu-move=down]').click();
  const gallery=page.locator('[data-menu-row]').filter({has:page.locator('#menu-label-gallery')});await gallery.locator('input[type=checkbox]').uncheck();
  changed=true;await save();
  assert.equal(await page.locator('#menu-label-program').inputValue(),'Programm 2027');assert.equal(await page.locator('[data-menu-auto]').isChecked(),false);
  const publicPage=await browser.newPage();await publicPage.goto(base+'/');
  assert.equal(await publicPage.locator('#nav a').first().textContent(),'Programm 2027');assert.equal(await publicPage.locator('#nav a').count(),5);assert.equal(await publicPage.locator('#nav a[href$="galerie/"]').count(),0);await publicPage.close();
  console.log('PASS actual demo save, reload and public header: manual 2027, order and hidden gallery');
  await page.setViewportSize({width:390,height:960});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
  await page.locator('.website-menu-editor').screenshot({path:'.local/website-menu-mobile.png'});
  console.log('PASS 390px without horizontal overflow');assert.deepEqual(errors,[]);
 }finally{
  if(changed&&original){
   await page.goto(url);await page.locator('[data-menu-auto]').uncheck();
   for(const row of original.rows){const input=page.locator('#'+row.id);await input.fill(row.label);const li=input.locator('xpath=ancestor::li');await li.locator('[data-menu-position]').fill(row.position);await li.locator('input[type=checkbox]').setChecked(row.visible);}
   await page.locator('[data-menu-auto]').setChecked(original.auto);await save();console.log('PASS restored previous demo menu');
  }
  await browser.close();
 }
})().catch(error=>{console.error(error);process.exitCode=1;});
