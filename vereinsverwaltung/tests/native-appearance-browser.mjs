import {readFile,writeFile,mkdir} from 'node:fs/promises';
import assert from 'node:assert/strict';
const fixture=JSON.parse(await readFile(process.env.DVM_APPEARANCE_FIXTURE,'utf8'));
const origins=Object.values(fixture.origins);
for(const origin of origins)if(!/^http:\/\/127\.0\.0\.1:[0-9]+$/.test(origin))throw new Error('Own loopback appearance fixture required.');
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE||'/usr/bin/chromium',args:['--no-sandbox']});
const artifacts=process.env.DVM_APPEARANCE_ARTIFACTS||'/tmp/kulturhus-appearance-browser-proof';await mkdir(artifacts,{recursive:true});
const errors=[],assets=new Map();let external=0,checks=0,views=0;
const check=(ok,label)=>{assert.ok(ok,label);checks++;};
const account=origin=>origin+'/account?section=appearance#account-appearance';
const manager=(origin,app,query={})=>origin+(app==='native'?'/':'/vereinsverwaltung/')+'?'+new URLSearchParams({view:'website',...(app==='integrated'?{tab:'pages'}:{}),...query});
const form=page=>page.locator('[data-appearance-form]');
const values=page=>form(page).evaluate(node=>Object.fromEntries(new FormData(node)));
const choose=async(page,origin,view,color='light',density='comfortable')=>{
 await page.goto(account(origin));const before=Number((await values(page)).appearance_version);
 await form(page).locator('input[name="admin_view"][value="'+view+'"]').check();await form(page).locator('select[name="admin_color"]').selectOption(color);await form(page).locator('select[name="admin_density"]').selectOption(density);
 await form(page).getByRole('button',{name:'Ansicht speichern',exact:true}).click({noWaitAfter:true});await form(page).locator('input[name="appearance_version"][value="'+(before+1)+'"]').waitFor({state:'attached'});
 check(await page.locator('body').getAttribute('data-admin-view')===view,'actual saved account uses its selected '+view+' layout');
};
async function readableAdministration(page,label){
 // Force a paint, then let Bootstrap's 150ms colour transitions finish.
 // Use the automation clock: document timers do not run with JavaScript disabled.
 await page.screenshot({animations:'disabled'});await page.waitForTimeout(200);
 const report=await page.evaluate(()=>{
  const canvas=document.createElement('canvas');canvas.width=canvas.height=1;const ctx=canvas.getContext('2d',{willReadFrequently:true});
  const rgba=value=>{ctx.clearRect(0,0,1,1);ctx.fillStyle=value;ctx.fillRect(0,0,1,1);return [...ctx.getImageData(0,0,1,1).data].map((v,i)=>i===3?v/255:v);};
  const over=(a,b)=>[0,1,2].map(i=>a[i]*a[3]+b[i]*(1-a[3])).concat(1);
  const background=node=>{const chain=[];for(let n=node;n;n=n.parentElement)chain.unshift(n);return chain.reduce((color,n)=>over(rgba(getComputedStyle(n).backgroundColor),color),[255,255,255,1]);};
  const lum=c=>c.slice(0,3).map(v=>{v/=255;return v<=.04045?v/12.92:((v+.055)/1.055)**2.4;}).reduce((s,v,i)=>s+v*[.2126,.7152,.0722][i],0);
  const rendered=node=>{if(!node.getClientRects().length||!node.checkVisibility({checkVisibilityCSS:true}))return false;for(let parent=node.parentElement;parent;parent=parent.parentElement)if(parent.matches('details:not([open])')&&!parent.querySelector(':scope > summary')?.contains(node))return false;return true;};
  const failures=[];let checked=0;const inspect=(node,color,text,large=false)=>{const bg=background(node),fg=over(rgba(color),bg),a=lum(fg),b=lum(bg),ratio=(Math.max(a,b)+.05)/(Math.min(a,b)+.05);checked++;if(ratio+.01<(large?3:4.5))failures.push({tag:node.tagName,class:node.className,text:text.slice(0,70),ratio:Number(ratio.toFixed(2)),foreground:color,background:bg.slice(0,3)});};
  for(const node of document.querySelectorAll('h1,h2,h3,label,legend,summary,.btn,.button,.club-label,.module-tabs a,.sidebar a')){const style=getComputedStyle(node);if(!rendered(node)||style.visibility==='hidden'||node.matches(':disabled,[aria-disabled=true]')||!node.textContent.trim())continue;const size=parseFloat(style.fontSize),large=size>=24||(size>=18.66&&Number(style.fontWeight)>=700);inspect(node,style.color,node.textContent.trim(),large);}
  for(const node of document.querySelectorAll('input[placeholder],textarea[placeholder]'))if(rendered(node)&&!node.disabled&&node.placeholder)inspect(node,getComputedStyle(node,'::placeholder').color,node.placeholder);
  return{checked,failures};
 });
 check(report.checked>=5,label+' contains actual rendered administration text');
 if(report.failures.length)await writeFile(artifacts+'/contrast-failures.json',JSON.stringify({label,...report},null,2));
 check(report.failures.length===0,label+' text and placeholders meet WCAG contrast: '+JSON.stringify(report.failures));
}
// The guarded fixture emulates HTTPS over its own loopback transport only.
// Keep the actual production Secure-cookie assertion, then adapt only this test jar.
async function enterAccount(context,origin,mode,source=null){
 const entered=await context.request.get(origin+'/own-test-enter?'+new URLSearchParams({mode,...(source?{source_app:source}:{})}),{maxRedirects:0});
 check(entered.status()===303,'actual account entry establishes its original session before redirect');
 if(origin===fixture.origins.integrated){const header=entered.headers()['set-cookie']||'',cookie=header.match(/(?:^|\n)KH_NATIVE_MANAGER=([^;\r\n]+)/);check(cookie&&/;\s*secure(?:;|$)/i.test(header),'actual production session retains its HTTPS-only cookie contract');await context.addCookies([{name:'KH_NATIVE_MANAGER',value:decodeURIComponent(cookie[1]),url:origin,secure:false,httpOnly:true,sameSite:'Strict'}]);}
 return context.request.get(account(origin));
}
const openContext=async(app,js,mode)=>{
 const origin=fixture.origins[app],context=await browser.newContext({javaScriptEnabled:js,viewport:{width:390,height:844}});context.setDefaultNavigationTimeout(60000);
 await context.route('**/*',async route=>{const url=new URL(route.request().url());if(!origins.includes(url.origin)){external++;return route.abort();}
  if(route.request().method()==='GET'&&Object.hasOwn(fixture.static_assets||{},url.pathname)){const file=fixture.static_assets[url.pathname];return route.fulfill({status:200,contentType:url.pathname.endsWith('.css')?'text/css; charset=utf-8':'text/javascript; charset=utf-8',body:await readFile(file)});}
  if(route.request().method()==='GET'&&/\.(?:css|js|mjs|svg|woff2?)(?:$)/.test(url.pathname)){
   if(!assets.has(url.href))assets.set(url.href,(async()=>{const response=await route.fetch({timeout:60000});const headers=response.headers();if(response.status()!==200||!/(?:css|javascript|svg|font|woff)/i.test(headers['content-type']||''))throw new Error('Actual appearance asset unavailable: '+url.pathname);delete headers['set-cookie'];delete headers['content-length'];delete headers['content-encoding'];return{status:200,headers,body:await response.body()};})());
   try{return await route.fulfill(await assets.get(url.href));}catch(error){errors.push(error.message);return route.abort();}
  }return route.continue();
 });
 context.on('page',page=>{page.on('pageerror',error=>errors.push(error.message));page.on('dialog',async dialog=>{if(dialog.type()==='beforeunload')return dialog.accept();errors.push('Unexpected appearance dialog: '+dialog.type());await dialog.dismiss();});});
 const response=await enterAccount(context,origin,mode);check(response.status()===200,'actual '+app+' '+mode+' authenticated account route returns 200');return context;
};
try{
 for(const app of ['native','integrated'])for(const js of [false,true]){
  const mode=js?'js':'basic';if(process.env.DVM_APPEARANCE_FLOW&&![app,app+':'+mode].includes(process.env.DVM_APPEARANCE_FLOW))continue;
  const origin=fixture.origins[app],context=await openContext(app,js,mode),page=await context.newPage();
  await page.goto(account(origin));await form(page).waitFor();check(await page.locator('details[name="account-settings"][open]').count()===1,'actual preference direct link opens only its own compact setting');
  const preferenceAction=await form(page).evaluate(node=>node.action);const initial=await values(page);const denied=await context.request.post(preferenceAction,{form:{...initial,csrf:'wrong',admin_view:'focus'},maxRedirects:0});check(denied.status()===(app==='integrated'?403:422),'actual '+app+' own preference refuses forged CSRF: '+denied.status());
  const invalid=await context.request.post(preferenceAction,{form:{...initial,admin_view:'unregistered-template'},maxRedirects:0});const invalidHtml=await invalid.text();if(invalid.status()!==422||!invalidHtml.includes('Aktuellen Stand öffnen'))await writeFile(artifacts+'/invalid-preference.html',invalidHtml);check(invalid.status()===422&&invalidHtml.includes('Aktuellen Stand öffnen'),'actual '+app+' invalid enum has a recoverable 422 without a preference write: '+invalid.status());
  await page.goto(account(origin));const unchanged=await values(page);check(['admin_view','admin_color','admin_density','appearance_version','appearance_scope'].every(key=>unchanged[key]===initial[key]),'both actual 422 refusals preserve the exact original selected preference and CAS');
  await choose(page,origin,'focus','dark','comfortable');
  if(app==='integrated'&&js)for(const initialWidth of[1440,390]){
   await page.setViewportSize({width:initialWidth,height:900});await page.goto(origin+'/vereinsverwaltung/?view=settings');const picker=page.locator('.module-navigation').first();await picker.waitFor();
   check(await picker.evaluate(node=>!node.open)&&await picker.locator('summary').isVisible(),'section menu starts as one compact current-area picker at '+initialWidth);
   await page.screenshot({path:artifacts+'/compact-section-collapsed-'+initialWidth+'.png'});
   await picker.locator('summary').focus();await page.keyboard.press('Enter');check(await picker.evaluate(node=>node.open),'keyboard Enter opens the area picker at '+initialWidth);
   await picker.locator('nav a').first().focus();await page.keyboard.press('Escape');check(await picker.evaluate(node=>!node.open&&document.activeElement===node.querySelector('summary')),'Escape from a menu link closes and restores summary focus at '+initialWidth);
   await page.evaluate(()=>window.ownPreservedNavigation=document.querySelector('#navigation'));await page.keyboard.press('Space');check(await picker.evaluate(node=>node.open),'keyboard Space opens the area picker at '+initialWidth);await picker.getByRole('link',{name:'Beiträge & Nummern',exact:true}).click();await page.waitForURL(url=>url.searchParams.get('tab')==='rates');await page.waitForFunction(()=>document.querySelector('.module-nav-current')?.textContent==='Beitragssätze');
   check(await page.evaluate(()=>document.querySelector('#navigation')===window.ownPreservedNavigation),'real area change preserves the left navigation element at '+initialWidth);
   const activePicker=page.locator('.module-navigation').first();check(await activePicker.evaluate(node=>!node.open),'newly loaded section closes its picker and names the active subpage');await activePicker.locator('summary').click();const links=activePicker.locator('nav a');check(await links.evaluateAll(nodes=>nodes.filter(node=>node.getClientRects().length).every(node=>node.getBoundingClientRect().height>=44)),'expanded area and subpage links retain genuine touch targets');
   for(const width of [320,820,1440]){await page.setViewportSize({width,height:900});check(await activePicker.evaluate(node=>node.open),'resizing retains the deliberate open state at '+width);for(const size of [16,32]){views++;await page.evaluate(size=>document.documentElement.style.fontSize=size+'px',size);check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'compact section navigation fits viewport and doubled text '+width+'/'+size);}}
   await page.evaluate(()=>document.documentElement.style.fontSize='');await page.setViewportSize({width:initialWidth,height:900});await page.screenshot({path:artifacts+'/compact-section-menu-'+initialWidth+'.png'});await activePicker.locator('nav a').first().focus();await page.keyboard.press('Escape');check(await activePicker.evaluate(node=>!node.open),'expanded picker closes on Escape at '+initialWidth);await page.goto(account(origin));
  }
  if(app==='integrated'&&js){
   await page.setViewportSize({width:1440,height:900});await page.goto(origin+'/vereinsverwaltung/?view=press&section=templates');const pressPicker=page.locator('.communication-area-menu.module-navigation');await pressPicker.waitFor();
   check(await pressPicker.count()===1&&await pressPicker.locator('.module-navigation').count()===0,'desktop press reuses its existing area menu without nesting another selector');
   check(await pressPicker.locator('.module-nav-current').innerText()==='Vorlagen'&&await pressPicker.evaluate(node=>!node.open),'desktop press names its actual active subpage in one closed picker');
   await pressPicker.locator('summary').focus();await page.keyboard.press('Enter');await readableAdministration(page,'desktop press area picker');await pressPicker.locator('nav a').first().focus();await page.keyboard.press('Escape');check(await pressPicker.evaluate(node=>!node.open&&document.activeElement===node.querySelector('summary')),'desktop press uses the same keyboard and Escape behavior');await page.goto(account(origin));
  }
  if(app==='integrated'){
   for(const [query,navSelector,expected]of[
    [{view:'settings',tab:'roles'},'nav.section-main-nav','Rollen & Rechte'],
    [{view:'finance',tab:'receipts'},'nav.finance-main-nav','Belege'],
    [{view:'social',section:'contents'},'nav.editorial-hub-nav','Inhalte'],
    [{view:'documents'},'nav.document-area-nav','Dokumente'],
    [{view:'documents',tab:'workflows'},'nav.dwf-tabs','Dokumente'],
    [{view:'website',tab:'events',tickets:'1'},'nav.section-sub-nav','Tickets verwalten']
   ]){
    const response=await page.goto(origin+'/vereinsverwaltung/?'+new URLSearchParams(query));check(response.status()===200,'actual common-menu destination opens '+JSON.stringify(query));
    const nav=page.locator(navSelector).first();await nav.waitFor({state:'attached'});
    if(js){
     const picker=nav.locator('xpath=ancestor::details[contains(@class,"module-navigation")]');await picker.waitFor();
     check(await picker.count()===1&&await picker.locator('.module-navigation').count()===0,'one unnested area picker owns '+navSelector);
     check(await picker.evaluate(node=>!node.open)&&(await picker.locator('.module-nav-current').innerText()).includes(expected),'closed picker states its current area '+expected);
     if(query.tab==='roles')check(await page.locator('nav.section-main-nav').count()===1&&await page.locator('nav.section-sub-nav').count()===1,'roles reuse the common settings navigation without duplicate rows');
     if(query.view==='finance')check(await page.locator('details.finance-area-menu').count()===0&&await picker.locator('nav.finance-sub-nav').count()===1,'finance puts the existing subpages into the same picker');
     if(query.view==='social')check(await page.locator('.editorial-create').isVisible()&&await picker.locator('nav.editorial-hub-secondary').count()===1,'social creation stays directly available and subsection links are inside the picker');
     await picker.locator('summary').click();await readableAdministration(page,'shared '+query.view+' area menu');
     const current=picker.locator('a[aria-current]').last();const palette=await current.evaluate(node=>{const s=getComputedStyle(node),canvas=document.createElement('canvas'),ctx=canvas.getContext('2d');ctx.fillStyle=s.backgroundColor;ctx.fillRect(0,0,1,1);return[...ctx.getImageData(0,0,1,1).data];});check(palette[2]>palette[1],'active shared menu uses the administrator blue palette for '+query.view);
     for(const width of[390,1440]){await page.setViewportSize({width,height:1000});await page.evaluate(()=>document.documentElement.style.fontSize='32px');check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'shared menu fits enlarged text '+query.view+'/'+width);}
     await page.evaluate(()=>document.documentElement.style.fontSize='');
    }else {const parent=nav.locator('xpath=ancestor::details[1]');if(await parent.count()&&await parent.evaluate(n=>!n.open))await parent.locator(':scope > summary').click();check(await nav.getByRole('link').first().isVisible(),'all permitted area links remain reachable without JavaScript '+query.view);}
   }
   await page.goto(account(origin));
  }
  for(const tab of ['api','system']){
   const destination=await context.newPage();const response=await destination.goto(origin+'/vereinsverwaltung/?view=settings&tab='+tab,{waitUntil:'domcontentloaded'});
   check(response.status()===200,'actual '+app+' '+mode+' protected '+tab+' destination opens');
   await destination.setViewportSize({width:390,height:844});await destination.evaluate(()=>document.documentElement.style.fontSize='32px');
   const state=await destination.evaluate(()=>({view:document.body.dataset.adminView,color:document.body.dataset.adminColor,density:document.body.dataset.adminDensity,bg:getComputedStyle(document.body).backgroundColor,fits:document.documentElement.scrollWidth<=innerWidth+1,small:[...document.querySelectorAll('button,input:not([type=hidden]):not([type=checkbox]):not([type=radio]),select')].filter(node=>node.getClientRects().length).some(node=>node.getBoundingClientRect().height<43)}));
   check(state.view==='focus'&&state.color==='dark'&&state.density==='comfortable'&&state.bg==='rgb(18, 25, 34)','actual '+app+' '+tab+' uses the exact current personal palette');
   check(state.fits&&!state.small,'actual '+app+' '+tab+' fits phone text enlargement with touch controls');await destination.close();
  }
  const stale=await values(page),stalePage=await context.newPage();await stalePage.goto(account(origin));
  await form(stalePage).locator('input[name="admin_view"][value="studio"]').check();await form(stalePage).locator('select[name="admin_color"]').selectOption('light');await choose(page,origin,'compact','light','compact');
  const failed=stalePage.waitForResponse(response=>response.request().method()==='POST');await form(stalePage).getByRole('button',{name:'Ansicht speichern',exact:true}).click({noWaitAfter:true});check((await failed).status()===409,'actual '+app+' '+mode+' stale preference returns 409 at its original application dispatcher');
  const retained=await values(stalePage);check(retained.admin_view==='studio'&&retained.admin_color==='light'&&retained.appearance_version===stale.appearance_version&&retained.appearance_scope===stale.appearance_scope,'actual stale form keeps exact own choices old CAS and original account scope');
  const current=stalePage.getByRole('link',{name:'Aktuellen Stand öffnen',exact:true});const reloaded=stalePage.waitForResponse(response=>response.request().method()==='GET'&&new URL(response.url()).searchParams.has('refresh'));await current.click();check((await reloaded).status()===200,'explicit current-state action performs a real fresh GET even from an existing account fragment');await form(stalePage).locator('input[name="appearance_version"][value="'+(Number(stale.appearance_version)+1)+'"]').waitFor({state:'attached'});check((await values(stalePage)).admin_view==='compact','explicit current-state link opens the separately saved original preference');await stalePage.close();
  const repeated=await context.request.post(preferenceAction,{form:stale,maxRedirects:0});check(repeated.status()===409,'replayed old appearance action cannot save a duplicate original revision');
  // The same genuine account in the other application reads the same existing profile, without copying it.
  const other=app==='native'?'integrated':'native',otherOrigin=fixture.origins[other],cross=await browser.newContext({javaScriptEnabled:false});
  const crossResponse=await enterAccount(cross,otherOrigin,mode,app);const crossState=await page.evaluate(html=>{const doc=new DOMParser().parseFromString(html,'text/html'),f=doc.querySelector('[data-appearance-form]');return f?Object.fromEntries(new FormData(f)):null;},await crossResponse.text());check(crossResponse.status()===200&&crossState?.admin_view==='compact'&&crossState.appearance_version===String(Number(stale.appearance_version)+1),'same actual personal account reads the exact same preference and CAS through the other application: '+crossResponse.status()+' '+JSON.stringify(crossState?{view:crossState.admin_view,version:crossState.appearance_version}:null));await cross.close();
  await page.goto(account(origin));await form(page).locator('input[name="admin_view"][value="studio"]').check();const discarded=page.waitForResponse(response=>response.request().method()==='GET'&&new URL(response.url()).searchParams.has('refresh'));await page.getByRole('link',{name:'Änderungen verwerfen',exact:true}).click();check((await discarded).status()===200&&(await values(page)).admin_view==='compact','explicit discard reloads the actual saved original preference without a save');
  if(js){
   await choose(page,origin,'studio','light','comfortable');await page.goto(manager(origin,app));await page.evaluate(()=>window.__ownAppearanceDocument='same original manager document');
   const twin=await context.newPage();await choose(twin,origin,'focus','dark','compact');await twin.close();
   if(app==='integrated'&&await page.locator('.website-area-menu:not([open])>summary').count())await page.locator('.website-area-menu>summary').click();
   const link=page.locator(app==='native'?'#workspace .sidebar':'#workspace .module-tabs').getByRole('link',{name:app==='native'?'Beiträge':'Aktuelles',exact:true});await link.click({noWaitAfter:true});await page.waitForFunction(()=>document.body.dataset.adminView==='focus'&&document.querySelector('main[data-admin-view]')?.dataset.adminView==='focus');
   check(await page.evaluate(()=>window.__ownAppearanceDocument==='same original manager document'),'actual soft navigation retains the original document while adopting preferences saved in a second window');
   check(await page.locator('body').getAttribute('data-admin-color')==='dark'&&await page.locator('body').getAttribute('data-admin-density')==='compact','actual soft mount adopts color and spacing from the new trusted server main');
   await page.goto(manager(origin,app,app==='native'?{item:fixture.page_id}:{native_item:fixture.page_id}));
   const editor=page.locator('form:has(input[name="operation"][value="page-save"])'),title=editor.locator('input[name="title"]');await editor.waitFor();const humanTitle=await title.inputValue();await title.fill('');let attemptedPosts=0;const countPost=request=>{if(request.method()==='POST')attemptedPosts++;};page.on('request',countPost);await editor.getByRole('button',{name:'Entwurf speichern',exact:true}).click();await title.locator('xpath=following-sibling::*[contains(@class,"field-validation-error")]').waitFor();
   check(await title.getAttribute('aria-invalid')==='true'&&await title.evaluate(node=>node===document.activeElement)&&attemptedPosts===0,'actual shared form helper names and focuses an invalid original title before any business POST');
   check(await title.getAttribute('aria-describedby')!==null&&await editor.locator('.form-validation-summary[role="alert"]').count()===1,'actual shared helper gives both field description and bounded error summary');
   await title.fill(humanTitle+' ungespeicherte eigene Eingabe');await page.waitForFunction(()=>!document.querySelector('form input[name="title"][aria-invalid="true"]'));page.off('request',countPost);
   if(app==='integrated'&&await page.locator('.website-area-menu:not([open])>summary').count())await page.locator('.website-area-menu>summary').click();
   const navigate=page.locator(app==='native'?'#workspace .sidebar':'#workspace .module-tabs').getByRole('link',{name:app==='native'?'Beiträge':'Aktuelles',exact:true});const beforeUrl=page.url();await navigate.click({noWaitAfter:true});const dialog=page.getByRole('dialog');await dialog.waitFor();
   check(await dialog.evaluate(node=>!!node.getAttribute('aria-label')||!!document.getElementById(node.getAttribute('aria-labelledby'))?.textContent.trim()),'actual unsaved-original dialog has a programmatic human-readable name');
   await dialog.getByRole('button',{name:/^(?:Weiter bearbeiten|Abbrechen)$/}).click();check(page.url()===beforeUrl&&await title.inputValue()===humanTitle+' ungespeicherte eigene Eingabe','explicit dialog cancellation preserves the exact unsent human original input');
   await navigate.click({noWaitAfter:true});await dialog.getByRole('button',{name:/^(?:Verwerfen|Änderungen verwerfen)$/}).click();await page.waitForURL(url=>url.searchParams.get(app==='native'?'view':'tab')==='news');
  }
  if(process.env.DVM_APPEARANCE_ACTIONS_ONLY!=='1')for(const view of ['studio','focus','compact']){
   await choose(page,origin,view,'light',view==='compact'?'compact':'comfortable');
   for(const [width,height]of [[320,720],[390,844],[820,1024],[1440,900]])for(const fontSize of [16,32]){
    await page.setViewportSize({width,height});const response=await page.goto(manager(origin,app,app==='native'?{item:fixture.page_id}:{native_item:fixture.page_id}));check(response.status()===200,'actual '+app+' manager opens its selected '+view+' layout');await page.evaluate(size=>document.documentElement.style.fontSize=size+'px',fontSize);
    const metrics=await page.evaluate(()=>{const rect=node=>{const r=node?.getBoundingClientRect();return r?{x:r.x,y:r.y,width:r.width,height:r.height,right:r.right,bottom:r.bottom}:null;};const sidebar=document.querySelector('#navigation')||document.querySelector('main.workspace>.sidebar');const content=document.querySelector('#navigation')?document.querySelector('.app'):document.querySelector('#content');return{viewport:document.body.getBoundingClientRect().width,fits:document.documentElement.scrollWidth<=innerWidth+1,view:document.body.dataset.adminView,main:document.querySelector('main[data-admin-view]')?.dataset.adminView,sidebar:rect(sidebar),content:rect(content),text:document.body.innerText,originalTitle:document.querySelector('input[name=title]')?.value,targets:[...document.querySelectorAll('.sidebar a,.sidebar summary')].filter(node=>node.getClientRects().length&&getComputedStyle(node).visibility!=='hidden').map(node=>({height:node.getBoundingClientRect().height,width:node.getBoundingClientRect().width}))};});
    if(!metrics.fits){await page.screenshot({path:artifacts+'/overflow-'+app+'-'+mode+'-'+view+'-'+width+'-'+fontSize+'.png',fullPage:true});await writeFile(artifacts+'/overflow.json',JSON.stringify(metrics,null,2));}
    check(metrics.fits,'actual '+app+' '+view+' fits '+[width,height,fontSize,mode]);check(metrics.view===view&&metrics.main===view,'actual current manager and replaceable main retain the same selected preference');check(metrics.originalTitle==='Unveränderter eigener Ansichtsinhalt ä🙂','actual admin layout retains the original human-authored page title');for(const target of metrics.targets)check(target.height>=43&&target.width>=43,'actual selected layout keeps large navigation touch targets');
    if(app==='integrated'&&width<=1000)check(Math.abs(metrics.content.x)<=1&&metrics.content.width>=metrics.viewport-1,'actual '+view+' tablet navigation releases the entire reading surface at '+width+' and '+fontSize+'px in '+mode);
    if(app==='integrated'&&width===1440&&fontSize===32&&view!=='focus')check(metrics.sidebar.width<=320&&metrics.content.width>=metrics.viewport-321,'actual '+view+' double-font navigation leaves the main reading width available in '+mode);
    if(width===1440&&fontSize===16){if(view==='focus')check(app==='integrated'?metrics.sidebar.bottom<=metrics.content.y+1:metrics.sidebar.width>metrics.content.width*.8,'focus moves the actual navigation above the content');else check(metrics.sidebar.right<=metrics.content.x+1,'studio and compact keep the actual navigation beside the content');}
    if(js)check(await page.evaluate(()=>!!window.KulturhusControls),'actual selected layout retains shared native Bootstrap controls');views++;
   }
  }
  await choose(page,origin,'studio','system','comfortable');await page.emulateMedia({colorScheme:'light'});const light=await page.locator('body').evaluate(node=>getComputedStyle(node).backgroundColor);await page.emulateMedia({colorScheme:'dark'});const dark=await page.locator('body').evaluate(node=>getComputedStyle(node).backgroundColor);check(light!==dark,'system palette follows the actual device preference without a data write');
  for(const [color,device]of[['light','light'],['dark','light'],['system','dark']]){
   console.log(JSON.stringify({appearance_color_check:{app,mode,color,device}}));
   await choose(page,origin,'studio',color,'comfortable');await page.emulateMedia({colorScheme:device});
   for(const width of[390,820,1440]){
    await page.setViewportSize({width,height:1000});await page.goto(account(origin));await readableAdministration(page,app+' '+mode+' account '+color+' '+width);
    const urls=app==='integrated'?[origin+'/vereinsverwaltung/',origin+'/vereinsverwaltung/?view=members']: [manager(origin,app,{item:fixture.page_id})];
    for(const url of urls){const response=await page.goto(url);check(response.status()===200,'actual contrast target opens '+url);if(app==='integrated'&&new URL(url).searchParams.get('view')==='members')for(const summary of await page.locator('.tool-panel>summary').all())if(await summary.locator('..').getAttribute('open')===null)await summary.click();await readableAdministration(page,app+' '+mode+' '+url+' '+color+' '+width);check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'contrast repair retains available reading width');}
   }
  }
  await page.screenshot({path:artifacts+'/account-'+app+'-'+mode+'.png',fullPage:true});await context.close();console.log('PASS actual personal layouts CAS recovery CSRF persistence '+app+' '+mode);
 }
 check(external===0,'no appearance provider or remote asset requests');if(errors.length)console.error('Appearance browser diagnostics:',JSON.stringify(errors));check(errors.length===0,'no actual appearance JavaScript or static asset errors');
 const result={suite:'native-appearance-browser',views,checks,external_requests:external,javascript_errors:errors,static_assets:assets.size,live_transport:false};await writeFile(fixture.result_file,JSON.stringify(result,null,2)+'\n');console.log(JSON.stringify(result));
}catch(error){console.error('Appearance original failure:',error.stack);let i=0;for(const context of browser.contexts())for(const page of context.pages())try{await writeFile(artifacts+'/failure-'+i+'.html',await page.content());await page.screenshot({path:artifacts+'/failure-'+i+'.png',fullPage:true});i++;}catch{}throw error;}finally{await browser.close();}
