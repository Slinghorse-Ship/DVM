import assert from 'node:assert/strict';
import {readFile,writeFile,mkdir} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const root=fileURLToPath(new URL('../../',import.meta.url));
const isolated=process.env.DVM_WEBSITE_LAYOUTS_FIXTURE?JSON.parse(await readFile(process.env.DVM_WEBSITE_LAYOUTS_FIXTURE,'utf8')):null;
const browser=await chromium.launch({executablePath:'/usr/bin/chromium'});
const names={klar:'Klar',magazin:'Magazin',aktiv:'Aktiv',natur:'Natur',kulturhus:'Kulturhus'};
let checks=0,saves=0;
const check=(ok,label)=>{assert(ok,label);checks++;console.log('PASS '+label);};
const fits=async page=>{const result=await page.evaluate(()=>({ok:document.documentElement.scrollWidth<=innerWidth+2,width:innerWidth,scroll:document.documentElement.scrollWidth,bad:[...document.querySelectorAll('body *')].map(n=>({tag:n.tagName,cls:n.className,box:n.getBoundingClientRect(),text:n.textContent.slice(0,90)})).filter(n=>n.box.right>innerWidth+2||n.box.left< -2).slice(0,10).map(n=>({tag:n.tag,cls:n.cls,x:n.box.x,width:n.box.width,text:n.text}))}));if(!result.ok){result.text=await page.evaluate(()=>{const out=[],w=document.createTreeWalker(document.body,NodeFilter.SHOW_TEXT);for(let n=w.nextNode();n&&out.length<12;n=w.nextNode()){if(!n.textContent.trim()||['SCRIPT','STYLE'].includes(n.parentElement.tagName))continue;const r=document.createRange();r.selectNode(n);const b=r.getBoundingClientRect();if(b.right>innerWidth+2||b.left< -2)out.push({text:n.textContent.trim().slice(0,100),tag:n.parentElement.tagName,cls:n.parentElement.className,x:b.x,width:b.width});}return{nodes:out,body:document.body.getBoundingClientRect().width,client:document.documentElement.clientWidth,zoom:getComputedStyle(document.documentElement).zoom};});console.log(JSON.stringify({design_overflow:result}));await page.screenshot({path:'/workspace/recovery-notes/website-design-overflow-1855.png',fullPage:true});await writeFile('/workspace/recovery-notes/website-design-overflow-1855.html',await page.content());}return result.ok;};
const screens=isolated?isolated.root+'/design-screens':root+'.local/browser-tests/design-screens';await mkdir(screens,{recursive:true});
try{for(const mode of (isolated?['integrated']:['integrated','standalone'])){
 const origin=isolated?isolated.origin:mode==='integrated'?'http://127.0.0.1:8111':'http://127.0.0.1:8107';
 const fixture=isolated?isolated.data:JSON.parse(await readFile(root+'.local/browser-tests/website-design-'+mode+'.json','utf8'));
 const saved=!isolated&&mode==='integrated'?process.env.WEBSITE_DESIGN_BROWSER_STORAGE:null;const context=await browser.newContext(saved?{storageState:saved}:{}),errors=[],external=[];
 const restrict=async c=>c.route('**/*',r=>new URL(r.request().url()).origin===origin?r.continue():(external.push(r.request().url()),r.abort()));
 await restrict(context);const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
 if(isolated){await page.goto(origin+'/own-test-enter?role=admin');}else if(mode==='integrated'&&!saved){
  const credentials=JSON.parse(await readFile(root+'.local/native-cms-integration/integrated-manager-runtime/browser-credentials.json','utf8')).admin;
  await page.goto(origin+'/login');await page.locator('[name=email]').fill(credentials.email);await page.locator('[name=password]').fill(credentials.password);
  await Promise.all([page.waitForURL('**/vereinsverwaltung/**'),page.getByRole('button',{name:'Anmelden und Vereinsmanager öffnen',exact:true}).click()]);
 }
 const settings=origin+(mode==='integrated'?'/vereinsverwaltung/?view=website&tab=integrations':'/?view=settings');
 const preview=key=>origin+(mode==='integrated'?'/vereinsverwaltung/?native_endpoint=design-preview&template='+key:'/design-preview?template='+key);
 const active=async()=>{await page.goto(settings);return await page.locator('[data-current-design]').innerText();};
 const choose=async(p,key)=>{
  await p.goto(settings);const card=p.locator('[data-design="'+key+'"]');
  if(await card.locator('button').count()){
   const posted=p.waitForResponse(r=>r.request().method()==='POST');await card.locator('button').click();const response=await posted;await p.waitForLoadState('domcontentloaded');if(![200,303].includes(response.status()))console.log(JSON.stringify({design_failed:{status:response.status(),expected:key,url:p.url(),alerts:await p.locator('[role=alert]').allTextContents()}}));await p.waitForFunction(name=>document.querySelector('[data-current-design]')?.textContent===name,names[key]);saves++;
  }
 };
 if(process.env.DVM_WEBSITE_LAYOUTS_NOJS_ONLY!=='1'){
 await active();check(await page.locator('.website-design-card').count()===5,mode+' all five templates offered');
 const baseline=await(await context.request.get(origin+'/site/start')).text();
 const sampleLayouts=new Set();
 for(const width of (isolated?[390,320,820,1440]:[1440,820,390])){
  await page.setViewportSize({width,height:950});await page.goto(settings);
  check(await fits(page),mode+' selection fits '+width);
  check(await page.locator('.website-design-actions a').evaluateAll(nodes=>nodes.every(n=>n.getBoundingClientRect().height>=44)),mode+' preview targets at least 44px '+width);
  for(const key of Object.keys(names)){
   const link=page.locator('[data-design="'+key+'"] a');check((await link.getAttribute('href')).includes('template='+key),mode+' canonical preview '+key+' '+width);
   const response=await page.goto(preview(key));
   check(response.ok()&&response.headers()['cache-control'].includes('no-store')&&response.headers()['x-robots-tag'].includes('noindex'),mode+' authenticated uncached preview '+key+' '+width);
   check(await page.locator('body').evaluate((b,k)=>b.classList.contains(k==='kulturhus'?'theme-kultur':'design-'+k),key),mode+' actual selected public layout '+key+' '+width);
   check(await fits(page),mode+' preview fits '+key+' '+width);
   if(width===1440){sampleLayouts.add(await page.locator('.hero--home').evaluate(n=>{const s=getComputedStyle(n);return [s.gridTemplateColumns,s.textAlign,s.backgroundColor,getComputedStyle(n.querySelector('h1')).fontFamily].join('|');}));await page.screenshot({path:screens+'/'+mode+'-'+key+'.png',fullPage:true});}
   check(await page.locator('.site-footer a').evaluateAll(nodes=>nodes.every(n=>{const r=n.getBoundingClientRect();return r.height>=44&&r.width>=44;})),mode+' all footer targets at least 44px '+key+' '+width);
   check(await page.locator('.site-header .brand,.news-card h2 a,.news-card h3 a').evaluateAll(nodes=>nodes.every(n=>{const r=n.getBoundingClientRect();return r.height>=44&&r.width>=44;})),mode+' brand and news title targets at least 44px '+key+' '+width);
   if(width===390){const menu=page.locator('button.site-menu-toggle');check(await menu.evaluate(n=>getComputedStyle(n).opacity==='1'&&getComputedStyle(n).position!=='absolute'),mode+' visible public menu button without Bootstrap dropdown collision '+key);await menu.focus();await menu.press('Enter');check(await menu.getAttribute('aria-expanded')==='true',mode+' keyboard public menu '+key);check(await page.locator('.site-header nav a,button.site-menu-toggle').evaluateAll(nodes=>nodes.every(n=>{const r=n.getBoundingClientRect();return r.height>=44&&r.width>=44;})),mode+' open navigation targets at least 44px '+key);await menu.press('Escape');check(await menu.getAttribute('aria-expanded')==='false'&&await menu.evaluate(n=>n===document.activeElement),mode+' escape closes menu and returns focus '+key);}
   await page.goto(settings);
  }
 }
 check(sampleLayouts.size===5,mode+' templates differ in actual layout or typography');
 check(await(await context.request.get(origin+'/site/start')).text()===baseline,mode+' all previews preserve actual public HTML');
 await page.setViewportSize({width:390,height:950});await page.goto(settings);await page.evaluate(()=>document.documentElement.style.zoom='2');
 check(await fits(page),mode+' selection fits 200 percent zoom');
 const touch=await browser.newContext({storageState:await context.storageState(),viewport:{width:390,height:844},hasTouch:true,isMobile:true});await restrict(touch);const touchPage=await touch.newPage();await touchPage.goto(settings);const [popup]=await Promise.all([touch.waitForEvent('page'),touchPage.locator('[data-design="klar"] a').tap()]);await popup.waitForLoadState();check(await popup.locator('body.design-klar').count()===1,mode+' touch opens private template preview');await touch.close();
 await page.goto(settings);const staleForm=await page.locator('[data-design="klar"] form').evaluate(n=>Object.fromEntries(new FormData(n)));
 for(const key of Object.keys(names)){
  await choose(page,key);const html=await(await context.request.get(origin+'/site/start')).text();check(html.includes('class="'+(key==='kulturhus'?'theme-kultur':'design-'+key)),mode+' explicit public design switch '+key);
 }
 const conflict=await context.request.post(settings,{form:staleForm});check([409,422].includes(conflict.status()),mode+' stale design selection rejected');
 const invalid=await context.request.post(settings,{form:{...staleForm,operation:'website-design',website_template:'../../bad'}});check([409,422].includes(invalid.status()),mode+' unknown template rejected');
 const forged=await context.request.post(settings,{form:{...staleForm,csrf:'forged'}});check([403,409,422].includes(forged.status()),mode+' forged design CSRF rejected');
 }
 const nojs=await browser.newContext({storageState:await context.storageState(),javaScriptEnabled:false,viewport:{width:390,height:950}});await restrict(nojs);const plain=await nojs.newPage();await choose(plain,'natur');check(await plain.locator('[data-current-design]').innerText()==='Natur',mode+' native form works without JavaScript');await choose(plain,fixture.template);const correctionForm=await plain.locator('.website-design-actions form').first().evaluate(n=>Object.fromEntries(new FormData(n)));const correction=await nojs.request.post(settings,{form:{...correctionForm,website_template:'invalid-template'}});check(correction.status()===422&&(await correction.text()).includes('website-design-card'),mode+' JavaScript-free invalid selection retains correction form');if(isolated){await plain.setViewportSize({width:320,height:950});for(const key of Object.keys(names)){await plain.goto(preview(key));await plain.evaluate(()=>document.documentElement.style.zoom='2');check(await plain.locator('.site-header nav a').count()===5,mode+' five original navigation destinations without JavaScript '+key);check(await plain.locator('.site-header nav a').evaluateAll(nodes=>nodes.every(n=>{const r=n.getBoundingClientRect();return r.width>0&&r.height>=44;})),mode+' actual navigation remains visible without JavaScript '+key);check(await fits(plain),mode+' populated public design fits 320 at 200 percent without JavaScript '+key);check(await plain.locator('.site-header .site-menu-toggle').isHidden(),mode+' no inactive menu button without JavaScript '+key);await plain.screenshot({path:screens+'/'+key+'-mobile-nojs-zoom.png',fullPage:true});}}await nojs.close();
 const endpoint=preview('klar');const head=await context.request.head(endpoint);check(head.ok()&&(await head.body()).length===0,mode+' preview HEAD has no body');
 const post=await context.request.post(endpoint);check(post.status()===405,mode+' preview rejects POST');
 check(errors.length===0,mode+' no browser script errors');check(external.length===0,mode+' no external requests or publications');await context.close();
 }
 const result={suite:'native-website-design-browser',checks,saves,modes:isolated?['integrated']:['integrated','standalone'],widths:isolated?[1440,820,390,320]:[1440,820,390],external_actions:0};if(!isolated)await writeFile(root+'.local/browser-tests/website-design-result.json',JSON.stringify(result)+'\n',{mode:0o600});console.log(JSON.stringify(result));
}finally{await browser.close();}
