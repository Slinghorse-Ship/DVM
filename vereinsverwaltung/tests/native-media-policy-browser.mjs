import {kDirectory,kOrigin,kStorage,kConfig,kSource} from './support/k-browser-client.mjs';
import assert from 'node:assert/strict';
import {readFile,writeFile} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
const root=fileURLToPath(new URL('../../',import.meta.url));
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const browser=await chromium.launch({executablePath:'/usr/bin/chromium'});
let checks=0,writes=0;const errors=[],external=[];
const check=(ok,label)=>{assert(ok,label);++checks;console.log('PASS '+label);};
try{
 for(const standalone of[true,false]){
  const name=standalone?'standalone':'integrated',origin=kOrigin(standalone);
  const f=JSON.parse(await readFile(kDirectory()+`/media-policy-${name}.json`,'utf8')),bytes=await readFile(f.source);
  const url=origin+(standalone?'/?view=media&section=library':'/vereinsverwaltung/?view=website&tab=gallery&section=library');
  for(const [index,[width,js]]of[[390,true],[320,true],[320,false]].entries()){
   const context=await browser.newContext({storageState:kStorage(standalone),viewport:{width,height:950},hasTouch:true,javaScriptEnabled:js});context.setDefaultTimeout(20000);
   await context.route('**/*',r=>new URL(r.request().url()).origin===origin?r.continue():(external.push(r.request().url()),r.abort()));const p=await context.newPage();p.on('pageerror',e=>errors.push(e.message));
   await p.goto(url);const tag=name+' '+width+' '+(js?'JS':'native');
   const click=async button=>js?button.click():Promise.all([p.waitForNavigation({waitUntil:'load'}),button.click()]);
   let panel=p.locator('.media-policy');check(await panel.count()===1&&!(await panel.evaluate(e=>e.open)),tag+' optional global controls start collapsed');
   await panel.locator('summary').click();check((await panel.locator('summary').boundingBox()).height>=44,tag+' policy disclosure supports touch');
   const selects=panel.locator('select');check(await selects.nth(0).inputValue()==='0'&&await selects.nth(1).inputValue()==='0',tag+' both optional checks start disabled');
   await selects.nth(0).selectOption('1');await selects.nth(1).selectOption('1');await click(panel.getByRole('button',{name:'Bildprüfung speichern',exact:true}));++writes;
   await p.waitForFunction(()=>(document.body?.textContent||'').includes('Bildprüfung gespeichert.'));
   await p.goto(url);panel=p.locator('.media-policy');check(await panel.locator('select').nth(0).inputValue()==='1'&&await panel.locator('select').nth(1).inputValue()==='1',tag+' actual saved policy survives navigation');
   let upload=p.locator('.media-upload');await upload.locator(':scope > summary').click();let input=upload.locator('input[type=file]');
   check(await input.getAttribute('multiple')!==null&&await upload.locator('input[name=alt]').getAttribute('required')===null,tag+' multi selection needs no metadata form');
   const selected=[{name:f.prefix+index+'-a.png',mimeType:'image/png',buffer:bytes},{name:f.prefix+index+'-bad.png',mimeType:'image/png',buffer:Buffer.from('own invalid image')},{name:f.prefix+index+'-b.png',mimeType:'image/png',buffer:bytes}];
   await input.setInputFiles(selected);if(!js)await click(upload.getByRole('button',{name:'Hochladen',exact:true}));writes+=2;
   await p.waitForFunction(()=>{const text=(document.body?.textContent||'');return /2 (Bilder|Bilder hochgeladen)/.test(text)&&text.includes('fehlgeschlagen');});
   await p.waitForLoadState('load');await p.waitForFunction(()=>[...document.querySelectorAll('link[rel=stylesheet]')].every(link=>link.sheet));await p.evaluate(()=>document.fonts.ready);
   const geometry=await p.evaluate(()=>({width:innerWidth,scroll:document.documentElement.scrollWidth,overflow:[...document.querySelectorAll('body *')].filter(n=>n.checkVisibility({checkVisibilityCSS:true})&&n.getBoundingClientRect().right>innerWidth+2).slice(0,30).map(n=>({tag:n.tagName,id:n.id,classes:n.className,right:n.getBoundingClientRect().right,width:n.getBoundingClientRect().width,wrap:getComputedStyle(n).overflowWrap}))}));
   if(geometry.scroll>geometry.width+2){const proof=kDirectory()+'/'+name+'-policy-overflow-'+width+'-'+(js?'js':'nojs');await writeFile(proof+'.json',JSON.stringify(geometry,null,2),{flag:'wx',mode:0o600});await writeFile(proof+'.html',await p.content(),{flag:'wx',mode:0o600});await p.screenshot({path:proof+'.png'});console.log('Owned upload-policy overflow',JSON.stringify(geometry));}
   check(geometry.scroll<=geometry.width+2,tag+' partial success and error remain within viewport');if(!js)await p.screenshot({path:kDirectory()+'/'+name+'-policy-nojs-320.png'});
   await p.goto(url);upload=p.locator('.media-upload');await upload.locator(':scope > summary').click();input=upload.locator('input[type=file]');await input.setInputFiles([{name:f.prefix+index+'-single.png',mimeType:'image/png',buffer:bytes}]);if(!js)await click(upload.getByRole('button',{name:'Hochladen',exact:true}));++writes;
   await p.waitForFunction(()=>{const text=(document.body?.textContent||'');return /Bild (hochgeladen|gespeichert)|1 Bilder hochgeladen|1 Bild aufgenommen/.test(text);});
   check(true,tag+' actual single upload succeeds with checks active and no supplied metadata');
   await p.goto(url);panel=p.locator('.media-policy');await panel.locator('summary').click();await panel.locator('select').nth(0).selectOption('0');await panel.locator('select').nth(1).selectOption('0');await click(panel.getByRole('button',{name:'Bildprüfung speichern',exact:true}));++writes;
   await p.waitForFunction(()=>(document.body?.textContent||'').includes('Bildprüfung gespeichert.'));
   await p.goto(url);check(await p.locator('.media-policy select').nth(0).inputValue()==='0'&&await p.locator('.media-policy select').nth(1).inputValue()==='0',tag+' checks are independently reversible');
   check(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),tag+' restored list fits the mobile viewport');
   await context.close();
  }
 }
 check(errors.length===0,'no browser runtime errors');check(external.length===0,'no external browser requests');console.log(JSON.stringify({checks,writes,external_service_calls:false}));
}finally{await browser.close();}
