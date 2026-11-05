import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const fixture=JSON.parse(await readFile(process.env.DVM_WEBSITE_LAYOUTS_FIXTURE,'utf8'));
const browser=await chromium.launch({executablePath:'/usr/bin/chromium'});
let checks=0,taps=0;
const check=(value,label)=>{assert(value,label);checks++;console.log('PASS '+label);};
try{
 for(const javascript of [true,false]){
  const context=await browser.newContext({javaScriptEnabled:javascript,viewport:{width:320,height:844},hasTouch:true,isMobile:true});
  const external=[],errors=[];
  await context.route('**/*',route=>new URL(route.request().url()).origin===fixture.origin?route.continue():(external.push(route.request().url()),route.abort()));
  const page=await context.newPage();page.on('pageerror',error=>errors.push(error.message));
  await page.goto(fixture.origin+'/own-test-enter?role=admin');
  for(const design of ['klar','magazin','aktiv','natur','kulturhus']){
   await page.goto(fixture.origin+'/vereinsverwaltung/?native_endpoint=design-preview&template='+design);
   const menu=page.locator('.site-menu-toggle'),navigation=page.locator('.site-header nav');
   check(await navigation.locator('a').count()===5,'five actual original destinations '+design+' JS='+javascript);
   for(const zoom of [1,2]){
    await page.evaluate(value=>document.documentElement.style.zoom=String(value),zoom);
    if(javascript){
     check(await menu.evaluate(node=>getComputedStyle(node).opacity==='1'&&node.getBoundingClientRect().height>=44),'visible full touch target '+design+' zoom='+zoom);
     await menu.tap();taps++;
     check(await menu.getAttribute('aria-expanded')==='true'&&await navigation.isVisible(),'real touch opens menu '+design+' zoom='+zoom);
    }else check(await menu.isHidden()&&await navigation.isVisible(),'usable navigation without inactive button '+design+' zoom='+zoom);
    check(await navigation.locator('a').evaluateAll(nodes=>nodes.every(node=>{const r=node.getBoundingClientRect();return r.height>=44&&r.width>=44&&r.left>=-1&&r.right<=innerWidth+1;})),'all real links visible and reachable without clipping '+design+' zoom='+zoom+' JS='+javascript);
    if(javascript){await page.keyboard.press('Escape');check(await menu.getAttribute('aria-expanded')==='false'&&await menu.evaluate(node=>node===document.activeElement),'escape restores original menu focus '+design+' zoom='+zoom);}
   }
  }
  check(errors.length===0&&external.length===0,'no script errors or external actions JS='+javascript);await context.close();
 }
 console.log(JSON.stringify({suite:'native-public-menu-browser',checks,taps,width:320,zoom:[1,2],javascript:[true,false],external_actions:0}));
}finally{await browser.close();}
