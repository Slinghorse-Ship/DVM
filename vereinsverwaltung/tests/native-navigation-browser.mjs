import {readFile,writeFile,mkdir} from 'node:fs/promises';
import assert from 'node:assert/strict';
const fixture=JSON.parse(await readFile(process.env.DVM_NAVIGATION_FIXTURE,'utf8'));
const artifacts=process.env.DVM_NAVIGATION_ARTIFACTS||fixture.artifacts;await mkdir(artifacts,{recursive:true});
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE||'/usr/bin/chromium',args:['--no-sandbox']});
const errors=[],checks=[];let committed=0,external=0;let profiles=0;const selection=process.env.DVM_NAVIGATION_PROFILE||'';assert.ok(['','native:js','native:basic','integrated:js','integrated:basic'].includes(selection),'Known owned navigation profile required.');
const check=(ok,label)=>{assert.ok(ok,label);checks.push(label);};
const url=app=>fixture.origins[app]+(app==='native'?'/?view=navigation':'/vereinsverwaltung/?view=website&tab=navigation');
const editor=page=>page.locator('form[data-navigation-editor]');
const input=(page,name)=>editor(page).locator('input[name="'+name+'"]');
const row=(page,id)=>editor(page).locator('[data-menu-row]').filter({has:page.locator('input[name="items['+id+'][label]"]')});
const values=page=>editor(page).evaluate(form=>Object.fromEntries(new FormData(form)));
const create=async(app,js=true,role='admin')=>{
 const context=await browser.newContext({javaScriptEnabled:js,hasTouch:true,viewport:{width:390,height:844}});// A real full document and its assets share the single owned PHP server.
 // Allow the same bound for HTTP/DOM work as for navigation under concurrent CI.
 context.setDefaultTimeout(60000);context.setDefaultNavigationTimeout(60000);
 await context.route('**/*',route=>{if(Object.values(fixture.origins).includes(new URL(route.request().url()).origin))return route.continue();external++;return route.abort();});
 context.on('page',page=>{page.on('pageerror',error=>errors.push(error.message));page.on('dialog',dialog=>dialog.type()==='beforeunload'?dialog.accept():dialog.dismiss());});
 await context.request.get(fixture.origins[app]+'/own-test-enter?role='+role);return context;
};
const submit=async(page,name,expected)=>{
 const button=name==='save'?editor(page).getByRole('button',{name:'Menü speichern',exact:true}):editor(page).locator('button[name=navigation_browse][value='+name+']');
 const navigation=page.ownMenuAjax?null:page.waitForNavigation({waitUntil:'load'});
 const response=page.waitForResponse(r=>r.request().method()==='POST'&&new URL(r.url()).pathname===new URL(page.url()).pathname);
 await button.click({noWaitAfter:true});const result=await response;check(result.status()===expected,'actual POST '+name+' HTTP '+expected+' '+page.url());
 if(result.request().headers().accept?.includes('application/json')){
  if(expected===200)await page.waitForFunction(()=>!document.querySelector('#workspace[aria-busy]'));
  else await page.locator('#notice.error').waitFor();
 }else await navigation;
 await editor(page).waitFor();
 // Restored fields can appear before a full POST document has run deferred scripts.
 if(page.ownMenuEnhanced)await page.waitForFunction(()=>document.readyState==='complete'&&Boolean(window.KulturhusNavigation),null,{timeout:60000});
 return result;
};
const remoteSave=async(context,page,app,label)=>{
 const response=await context.request.get(url(app));check(response.status()===200,'parallel real editor reads current menu');
 const data=await page.evaluate(html=>{const doc=new DOMParser().parseFromString(html,'text/html');return Object.fromEntries(new FormData(doc.querySelector('form[data-navigation-editor]')));},await response.text());
 data['items['+fixture.items.first+'][label]']=label;
 const saved=await context.request.post(url(app),{form:data,maxRedirects:0});check(saved.status()===303,'parallel current-CAS save commits through real controller');committed++;
 return Number(data.version)+1;
};
try{
 for(const app of ['native','integrated'])for(const js of [true,false]){
  if(selection&&selection!==app+':'+(js?'js':'basic'))continue;profiles++;
  const context=await create(app,js),remote=await create(app,false,'editor'),page=await context.newPage(),flow=app+':'+(js?'js':'basic');
  page.ownMenuAjax=app==='native'&&js;page.ownMenuEnhanced=app==='integrated'&&js;
  const publicBefore=await context.request.get(fixture.origins[app]+'/site/own-menu-first');check(publicBefore.status()===200,'actual published menu original available '+flow);
  const originalMain=await page.evaluate(html=>new DOMParser().parseFromString(html,'text/html').querySelector('main')?.innerHTML,await publicBefore.text());check(originalMain?.includes('Exact original menu body first ä🙂'),'public original body is exact '+flow);
  await page.goto(url(app));await editor(page).waitFor();const old=Number(await input(page,'version').inputValue());
  if(js){
   const first=await editor(page).locator('[data-menu-row]').first().locator('input[name$="[label]"]').getAttribute('name');
   const down=editor(page).locator('[data-menu-row]').first().locator('[data-menu-step="1"]');await down.focus();await page.keyboard.press('Enter');
   check(await editor(page).locator('[data-menu-row]').nth(1).locator('input[name$="[label]"]').getAttribute('name')===first,'keyboard reorder changes actual submitted row order '+flow);
   check(await editor(page).locator('[data-menu-position]').evaluateAll(fields=>fields.every((f,i)=>f.value===String(i))),'keyboard reorder renumbers actual positions '+flow);
   check(await page.evaluate(()=>document.activeElement?.matches('[data-menu-step]:not(:disabled)')),'keyboard reorder preserves focus on an active control '+flow);
   const beforeTouch=await editor(page).locator('[data-menu-row] input[name$="[label]"]').evaluateAll(fields=>fields.map(field=>field.name));
   await editor(page).locator('[data-menu-row]').first().locator('[data-menu-step="1"]').tap();
   check(JSON.stringify(await editor(page).locator('[data-menu-row] input[name$="[label]"]').evaluateAll(fields=>fields.map(field=>field.name)))!==JSON.stringify(beforeTouch),'touch reorder changes real submitted row order '+flow);
  }
  const own='Eigener Vorschlag '+flow+' & ä🙂',ownRow=row(page,fixture.items.first);
  await ownRow.locator('input[name$="[label]"]').fill(own);await ownRow.locator('[data-menu-position]').fill('17');await ownRow.locator('input[name$="[visible]"]').uncheck();await input(page,'automatic_program_year').uncheck();
  const originalPost=await values(page),firstCurrent=await remoteSave(remote,page,app,'Parallel original one '+flow);
  await submit(page,'save',409);
  check(Number(await input(page,'version').inputValue())===old&&await row(page,fixture.items.first).locator('input[name$="[label]"]').inputValue()===own,'409 keeps exact human label and OLD CAS '+flow);
  check(await row(page,fixture.items.first).locator('[data-menu-position]').inputValue()==='17'&&!await row(page,fixture.items.first).locator('input[name$="[visible]"]').isChecked()&&!await input(page,'automatic_program_year').isChecked(),'409 preserves position/visibility/automatic selection '+flow);
  await submit(page,'compare',200);
  check(Number(await input(page,'version').inputValue())===old&&Number(await input(page,'navigation_seen_version').inputValue())===firstCurrent,'comparison does not advance own old CAS '+flow);
  check((await page.locator('[data-navigation-current]').innerText()).includes('Parallel original one '+flow),'saved current original displayed separately '+flow);
  const jsonError=await context.request.post(url(app),{form:originalPost,headers:{Accept:'application/json'},maxRedirects:0});check(jsonError.status()===409&&typeof(await jsonError.json()).error==='string','JSON error keeps 409 and does not return replacement form '+flow);
  if(app==='integrated'){const compared=await context.request.post(url(app),{form:{...originalPost,navigation_browse:'compare'},headers:{Accept:'application/json'},maxRedirects:0});const data=await compared.json();check(compared.status()===200&&data.unsaved===true&&data.html.includes('data-unsaved-changes'),'integrated JSON comparison expressly returns unsaved proposal '+flow);}
  const secondCurrent=await remoteSave(remote,page,app,'Parallel original two '+flow);
  await submit(page,'resume',409);check(Number(await input(page,'version').inputValue())===old,'new concurrent state prevents stale adoption '+flow);
  await submit(page,'compare',200);await submit(page,'resume',200);
  check(Number(await input(page,'version').inputValue())===secondCurrent&&await row(page,fixture.items.first).locator('input[name$="[label]"]').inputValue()===own,'explicit no-save adoption advances only CAS and keeps own values '+flow);
  check(await editor(page).getAttribute('data-unsaved-changes')!==null,'adopted human proposal remains explicitly unsaved '+flow);
  const persisted=await remote.request.get(url(app));check((await persisted.text()).includes('Parallel original two '+flow),'adoption does not persist proposal '+flow);
  if(js){
   if(app==='integrated')check(await page.evaluate(()=>window.KulturhusNavigation.dirty()),'integrated adopted form remains dirty after document initialization');
   const destination=app==='native'?page.locator('a[data-nav][href="/?view=website"]').first():page.locator('#main a[data-nav][href*="tab=overview"]').first();
   if(app==='integrated'&&!await destination.isVisible())await page.locator('#main .website-area-menu>summary').click();
   await destination.click({noWaitAfter:true});await page.locator('dialog[open]').waitFor();await page.keyboard.press('Escape');
   check(await editor(page).count()===1&&await row(page,fixture.items.first).locator('input[name$="[label]"]').inputValue()===own,'cancel navigation warning retains unsaved adopted proposal '+flow);
   if(app==='integrated'){await page.evaluate(()=>window.KulturhusNavigation.markClean(document.querySelector('[data-navigation-editor]')));check(await page.evaluate(()=>window.KulturhusNavigation.dirty()),'generic markClean cannot mark an explicitly unsaved proposal pristine');}
  }
  for(const width of [320,390,1024])for(const font of [16,32]){
   await page.setViewportSize({width,height:900});await page.evaluate(size=>document.documentElement.style.fontSize=size+'px',font);
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'proposal and comparison fit mobile/zoom '+[flow,width,font]);
   check(await editor(page).locator('button,input:not([type=hidden])').evaluateAll(nodes=>nodes.every(node=>{const r=node.getBoundingClientRect();return r.width===0||r.left>=-1&&r.right<=innerWidth+1;})),'real menu controls remain inside viewport '+[flow,width,font]);
   check(await editor(page).locator('button').evaluateAll(nodes=>nodes.every(node=>{const r=node.getBoundingClientRect();return r.width===0||r.width>=43&&r.height>=43;})),'all actual menu touch buttons fit '+[flow,width,font]);
  }
  await page.setViewportSize({width:390,height:844});await page.evaluate(()=>document.documentElement.style.fontSize='16px');
  await row(page,fixture.items.first).locator('input[name$="[label]"]').fill('<img src=x onerror=alert(1)>');await row(page,fixture.items.first).locator('[data-menu-position]').fill('-2');await editor(page).evaluate(form=>form.noValidate=true);
  await submit(page,'save',422);
  check(await row(page,fixture.items.first).locator('input[name$="[label]"]').inputValue()==='<img src=x onerror=alert(1)>'&&await row(page,fixture.items.first).locator('[data-menu-position]').inputValue()==='-2','422 retains exact own rejected values '+flow);
  check(await editor(page).locator('img[src=x]').count()===0&&Number(await input(page,'version').inputValue())===secondCurrent,'422 cannot execute input or change adopted CAS '+flow);
  await row(page,fixture.items.first).locator('input[name$="[label]"]').fill(own);await row(page,fixture.items.first).locator('[data-menu-position]').fill('17');await submit(page,'save',app==='native'&&js?200:303);committed++;
  await page.waitForFunction(expected=>Number(document.querySelector('[data-navigation-editor] input[name=version]')?.value)===expected,secondCurrent+1);
  check(await editor(page).getAttribute('data-unsaved-changes')===null&&await row(page,fixture.items.first).locator('input[name$="[label]"]').inputValue()===own,'one explicit subsequent save commits proposal and removes unsaved marker '+flow);
  const publicAfter=await context.request.get(fixture.origins[app]+'/site/own-menu-first');check(publicAfter.status()===200&&await page.evaluate(html=>new DOMParser().parseFromString(html,'text/html').querySelector('main')?.innerHTML,await publicAfter.text())===originalMain,'menu recovery leaves actual published page body unchanged '+flow);
  const replay=await context.request.post(url(app),{form:originalPost,maxRedirects:0});check(replay.status()===409,'replay of old successful intent cannot overwrite later menu '+flow);
  const current=await values(page),privateInput='Own rejected CSRF input '+flow;
  const denied=await context.request.post(url(app),{form:{...current,csrf:'wrong',['items['+fixture.items.first+'][label]']:privateInput},maxRedirects:0});check(denied.status()>=400&&!(await denied.text()).includes(privateInput),'wrong CSRF never recovers attacker proposal '+flow);
  for(const field of ['navigation_actor','navigation_club']){const bad=await context.request.post(url(app),{form:{...current,[field]:'another-context',['items['+fixture.items.first+'][label]']:privateInput},maxRedirects:0});check(bad.status()>=400&&!(await bad.text()).includes(privateInput),'changed context cannot recover proposal '+field+' '+flow);}
  const reader=await create(app,false,'reader'),readPage=await reader.newPage();await readPage.goto(url(app));check(await editor(readPage).locator('fieldset').evaluate(fieldset=>fieldset.hasAttribute('disabled'))&&await editor(readPage).locator('fieldset input,fieldset button,fieldset select,fieldset textarea').evaluateAll(controls=>controls.length>0&&controls.every(control=>control.matches(':disabled'))),'read-only role has no active menu controls '+flow);
  const readPost=await values(readPage);const deniedRead=await reader.request.post(url(app),{form:{...current,csrf:readPost.csrf,navigation_actor:readPost.navigation_actor,navigation_club:readPost.navigation_club},maxRedirects:0});check(deniedRead.status()>=400,'current read-only ACL rejects actual POST '+flow);
  if(!js){
   const publisher=await create(app,false,'editor'),publisherPage=await publisher.newPage();publisherPage.ownMenuAjax=false;await publisherPage.goto(url(app));
   const lockedBefore=await publisherPage.locator('[data-navigation-readonly]').textContent();check(lockedBefore.includes('Locked original & ä🙂'),'readable privileged original remains visibly identified as view only '+app);
   const automatic=editor(publisherPage).locator('input[type=checkbox][name=automatic_program_year]');check(await automatic.isDisabled(),'inaccessible programme keeps automatic-label checkbox readonly '+app);
   const preserved=await values(publisherPage);check((preserved.automatic_program_year==='1')===await automatic.isChecked()&&!(await publisherPage.content()).includes(fixture.programme),'disabled checkbox preserves current boolean without exposing private programme identity '+app);
   check(await editor(publisherPage).locator('input[name^="items['+fixture.locked+']"]').count()===0,'normal publisher form does not submit a privileged original '+app);
   await row(publisherPage,fixture.items.first).locator('input[name$="[label]"]').fill('Allowed ordinary publisher '+app);await submit(publisherPage,'save',303);committed++;
   check(await publisherPage.locator('[data-navigation-readonly]').textContent()===lockedBefore,'allowed row saves without changing skipped original override '+app);
   const malicious=await values(publisherPage);malicious['items['+fixture.locked+'][label]']='Own forbidden recovered value';malicious['items['+fixture.locked+'][position]']='2';
   const refused=await publisher.request.post(url(app),{form:malicious,maxRedirects:0});check(refused.status()>=400&&!(await refused.text()).includes('Own forbidden recovered value'),'forged privileged row cannot enter noJS recovery '+app);await publisher.close();
  }
  await reader.close();await page.screenshot({path:artifacts+'/menu-'+app+'-'+(js?'js':'basic')+'.png',fullPage:true});await context.close();await remote.close();console.log('PASS actual menu recovery '+flow);
 }
 check(errors.length===0&&external===0,'no JavaScript errors, external requests or provider actions');
 await writeFile(artifacts+'/result.json',JSON.stringify({suite:'native-navigation-browser',profiles,passed:checks.length,checks,committed_saves:committed,external,errors},null,2)+'\n');console.log(JSON.stringify({suite:'native-navigation-browser',profiles,passed:checks.length,committed_saves:committed,external,errors}));
}catch(error){await writeFile(artifacts+'/failure.json',JSON.stringify({error:error.stack,checks,errors},null,2));for(const context of browser.contexts())for(const page of context.pages()){await writeFile(artifacts+'/failure-'+browser.contexts().indexOf(context)+'.html',await page.content());await page.screenshot({path:artifacts+'/failure-'+browser.contexts().indexOf(context)+'.png',fullPage:true}).catch(()=>{});}throw error;}
finally{await browser.close();}
