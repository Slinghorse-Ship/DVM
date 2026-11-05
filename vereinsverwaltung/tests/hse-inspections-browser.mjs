import fs from 'node:fs';
const fixture=JSON.parse(fs.readFileSync(0,'utf8'));
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'/workspace/.setup/browser/node_modules/playwright/index.mjs');
const browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE||'/usr/bin/chromium',headless:true,args:['--no-sandbox']});
let checks=0,writes=0;const check=(ok,label)=>{if(!ok)throw new Error(label);checks++;};
async function client(role,width=390,js=true){
 const context=await browser.newContext({viewport:{width,height:900},javaScriptEnabled:js,hasTouch:true});
 await context.route('**/*',async route=>{try{const request=route.request(),u=new URL(request.url());if(u.origin!=='https://hse.example')return route.abort('blockedbyclient');const headers=await request.allHeaders();headers.host='hse.example';delete headers['content-length'];
   // Interception precedes Chromium's final wire metadata; translate the real initiator for the local proxy.
   if(!headers['sec-fetch-site']){try{headers['sec-fetch-site']=new URL(request.frame().url()).origin===u.origin?'same-origin':'cross-site';}catch{headers['sec-fetch-site']='none';}}
   const response=await context.request.fetch(`http://127.0.0.1:${fixture.port}${u.pathname}${u.search}`,{method:request.method(),headers,data:request.postDataBuffer()||undefined,maxRedirects:0});if(request.method()==='POST'&&(request.postData()||'').includes('document-workflow-inspection-'))writes++;
   // Chromium does not re-enter interception inside an HTTP redirect chain. Preserve the actual
   // controller Location/Cookie with a navigation bridge; every destination is still served by PHP.
   if(response.status()>=300&&response.status()<400&&response.headers().location){const target=new URL(response.headers().location,u).href;if(new URL(target).origin!=='https://hse.example')throw new Error('Foreign controller redirect.');const escaped=target.replaceAll('&','&amp;').replaceAll('"','&quot;').replaceAll('<','&lt;');const outgoing={...response.headers(),'content-type':'text/html; charset=utf-8'};delete outgoing.location;delete outgoing['content-length'];await route.fulfill({status:200,headers:outgoing,body:`<!doctype html><meta http-equiv="refresh" content="0;url=${escaped}">`});}
   else await route.fulfill({response});}catch(error){const message=error.message.split('\n')[0];if(/disposed|Target.*closed|context.*closed/i.test(message))return;throw new Error('Own HTTP transport failed: '+message);}});
 const page=await context.newPage();await page.goto('https://hse.example/login/');await page.locator('[name=email]').fill(fixture.accounts[role].email);await page.locator('[name=password]').fill(fixture.accounts[role].password);
 try{await Promise.all([page.waitForURL('**/vereinsverwaltung/**'),page.getByRole('button',{name:'Anmelden und Vereinsmanager öffnen',exact:true}).click()]);}
 catch(error){throw new Error('Actual login/navigation failed: '+(await page.locator('body').innerText()).slice(0,700)+'; '+error.message);}
 await page.locator('#main').waitFor();await page.waitForLoadState('networkidle');
 check(!await page.getByRole('button',{name:'Anmelden und Vereinsmanager öffnen',exact:true}).count(),'actual production login works with JS='+js);
 return{context,page};
}
const url=(mode,extra={})=>'https://hse.example/'+(mode==='native'?'vereinsverwaltung/':'direct/')+'?'+new URLSearchParams({view:'documents',tab:'inspections',inspection_status:'all',...extra});
const taskIds={};
async function fit(page,label){
 const ok=await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1);
 if(!ok){await page.screenshot({path:'/tmp/hse-inspections-overflow.png',fullPage:true});throw new Error('Actual inspection viewport overflow '+label);}
 check(ok,'actual inspection fits viewport '+label);
}
try{
 for(const mode of['native','direct']){
  console.log('Checking actual inspection lifecycle '+mode);
  const creator=await client('author',320,mode==='native');const page=creator.page;
  await page.goto('https://hse.example/'+(mode==='native'?'vereinsverwaltung/':'direct/')+'?'+new URLSearchParams({view:'documents',tab:'workflows',wf_doc:fixture.documents[mode],wf_revision:'4'}));
  await page.getByRole('link',{name:'Prüfauftrag zu dieser Fassung erteilen',exact:true}).click();
  await page.locator('.inspection-create').waitFor();
  check(await page.locator('[name=inspection_instruction]').inputValue()==='','actual implementation instruction starts empty for real human input');
  await page.locator('[name=inspection_title]').fill('Actual browser '+mode+' implementation');await page.locator('[name=inspection_instruction]').fill('Human instruction: remove the chairs and inspect both doors.');await page.locator('[name=inspection_assignee]').selectOption('reader');await page.locator('[name=inspection_deadline]').fill('2026-10-20');
  await page.getByRole('button',{name:'Auftrag erteilen',exact:true}).click();await page.locator('.inspection-focus .inspection-instruction').waitFor();
  const id=new URL(page.url()).searchParams.get('inspection');check(/^[a-f0-9]{32}$/.test(id||''),'actual controller creates independent operational task');taskIds[mode]=id;
  check((await page.locator('.inspection-source').innerText()).includes('Beurteilungsfassung 4'),'actual operational source remains pinned to original approved assessment');
  check(!await page.locator('.inspection-list').evaluate(el=>el.open),'selected operational instruction appears above collapsed bounded list');
  await page.locator('.inspection-state>summary').click();await page.locator('.inspection-state [name=inspection_comment]').fill('   ');await page.getByRole('button',{name:'Auftrag abbrechen',exact:true}).click();await page.locator('.inspection-workspace [role=alert]').waitFor();
  check(await page.locator('.inspection-edit [name=inspection_title]').inputValue()==='Actual browser '+mode+' implementation'&&await page.locator('.inspection-edit [name=inspection_instruction]').inputValue()==='Human instruction: remove the chairs and inspect both doors.','unrelated refused cancellation keeps existing editable instruction and title');
  await page.locator('.inspection-edit>summary').click();await page.locator('.inspection-edit [name=wf_version]').evaluate(el=>el.value='0');await page.locator('.inspection-edit [name=inspection_instruction]').fill('Human retained stale instruction');await page.getByRole('button',{name:'Auftrag speichern',exact:true}).click();await page.locator('.inspection-workspace [role=alert]').waitFor();
  check(await page.locator('.inspection-edit [name=wf_version]').inputValue()==='0'&&await page.locator('.inspection-edit [name=inspection_instruction]').inputValue()==='Human retained stale instruction','actual stale post retains human input and original stale revision');
  await page.getByRole('link',{name:'Aktuellen Stand öffnen',exact:true}).click();await page.locator('.inspection-focus .inspection-instruction').waitFor();
  await creator.context.close();
  const assignee=await client('reader',390,mode!=='native');await assignee.page.goto(url(mode,{inspection:id}));await assignee.page.locator('.inspection-submit').waitFor();
  check(!await assignee.page.locator('.inspection-edit').count()&&!await assignee.page.locator('.inspection-review').count(),'assigned reader gets implementation form without issuer or acceptance forms');
  await assignee.page.locator('[name=inspection_implementation]').fill('Human report: chairs removed; doors open fully.');await assignee.page.locator('[name=inspection_completed_on]').fill('2026-10-13');await assignee.page.getByRole('button',{name:'Umsetzung zur Abnahme melden',exact:true}).click();await assignee.page.locator('.inspection-evidence').waitFor();
  check((await assignee.page.locator('.inspection-focus .inspection-status').innerText())==='Zur Abnahme','actual personal implementation report waits for separate acceptance');await assignee.context.close();
  const reviewer=await client('author',390,false);await reviewer.page.goto(url(mode,{inspection:id}));await reviewer.page.locator('.inspection-review').waitFor();
  await reviewer.page.locator('.inspection-review [name=inspection_comment]').fill('Checked both actual doors.');await reviewer.page.getByRole('button',{name:'Umsetzung abnehmen',exact:true}).click();await reviewer.page.locator('.inspection-state').waitFor();
  check((await reviewer.page.locator('.inspection-focus .inspection-status').innerText())==='Abgenommen','actual issuer accepts operational evidence independently of the assessment');
  await reviewer.page.locator('.inspection-history>summary').click();check(await reviewer.page.locator('.inspection-history-entry').count()===3,'actual original create/report/acceptance history is readable');await reviewer.context.close();
  for(const enabled of(fixture.focused?[]:[true,false])){
   const c=await client('author',390,enabled);
   for(const width of[320,390,820])for(const zoom of[1,2]){
   console.log('Checking actual inspection viewport '+mode+'/'+width+'/'+zoom+'/'+enabled);
   const p=c.page;await p.setViewportSize({width,height:900});const errors=[];p.on('pageerror',e=>errors.push(e.message));await p.goto(url(mode,{inspection_status:'active'}));await p.locator('.inspection-card').first().waitFor();if(zoom===2)await p.evaluate(()=>document.documentElement.style.fontSize='200%');
   if(enabled)check(await p.evaluate(()=>typeof window.KulturhusNavigation?.navigate==='function'),'real manager JavaScript navigation loaded in '+mode);
   check(await p.locator('.inspection-card').count()===6,'six actual operational cards '+mode+'/'+width+'/'+zoom+'/'+enabled);await fit(p,'catalogue '+mode+'/'+width+'/'+zoom+'/'+enabled);
   const cardLink=p.locator('.inspection-card').first().getByRole('link',{name:'Auftrag öffnen',exact:true});const target=await cardLink.getAttribute('href');await cardLink.click();
   try{await p.locator('.inspection-focus').waitFor();}catch{throw new Error('Actual detail navigation failed '+mode+'/'+width+'/'+zoom+'/'+enabled+' URL '+p.url()+' target '+target+' visible errors '+JSON.stringify(await p.locator('.error').allTextContents()));}
   if(zoom===2)await p.evaluate(()=>document.documentElement.style.fontSize='200%');await fit(p,'detail '+mode+'/'+width+'/'+zoom+'/'+enabled);
   const edit=p.locator('.inspection-edit');if(await edit.count()){
    await edit.locator('summary').click();const form=edit.locator('form');await form.locator('[name=inspection_title]').waitFor({state:'visible'});const box=await form.locator('[name=inspection_title]').boundingBox();check(box.height>=44,'actual human task field has a touch target '+mode+'/'+width+'/'+zoom+'/'+enabled);
    await form.locator('[name=inspection_title]').focus();await p.keyboard.press('Tab');check(await form.locator('[name=inspection_instruction]').evaluate(el=>el===document.activeElement),'keyboard advances through actual instruction fields');await fit(p,'edit '+mode+'/'+width+'/'+zoom+'/'+enabled);
   }
   check(errors.length===0,'no actual inspection script errors');
   }
   await c.context.close();
  }
  const own=await client('member',320,false);await own.page.goto(url(mode));await own.page.locator('.inspection-list').waitFor();check(!await own.page.getByRole('link',{name:'Prüfauftrag erteilen',exact:true}).count(),'ordinary assigned member has no issuer control');check(!await own.page.getByRole('link',{name:'Gefährdungsbeurteilung',exact:true}).count(),'personal task navigation grants no HSE assessment access');await fit(own.page,'personal member');await own.context.close();
 }
 console.log(JSON.stringify({browser_checks:checks,actual_inspection_posts:writes,task_ids:taskIds,external_actions:0}));
}finally{await browser.close();}
