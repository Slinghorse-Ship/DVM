import assert from 'node:assert/strict';
import {readFile,writeFile} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
const root=fileURLToPath(new URL('../../',import.meta.url)),{chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const browser=await chromium.launch({executablePath:process.env.CHROMIUM_EXECUTABLE||'/usr/bin/chromium'});
let checks=0,writes=0;const external=[],errors=[],assets=[];
const check=(ok,label)=>{assert(ok,label);++checks;console.log('PASS '+label);};
const fits=p=>p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2);
try{
 for(const standalone of [true,false]){
  const name=standalone?'standalone':'integrated',origin='http://127.0.0.1:'+(standalone?'8107':'8111');
  const f=JSON.parse(await readFile(root+`.local/browser-tests/image-usage-${name}.json`,'utf8'));
  const url=item=>origin+(standalone?'/?':'/vereinsverwaltung/?')+new URLSearchParams(standalone?{view:'media',section:'library',item:item.id}:{view:'website',tab:'gallery',section:'library',native_item:item.id});
  const session=root+'.local/browser-tests/controls-sessions/admin.json';let storage;if(!standalone)storage=JSON.parse(await readFile(session,'utf8'));
  const login=await browser.newContext({storageState:storage}),p=await login.newPage();await p.goto(url(f.items[0]));
  if(standalone){await p.getByLabel('Synthetische Demorolle',{exact:true}).selectOption('admin');await p.getByRole('button',{name:'Wechseln',exact:true}).click();await p.waitForFunction(()=>document.querySelector('#notice')?.textContent.includes('Lokale Demorolle gewechselt.'));}
  else if(new URL(p.url()).pathname==='/login/'){
   const c=JSON.parse(await readFile(root+'.local/native-cms-integration/integrated-manager-runtime/browser-credentials.json','utf8'));
   await p.getByLabel('E-Mail-Adresse oder persönlicher Benutzername',{exact:true}).fill(c.email);await p.getByLabel('Passwort',{exact:true}).fill(c.password);await Promise.all([p.waitForURL(u=>u.pathname==='/vereinsverwaltung/'),p.getByRole('button',{name:'Anmelden und Vereinsmanager öffnen',exact:true}).click()]);
  }
  storage=await login.storageState();if(!standalone)await writeFile(session,JSON.stringify(storage),{mode:0o600});await login.close();
  for(const item of f.items){
   const context=await browser.newContext({storageState:storage,javaScriptEnabled:item.js,viewport:{width:item.width,height:900},hasTouch:item.width<=390});context.setDefaultTimeout(15000);const editor=await context.newPage(),reader=await context.newPage();
   const configuredOrigin=new URL(item.view_url).origin;
   await context.route('**/*',async r=>{const u=new URL(r.request().url());if(u.origin===origin)return r.continue();if(u.origin===configuredOrigin&&u.pathname.startsWith('/publication-image/'))return r.fulfill({response:await r.fetch({url:origin+u.pathname})});external.push(u.href);return r.abort();});
   for(const page of [editor,reader]){page.on('pageerror',e=>errors.push(e.message));page.on('response',r=>{if(['stylesheet','script'].includes(r.request().resourceType())&&r.status()>=400)assets.push(r.url());});}
   const viewUrl=origin+new URL(item.view_url).pathname+new URL(item.view_url).search;
   const before=await reader.goto(viewUrl);check(before.status()===200,name+' actual frozen newsletter endpoint opens');const original=await before.text();
   await reader.waitForFunction(()=>[...document.querySelectorAll('article img')].every(i=>i.complete&&i.naturalWidth>0));
   check(await reader.locator('article img').filter({visible:true}).count()>0,name+' actual frozen original images are decoded');
   check(await reader.locator(`img[alt="${item.alt}"]`).count()===1&&(await reader.locator('article').textContent()).includes(item.credit),name+' newsletter shows saved original alternative and credit');
   check(await reader.locator('meta[name=viewport]').count()===1&&await fits(reader),name+' actual newsletter fits '+item.width+' pixel viewport');
   const source=reader.getByRole('link',{name:'Eigene Quelle',exact:true});check(await source.count()===1&&(await source.boundingBox()).height>=44,name+' original source link remains touch friendly');
   if(item.width===820){await reader.evaluate(()=>document.body.style.zoom='2');check(await fits(reader),name+' original newsletter supports 200 percent zoom');await reader.evaluate(()=>document.body.style.zoom='');}
   check((await reader.request.get(origin+item.social_url)).status()===200,name+' own approved social JPEG is available before withdrawal');
   await editor.goto(url(item));await editor.locator('.media-metadata-form details summary').nth(1).click();await editor.getByLabel('Bildrechte',{exact:true}).selectOption('withdrawn');const save=editor.getByRole('button',{name:'Bildangaben speichern',exact:true});
   if(item.js)await save.click();else await Promise.all([editor.waitForNavigation({waitUntil:'load'}),save.click()]);await editor.waitForFunction(()=>document.body.textContent.includes('Bildangaben gespeichert.'));++writes;
   check(await editor.getByLabel('Bildrechte',{exact:true}).inputValue()==='withdrawn'&&await fits(editor),name+' actual metadata withdrawal is saved without losing mobile editor');
   check((await reader.request.get(origin+item.social_url)).status()===404,name+' actual social image endpoint stops withdrawn source');
   check((await reader.request.get(origin+new URL(item.photo_url).pathname)).status()===404,name+' actual newsletter image endpoint stops withdrawn source');
   check(await (await reader.request.get(viewUrl)).text()===original,name+' original newsletter HTML remains byte-identical after withdrawal');
   await reader.reload();check((await reader.locator('article').textContent()).includes(item.credit)&&await fits(reader),name+' original caption and readable layout remain after withdrawal');
   if(item.width===390)await reader.screenshot({path:root+`.local/browser-tests/image-usage-${name}-390.png`,fullPage:false});
   await context.close();
  }
 }
 check(writes===8,'eight actual own withdrawals, no mail or provider job');check(errors.length===0,'no browser runtime errors');check(assets.length===0,'all actual newsletter and manager assets are served');check(external.length===0,'zero external service requests');console.log(JSON.stringify({checks,writes,external_service_calls:false}));
}finally{await browser.close();}
