import assert from 'node:assert/strict';
import {readFile,writeFile} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
const root=fileURLToPath(new URL('../../',import.meta.url)),{chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const browser=await chromium.launch({executablePath:process.env.CHROMIUM_EXECUTABLE||'/usr/bin/chromium'});
let checks=0,plans=0;const errors=[],external=[],assetErrors=[];
const check=(ok,label)=>{assert(ok,label);++checks;console.log('PASS '+label);};
const fits=async p=>{await p.waitForLoadState('load');await p.evaluate(()=>document.fonts.ready);return p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2);};
try{
 for(const standalone of [true,false]){
  const name=standalone?'standalone':'integrated',origin='http://127.0.0.1:'+(standalone?'8107':'8111'),f=JSON.parse(await readFile(root+`.local/browser-tests/personal-planning-${name}.json`,'utf8'));
  const url=(query)=>origin+(standalone?'/?':'/vereinsverwaltung/?')+new URLSearchParams(query);
  const itemUrl=item=>url(standalone?{view:'social',item:item.id}:{view:'social',native_item:item.id});
  let storage;const session=root+'.local/browser-tests/controls-sessions/admin.json';if(!standalone)try{storage=JSON.parse(await readFile(session,'utf8'));}catch(e){if(e.code!=='ENOENT')throw e;}
  const login=await browser.newContext({storageState:storage}),first=await login.newPage();login.setDefaultTimeout(15000);login.setDefaultNavigationTimeout(30000);
  await login.route('**/*',r=>new URL(r.request().url()).origin===origin?r.continue():(external.push(r.request().url()),r.abort()));
  await first.goto(itemUrl(f.items[0]));
  if(standalone){await first.getByLabel('Synthetische Demorolle',{exact:true}).selectOption('admin');await first.getByRole('button',{name:'Wechseln',exact:true}).click();await first.waitForFunction(()=>document.querySelector('#notice')?.textContent.includes('Lokale Demorolle gewechselt.'));}
  else if(new URL(first.url()).pathname==='/login/'){
   const credentials=JSON.parse(await readFile(root+'.local/native-cms-integration/integrated-manager-runtime/browser-credentials.json','utf8'));
   await first.getByLabel('E-Mail-Adresse oder persönlicher Benutzername',{exact:true}).fill(credentials.email);await first.getByLabel('Passwort',{exact:true}).fill(credentials.password);
   await Promise.all([first.waitForURL(u=>u.pathname==='/vereinsverwaltung/'),first.getByRole('button',{name:'Anmelden und Vereinsmanager öffnen',exact:true}).click()]);
  }
  storage=await login.storageState();if(!standalone)await writeFile(session,JSON.stringify(storage),{mode:0o600});await login.close();
  for(const item of f.items.filter(item=>item.browser!==false)){
   const context=await browser.newContext({storageState:storage,javaScriptEnabled:item.js,viewport:{width:item.width,height:900},hasTouch:item.width<=390}),p=await context.newPage();
   context.setDefaultTimeout(15000);context.setDefaultNavigationTimeout(30000);
   await context.route('**/*',r=>new URL(r.request().url()).origin===origin?r.continue():(external.push(r.request().url()),r.abort()));p.on('pageerror',e=>errors.push(e.message));p.on('response',r=>{if(['stylesheet','script'].includes(r.request().resourceType())&&r.status()>=400)assetErrors.push(new URL(r.url()).pathname);});
   check((await p.goto(itemUrl(item))).status()===200,name+' approved original opens '+item.width+' '+item.js);check(await fits(p),name+' saved editor fits mobile/tablet width');
   const jump=p.getByRole('link',{name:'Planung öffnen',exact:true});check(await jump.count()===1,name+' approval has a direct native jump');await jump.click();
   check(await p.locator('#publication-planning').evaluate(el=>{const r=el.getBoundingClientRect();return r.top>=0&&r.top<innerHeight;}),name+' native jump reaches approval without walking the long editor');
   const detail=p.locator('.personal-publication');check(await detail.count()===1,name+' approved event offers its own personal publication choice');
   const summary=detail.locator('summary');if(item.width<=390)await summary.tap();else{await summary.focus();await p.keyboard.press('Enter');}
   check(await detail.evaluate(el=>el.open),name+' touch or keyboard opens the original target');
   const form=detail.locator('form');check(await form.locator('[name=city_scheduled_at]').inputValue()===''&&!await form.locator('[name=city_confirm]').isChecked(),name+' time and consent start empty');
   const button=form.getByRole('button',{name:'StadtLand.Funk einplanen',exact:true});check((await button.boundingBox()).height>=44,name+' personal submit is a real touch target');
   if(item.width===320){
    await form.locator('[name=city_scheduled_at]').fill('2030-03-31T02:30');await form.locator('[name=city_confirm]').check();
    const response=p.waitForResponse(r=>r.request().method()==='POST'&&new URL(r.url()).origin===origin);if(item.js)await button.click();else await Promise.all([p.waitForNavigation({waitUntil:'load'}),button.click()]);check((await response).status()===422,name+' actual nonexistent Berlin time rejects the form');
    if(item.js)await p.waitForFunction(()=>document.querySelector('#notice,[data-native-status],.flash')?.textContent.includes('Uhrzeit')||document.body?.textContent.includes('lokale Uhrzeit muss existieren'));
    else await p.waitForLoadState('domcontentloaded');
    check(await p.locator('.personal-publication [name=city_scheduled_at]').inputValue()==='2030-03-31T02:30',name+' rejected native form preserves the selected time');
    check(await p.locator('.personal-publication [name=city_confirm]').isChecked(),name+' unchanged original keeps its explicit target choice after validation error');
    check(await fits(p),name+' error recovery retains a mobile usable editor');
   }
   await p.locator('.personal-publication [name=city_scheduled_at]').fill('2098-10-10T12:00');await p.locator('.personal-publication [name=city_confirm]').check();
   const submit=p.getByRole('button',{name:'StadtLand.Funk einplanen',exact:true});if(item.js)await submit.click();else await Promise.all([p.waitForNavigation({waitUntil:'load'}),submit.click()]);await p.waitForSelector('.publication-history');plans++;
   const job=new URL(p.url()).searchParams.get('queue_job');check(/^[a-f0-9]{32}$/.test(job||''),name+' completed form preserves its exact original job URL');
   const focused=p.locator('[data-publication-focus]');check(await focused.count()===1&&await focused.locator('h3').textContent()===item.title,name+' exact original is located on its actual history page');
   check(await focused.locator('.publication-row-heading').textContent()==='StadtLand.Funk · VeranstaltungGeplant',name+' target, content type and real pending state are distinct');
   check(await p.locator('.publication-row').count()<=10&&await fits(p),name+' successful route is short and fits the display');
   check(await focused.locator('[name=operation]').count()===0,name+' pending original has no premature provider reconciliation or repeat button');
   if(item.js){await p.waitForFunction(()=>document.activeElement?.hasAttribute('data-publication-focus'));check(await focused.evaluate(el=>{const r=el.getBoundingClientRect();return r.top>=0&&r.top<innerHeight;}),name+' post-submit keyboard focus reaches the exact original');}
   if(item.width===820){await p.evaluate(()=>document.body.style.zoom='2');check(await fits(p),name+' personal history works at 200 percent zoom');await p.evaluate(()=>document.body.style.zoom='');}
   await p.goto(url({view:'social',section:'planning',queue_provider:'stadtlandfunk'}));check(await p.locator('.publication-row').count()===10,name+' filled personal history is bounded to ten');
   check(!(await p.locator('.publication-history').textContent()).includes('sf:own_temp')&&await p.locator('input[value=meta-reconcile]').count()===0,name+' personal timeline contains neither protocol internals nor Meta actions');
   const next=p.getByRole('link',{name:'Weiter',exact:true}).first();await next.click();await p.waitForURL(u=>u.searchParams.get('queue_page')==='2');check(await fits(p)&&await p.locator('.publication-row').count()<=10,name+' actual next page retains provider filter and bounded layout');
   await p.goto(url({view:'social',section:'planning',queue_job:f.unknown_job}));const original=p.locator('[data-publication-focus]');
   check((await original.locator('.publication-row-heading').textContent()).includes('Ergebnis prüfen'),name+' unknown original is labelled honestly');
   const review=original.getByRole('button',{name:'Status abgleichen',exact:true});if(item.js)await review.click();else await Promise.all([p.waitForNavigation({waitUntil:'load'}),review.click()]);await p.waitForFunction(()=>document.body?.textContent.includes('Ergebnis weiterhin unklar.'));await p.waitForSelector('[data-publication-focus]');
   check(new URL(p.url()).searchParams.get('queue_job')===f.unknown_job&&(await p.locator('[data-publication-focus] .publication-row-heading').textContent()).includes('Ergebnis prüfen'),name+' empty upload proof remains uncertain on the same exact original');
   check(await fits(p),name+' read-only review result retains mobile usability');
   if(item.width===390)await p.screenshot({path:root+`.local/browser-tests/personal-planning-${name}-390.png`,fullPage:false});
   await context.close();
  }
 }
 check(plans===8,'eight actual owned future plans, no provider attempts');check(errors.length===0,'no browser runtime errors');check(assetErrors.length===0,'all actual application scripts and styles are served');check(external.length===0,'browser makes no external requests');console.log(JSON.stringify({checks,plans,live_provider_transport:false}));
}finally{await browser.close();}
