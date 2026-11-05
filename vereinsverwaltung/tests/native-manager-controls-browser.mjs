import assert from 'node:assert/strict';
import {actualOwnMenuPage,actualOwnMenuRoutes} from './support/owned-menu-client.mjs';
import {readFile,writeFile,mkdir} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const root=fileURLToPath(new URL('../../',import.meta.url));
const owned=process.env.DVM_MANAGER_CONTROLS_FIXTURE?JSON.parse(await readFile(process.env.DVM_MANAGER_CONTROLS_FIXTURE,'utf8')):null;
const origin=owned?.origin||'http://127.0.0.1:8111';
const credentials=owned?null:JSON.parse(await readFile(root+'.local/native-cms-integration/integrated-manager-runtime/browser-credentials.json','utf8'));
const fixture=owned?.data||JSON.parse(await readFile(root+'.local/browser-tests/contact-status.json','utf8'));
const views=owned?(fixture.transition_only?[[1440,1000]]:fixture.fast?[[1440,1000],[320,720]]:[[320,720],[390,844],[820,1180],[1180,820],[1440,1000],[844,390]]):[[1440,1000],[820,1000],[390,1000]];const scales=owned&&!fixture.fast&&!fixture.transition_only?[2,1]:[1];
const browser=await chromium.launch({executablePath:process.env.CHROMIUM_EXECUTABLE||'/usr/bin/chromium',args:['--no-sandbox']});
const result={states:[],checks:0,failures:[],errors:[],external:[],writes:[],live_transport:false};
function check(value,label){assert(value,label);result.checks++;}
async function audit(page,label){
 const record=(value,message)=>{if(value)result.checks++;else{result.failures.push({label:message});console.log('FAIL audit '+message);}};
 const findings=await page.evaluate(()=>{
  const visible=e=>e.checkVisibility({checkVisibilityCSS:true})&&!e.closest('[hidden]');
  const controls=[...document.querySelectorAll('main :is(button,input,select,textarea),dialog[open] :is(button,input,select,textarea)')].filter(e=>e.type!=='hidden'&&!e.closest('[contenteditable],.kh-richtext'));
  const describe=e=>({tag:e.tagName,type:e.type,name:e.name,classes:e.className});
  const missing=controls.filter(e=>!e.classList.contains(e.tagName==='BUTTON'||['button','submit','reset'].includes(e.type)?'btn':['checkbox','radio'].includes(e.type)?'check':e.type==='range'?'form-range':'form-control')).map(describe);
  const small=controls.filter(e=>visible(e)&&e.tagName==='BUTTON').filter(e=>{const b=e.getBoundingClientRect();return b.width<43.5||b.height<43.5}).map(describe);
  const unnamed=controls.filter(e=>visible(e)&&e.getAttribute('aria-hidden')!=='true'&&e.tagName!=='BUTTON'&&!e.labels?.length&&!e.getAttribute('aria-label')&&!e.getAttribute('aria-labelledby')&&!e.title&&!e.placeholder).map(describe);
  const breadcrumb=document.querySelector('.topbar .breadcrumb');const box=breadcrumb?.getBoundingClientRect();
  const header=breadcrumb?{text:breadcrumb.textContent,present:visible(breadcrumb)&&box.width>0&&box.height>0,whole:breadcrumb.scrollWidth<=breadcrumb.clientWidth+1,phone:innerWidth<=560}:null;
  const smallHeader=[...document.querySelectorAll('.topbar :is(a,button)')].filter(visible).filter(e=>{const b=e.getBoundingClientRect();return b.width<43.5||b.height<43.5}).map(describe);
  return {controls:controls.length,missing,small,unnamed,header,smallHeader,overflow:document.documentElement.scrollWidth>innerWidth+2};
 });
 record(findings.missing.length===0,label+' Bootstrap controls: '+JSON.stringify(findings.missing));
 record(findings.small.length===0,label+' touch buttons: '+JSON.stringify(findings.small));
 record(findings.unnamed.length===0,label+' field names: '+JSON.stringify(findings.unnamed));
 if(findings.overflow&&owned){const artifacts=process.env.DVM_BROWSER_ARTIFACT_DIR||'/tmp/manager-controls-ui';await mkdir(artifacts,{recursive:true,mode:0o700});await page.screenshot({path:artifacts+'/manager-controls-overflow.png',fullPage:true});await writeFile(artifacts+'/manager-controls-overflow.html',await page.content(),{mode:0o600});console.log('Own menu overflow',label,await page.evaluate(()=>[...document.querySelectorAll('main *')].filter(n=>n.checkVisibility({checkVisibilityCSS:true})&&n.getBoundingClientRect().right>innerWidth+2).map(n=>({tag:n.tagName,id:n.id,class:n.className,width:n.getBoundingClientRect().width,right:n.getBoundingClientRect().right,minWidth:getComputedStyle(n).minWidth,whiteSpace:getComputedStyle(n).whiteSpace})).slice(0,35)));}
 record(!!findings.header?.present&&(!owned||findings.header.text===fixture.expected_club_name),label+' retains the actual original club name from common settings');
 record(!findings.header?.phone||findings.header.whole,label+' keeps the whole phone club name readable');
 record(findings.smallHeader.length===0,label+' keeps actual header links and buttons touch-sized: '+JSON.stringify(findings.smallHeader));
 record(!findings.overflow,label+' fits viewport');
 return findings.controls;
}
async function contextFor(storage,javaScriptEnabled=true){
 const context=await browser.newContext({storageState:storage,javaScriptEnabled,hasTouch:true});
 await context.route('**/*',route=>{
  const request=route.request();
  if(new URL(request.url()).origin!==origin){result.external.push(new URL(request.url()).origin);return route.abort();}
  if(request.method()==='POST'&&new URL(request.url()).pathname!=='/login/')result.writes.push(new URL(request.url()).pathname);
  return route.continue();
 });
 const page=await context.newPage();page.on('pageerror',e=>result.errors.push(e.message));
 return {context,page};
}
try{
 for(const role of (fixture.transition_only?['admin']:['admin','member','editor'])){
  let storage;
  if(!owned&&process.env.MANAGER_CONTROLS_STORAGE_DIR){try{storage=JSON.parse(await readFile(process.env.MANAGER_CONTROLS_STORAGE_DIR+'/'+role+'.json','utf8'));}catch(error){if(error.code!=='ENOENT')throw error;}}
  if(!storage&&owned){const login=await contextFor(undefined);await login.page.goto(origin+'/own-test-enter?role='+role);storage=await login.context.storageState();await login.context.close();}
  if(!storage){
   const login=await contextFor(undefined);await login.page.goto(origin+'/login/');await login.page.locator('[name=email]').fill(credentials[role].email);await login.page.locator('[name=password]').fill(credentials[role].password);
   await Promise.all([login.page.waitForURL('**/vereinsverwaltung/**'),login.page.getByRole('button',{name:'Anmelden und Vereinsmanager öffnen',exact:true}).click()]);
   storage=await login.context.storageState();await login.context.close();
   await mkdir(root+'.local/browser-tests/controls-sessions',{recursive:true,mode:0o700});await writeFile(root+'.local/browser-tests/controls-sessions/'+role+'.json',JSON.stringify(storage),{mode:0o600});
  }

  const {context,page}=await contextFor(storage);
  await page.goto(origin+'/vereinsverwaltung/?view=dashboard');
  const routes=(await actualOwnMenuRoutes(page,origin)).map(route=>route.url);
  check(routes.length>=(role==='admin'?20:3),'populated actual menu '+role);
  if(!fixture.profile_only&&!fixture.transition_only){
  const plain=await contextFor(storage,false);
  for(const url of routes){
   const plainViews=owned?views:[[1440,1000],[390,1000]];await plain.page.setViewportSize({width:plainViews[0][0],height:plainViews[0][1]});const response=await plain.page.goto(url);check(response.status()===200,'actual no-JavaScript menu response');
   for(const [width,height]of plainViews)for(const scale of scales){await plain.page.setViewportSize({width,height});await plain.page.evaluate(s=>document.documentElement.style.fontSize=s===2?'200%':'100%',scale);const controls=await audit(plain.page,role+' no-JavaScript '+width+' '+new URL(url).search);result.states.push({role,width,height,scale,route:new URL(url).search,controls,javascript:false});}
   console.log('CHECKED actual server form in every size and font without JavaScript',role,new URL(url).search);
  }
  await plain.context.close();
  }
  // Each real controller response is then resized like an open user page. All
  // route/viewport/font combinations remain; the separate navigation browser
  // also re-enters every admin/member route at four actual viewport sizes.
  if(!fixture.profile_only)for(const url of (fixture.transition_only?routes.filter(url=>{const q=new URL(url).searchParams;return q.get('tab')==='technical'||q.get('view')==='chat';}):routes)){
   await page.setViewportSize({width:views[0][0],height:views[0][1]});const response=await actualOwnMenuPage(page,url,owned,check);check(response.status()===200,role+' actual menu response '+new URL(url).search);await page.waitForFunction(()=>!!window.KulturhusControls);
   for(const [width,height]of views)for(const scale of scales){await page.setViewportSize({width,height});await page.evaluate(s=>{document.documentElement.style.fontSize=s===2?'200%':'100%';return new Promise(requestAnimationFrame);},scale);const controls=await audit(page,role+' '+width+' '+new URL(url).search);result.states.push({role,width,height,scale,route:new URL(url).search,controls,javascript:true});}
   console.log('CHECKED actual manager navigation in every size and font',role,new URL(url).search);
  }
  if(fixture.transition_only){
   const connections=routes.find(url=>new URL(url).searchParams.get('tab')==='technical'),chat=routes.find(url=>new URL(url).searchParams.get('view')==='chat');await actualOwnMenuPage(page,chat,owned,check);const create=page.locator('#chat-create');if(await create.getAttribute('open')===null)await create.locator('summary').click();const label=create.locator('form input[name=title]'),original=await label.inputValue();await label.fill('Own deliberately unsaved chat title');check(await page.evaluate(()=>window.KulturhusNavigation.dirty()),'real human chat title change remains protected after synchronous initialization');
   const pending=page.evaluate(url=>window.KulturhusNavigation.navigate(url),connections);const dialog=page.locator('dialog[open]');await dialog.waitFor();await dialog.getByRole('button',{name:'Abbrechen',exact:true}).tap();check(await pending===false&&await label.inputValue()==='Own deliberately unsaved chat title','declining actual navigation preserves the exact unsaved human field');await label.fill(original);check(!await page.evaluate(()=>window.KulturhusNavigation.dirty()),'restoring exact original field clears only the actual change');await actualOwnMenuPage(page,connections,owned,check);await context.close();break;
  }
  if(role==='admin'){
   if(owned)await page.setViewportSize({width:320,height:720});
   await page.goto(origin+'/vereinsverwaltung/?view=members&id='+fixture.contact);
   const summaries=page.locator('main details > summary');
   for(let i=0;i<await summaries.count();i++){
    const summary=summaries.nth(i);if(!await summary.isVisible())continue;
    const box=await summary.boundingBox();check(box.height>=43.5,'member profile disclosure touch height');
    if(await summary.locator('..').getAttribute('open')===null){await summary.focus();await summary.press('Enter');}
    await audit(page,'actual populated member disclosure '+i);
   }
   await page.evaluate(()=>document.documentElement.style.zoom='2');await audit(page,'populated member profile at 200 percent zoom');if(owned){check((await page.locator('.member-profile .page-heading h1').boundingBox()).height<500,'profile title remains a bounded readable heading at 320 pixels and double zoom');if(process.env.DVM_BROWSER_ARTIFACT_DIR){await mkdir(process.env.DVM_BROWSER_ARTIFACT_DIR,{recursive:true,mode:0o700});await page.locator('.member-profile > .page-heading').screenshot({path:process.env.DVM_BROWSER_ARTIFACT_DIR+'/profile-title-double-zoom.png'});}}await page.evaluate(()=>document.documentElement.style.zoom='');
   if(fixture.profile_only){await context.close();break;}
   await page.goto(origin+'/vereinsverwaltung/?view=calendar');const picker=page.getByRole('combobox',{name:'Einträge anzeigen',exact:true});await picker.tap();
   const option=page.getByRole('option',{name:'Alles',exact:true});check(await option.isVisible(),'actual calendar search opens by touch');const box=await option.boundingBox();check(box.height>=43.5,'calendar option has touch height');await option.tap();check(await picker.getAttribute('aria-expanded')==='false','calendar touch selection closes search');
   await page.goto(origin+'/vereinsverwaltung/?view=press'+(owned?'&section=compose':''));
   const menus=page.locator('.kh-rte-menu');
   check(await menus.count()>0,'actual rich text editor has tool menus');
   for(let i=0;i<await menus.count();i++){
    const summary=menus.nth(i).locator(':scope > summary');await summary.tap();await audit(page,'actual opened rich text tool menu '+i);await summary.press('Enter');
   }
   await page.goto(origin+'/vereinsverwaltung/?view=dashboard');
   await page.evaluate(()=>{window.controlPromptResult='pending';window.KulturhusDialog.prompt('Eigene Bedienprobe','Originaler Testwert').then(value=>{window.controlPromptResult=value;});});
   const dialog=page.locator('dialog[open]');await dialog.waitFor();await audit(page,'actual dynamic prompt');
   const input=dialog.getByRole('textbox',{name:'Eigene Bedienprobe',exact:true});check(await input.inputValue()==='Originaler Testwert','dynamic decoration retains original input value');await input.fill('Geprüfter Testwert');await dialog.getByRole('button',{name:'Übernehmen',exact:true}).tap();await page.waitForFunction(()=>window.controlPromptResult==='Geprüfter Testwert');check(await page.locator('dialog[open]').count()===0,'touch prompt closes without submitting a form');
   await page.evaluate(()=>{window.controlConfirmResult='pending';window.KulturhusDialog.confirm('Eigene Abbruchprobe',{danger:true}).then(value=>{window.controlConfirmResult=value;});});await dialog.waitFor();await audit(page,'actual dynamic confirmation');await page.keyboard.press('Escape');await page.waitForFunction(()=>window.controlConfirmResult===false);check(await page.locator('dialog[open]').count()===0,'Escape cancels actual dialog');
   await page.goto(origin+'/vereinsverwaltung/?view=dashboard');await page.waitForFunction(()=>!!window.KulturhusNavigation?.navigate);await page.evaluate(()=>{window.controlsDocumentMarker='retained';});const link=page.locator('#navigation .subnav a[href*="view=calendar"]').first();await page.getByRole('button',{name:'Navigation öffnen',exact:true}).tap();await link.evaluate(e=>{e.closest('details').open=true;});await link.tap();await page.waitForFunction(()=>new URL(location.href).searchParams.get('view')==='calendar'&&!document.querySelector('main').hasAttribute('aria-busy'));check(await page.evaluate(()=>window.controlsDocumentMarker)==='retained','touch soft navigation retains actual document');await audit(page,'dynamic replacement after touch menu navigation');check(await page.locator('script[src*=manager-controls]').count()===1,'one shared control module after navigation');
  }
  await context.close();

 }
 check(result.errors.length===0,'no browser script errors: '+JSON.stringify(result.errors));check(result.external.length===0,'no external calls');check(result.writes.length===0,'no application writes or deliveries');
 await writeFile(owned?fixture.result_file:root+'.local/browser-tests/manager-controls-result.json',JSON.stringify(result,null,2),{mode:0o600});console.log(JSON.stringify({states:result.states.length,checks:result.checks,failed_audits:result.failures.length,fast:!!(owned&&fixture.fast),profile_only:!!(owned&&fixture.profile_only),transition_only:!!(owned&&fixture.transition_only),live_transport:false}));
 check(result.failures.length===0,'all actual menu layouts and control audits pass: '+JSON.stringify(result.failures));
}finally{await browser.close();}
