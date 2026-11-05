import {kDirectory,kOrigin,kStorage,kConfig,kSource} from './support/k-browser-client.mjs';
import assert from 'node:assert/strict';
import {readFile,writeFile} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
const root=fileURLToPath(new URL('../../',import.meta.url)),{chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const browser=await chromium.launch({executablePath:process.env.CHROMIUM_EXECUTABLE||'/usr/bin/chromium'});let checks=0,writes=0;const external=[],errors=[];
const check=(ok,label)=>{assert(ok,label);++checks;console.log('PASS '+label);};const fits=async p=>{await p.evaluate(()=>document.fonts.ready);await p.waitForLoadState('load');await p.waitForTimeout(100);return p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2);};
const label='Speichergrenze in MiB (0 = ohne Grenze)';
try{
 for(const standalone of [true,false]){
  const name=standalone?'standalone':'integrated',origin=kOrigin(standalone),f=JSON.parse(await readFile(kDirectory()+`/media-quota-${name}.json`,'utf8'));
  const url=origin+(standalone?'/?view=media&section=library':'/vereinsverwaltung/?view=website&tab=gallery&section=library');const storage=kStorage(standalone);
  for(const [width,js]of[[390,true],[320,true],[320,false],[820,true]]){
   const context=await browser.newContext({storageState:storage,javaScriptEnabled:js,viewport:{width,height:900},hasTouch:width<=390});context.setDefaultTimeout(15000);await context.route('**/*',r=>new URL(r.request().url()).origin===origin?r.continue():(external.push(r.request().url()),r.abort()));
   const current=await context.newPage(),stale=await context.newPage();for(const p of[current,stale])p.on('pageerror',e=>errors.push(e.message));await current.goto(url);await stale.goto(url);check(await fits(current)&&await current.locator('.media-library-card').count()===12,name+' counted library and quota fit '+width+' pixels');
   const old=await current.locator('input[name=quota_version]').inputValue();check((await current.locator('.media-quota details').getAttribute('open'))===null,name+' storage administration stays compact by default');
   for(const p of[current,stale])await p.locator('.media-quota summary').click();check((await current.getByLabel(label,{exact:true}).boundingBox()).width<=width,name+' opened native quota form fits');
   await current.getByLabel(label,{exact:true}).fill(String(f.test_mib));const save=current.getByRole('button',{name:'Speichergrenze speichern',exact:true});if(js)await save.click();else await Promise.all([current.waitForNavigation({waitUntil:'load'}),save.click()]);await current.waitForFunction(()=>document.body?.textContent?.includes('Bildspeichergrenze gespeichert.'));++writes;
   check(await current.locator('input[name=quota_version]').inputValue()===String(Number(old)+1),name+' successful form immediately carries current saved version');
   check(await current.getByLabel(label,{exact:true}).inputValue()===String(f.test_mib)&&await fits(current),name+' exact quota is saved in actual native manager');
   await stale.getByLabel(label,{exact:true}).fill(String(f.test_mib+1));const failed=[];stale.on('response',r=>{if(r.request().method()==='POST')failed.push(r.status());});const staleSave=stale.getByRole('button',{name:'Speichergrenze speichern',exact:true});if(js)await staleSave.click();else await Promise.all([stale.waitForNavigation({waitUntil:'load'}),staleSave.click()]);await stale.waitForFunction(()=>document.body?.textContent?.includes('Bildspeichergrenze inzwischen geändert.'));
   check(failed.some(status=>[409,422].includes(status)),name+' stale actual native form receives explicit conflict');check(await stale.getByLabel(label,{exact:true}).inputValue()===String(f.test_mib+1)&&await stale.locator('input[name=quota_version]').inputValue()===old&&await fits(stale),name+' conflict preserves entered value and old version without overwriting current policy');
   if((await current.locator('.media-quota details').getAttribute('open'))===null)await current.locator('.media-quota summary').click();await current.getByLabel(label,{exact:true}).fill(String(f.original_mib));const restore=current.getByRole('button',{name:'Speichergrenze speichern',exact:true});if(js)await restore.click();else await Promise.all([current.waitForNavigation({waitUntil:'load'}),restore.click()]);await current.waitForFunction(version=>document.querySelector('input[name=quota_version]')?.value===version,String(Number(old)+2));++writes;
   check(await current.getByLabel(label,{exact:true}).inputValue()===String(f.original_mib)&&await fits(current),name+' same successful form can restore original quota without reload');
   if(width===820){await current.evaluate(()=>document.body.style.zoom='2');const zoomFits=await fits(current);if(!zoomFits){console.log(JSON.stringify(await current.evaluate(()=>({width:innerWidth,scroll:document.documentElement.scrollWidth,overflow:[...document.querySelectorAll('main *')].filter(e=>{const r=e.getBoundingClientRect();return r.width>0&&r.right>innerWidth+2;}).slice(0,12).map(e=>({tag:e.tagName,classes:e.className,right:e.getBoundingClientRect().right,css:getComputedStyle(e).width}))}))));await current.screenshot({path:kDirectory()+`/media-quota-${name}-zoom-failed.png`,fullPage:false});}check(zoomFits,name+' complete policy and twelve-card library support 200 percent zoom');await current.evaluate(()=>document.body.style.zoom='');}
   if(width===390)await current.screenshot({path:kDirectory()+`/media-quota-${name}-390.png`,fullPage:false});await context.close();
  }
 }
 check(writes===16,'sixteen actual controlled policy saves restore both original limits');check(errors.length===0,'no browser runtime errors');check(external.length===0,'no external browser requests');console.log(JSON.stringify({checks,writes,external_service_calls:false}));
}finally{await browser.close();}
