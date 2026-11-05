import fs from 'node:fs';
import {createHash} from 'node:crypto';
import {execFileSync} from 'node:child_process';
const f=JSON.parse(fs.readFileSync(0,'utf8'));
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE);
const browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE,headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
let checks=0,views=0,external=0,index=0;const errors=[];
const check=(ok,label)=>{if(!ok)throw Error(label);checks++;console.log('PASS '+label);};
async function login(page,origin,role){await page.goto(origin+'/login');await page.locator('[name=email]').fill(f.accounts[role].email);await page.locator('[name=password]').fill(f.password);await Promise.all([page.waitForURL(u=>u.pathname==='/vereinsverwaltung/'),page.getByRole('button',{name:/^(Anmelden und Vereinsmanager öffnen|Sign in and open club manager)$/}).click()]);}
async function language(page,origin,code){await page.goto(origin+'/account?section=language#account-language');const form=page.locator('form:has([name=operation][value=select-language])');await form.locator('[name=interface_locale]').selectOption(code);await Promise.all([page.waitForNavigation(),form.getByRole('button').click()]);}
async function context(origin,js){const ctx=await browser.newContext({viewport:{width:390,height:1000},javaScriptEnabled:js,hasTouch:true,serviceWorkers:'block'}),assets=new Map();await ctx.route('**/*',async r=>{const u=new URL(r.request().url());if(u.origin!==origin){external++;return r.abort();}if(r.request().method()==='GET'&&/\.(?:css|js|mjs|svg|png|woff2?)$/.test(u.pathname)){let saved=assets.get(u.href);if(!saved){const response=await r.fetch(),headers=response.headers();delete headers['set-cookie'];saved={status:response.status(),headers,body:await response.body()};assets.set(u.href,saved);}return r.fulfill(saved);}return r.continue();});return ctx;}
async function fit(page,label){const root=page.locator('[data-volunteer-workspace]'),nodes=await root.evaluateHandle(root=>[root,...root.querySelectorAll('*')].map(el=>({el,size:parseFloat(getComputedStyle(el).fontSize),original:el.style.fontSize}))),base=await root.evaluate(el=>parseFloat(getComputedStyle(el).fontSize));for(const width of[320,820])for(const scale of[100,200]){await page.setViewportSize({width,height:900});await page.evaluate(([rows,n])=>{for(const{el,size}of rows)el.style.fontSize=(size*n/100)+'px';},[nodes,scale]);views++;check(Math.abs(await root.evaluate(el=>parseFloat(getComputedStyle(el).fontSize))-base*scale/100)<.1,'actual volunteer interface text doubles '+label+' '+width+' '+scale);const fits=await root.evaluate(el=>{const r=el.getBoundingClientRect();return r.left>=-1&&r.right<=innerWidth+1&&el.scrollWidth<=el.clientWidth+1;});if(!fits){console.log('CHECK OVERFLOW '+JSON.stringify(await root.evaluate(root=>{const bounds=root.getBoundingClientRect();return {width:root.clientWidth,scroll:root.scrollWidth,boxes:[...root.querySelectorAll('*')].filter(el=>el.scrollWidth>el.clientWidth+1).slice(0,24).map(el=>({tag:el.tagName,classes:el.className,name:el.getAttribute('name'),width:el.clientWidth,scroll:el.scrollWidth,overflow:getComputedStyle(el).overflowX,position:getComputedStyle(el).position,before:getComputedStyle(el,'::before').content,after:getComputedStyle(el,'::after').content})),elements:[...root.querySelectorAll('*')].filter(el=>{const b=el.getBoundingClientRect();if(!b.width)return false;return b.left<bounds.left-1||b.right>bounds.right+1;}).slice(0,16).map(el=>{const b=el.getBoundingClientRect(),css=getComputedStyle(el);return {tag:el.tagName,classes:el.className,name:el.getAttribute('name'),text:el.textContent?.slice(0,70),left:b.left,right:b.right,width:b.width,min:css.minWidth,display:css.display,flex:css.flex,white:css.whiteSpace};})};})));await page.screenshot({path:(process.env.DVM_CHECKS_DIAGNOSTIC_DIR||f.root)+'/volunteer-font-overflow.png',fullPage:true});}check(fits,'actual volunteer interface controls fit with enlarged original text '+label+' '+width+' '+scale);}await page.evaluate(rows=>{for(const{el,original}of rows)el.style.fontSize=original;},nodes);await nodes.dispose();await page.setViewportSize({width:390,height:1000});}
const url=(origin,extra={})=>origin+'/vereinsverwaltung/?'+new URLSearchParams({view:'portal',tab:'safety',volunteer:'1',volunteer_kind:'general',...extra});
const results=[];
const hash=bytes=>createHash('sha256').update(bytes).digest('hex');
async function submit(page,form){const response=page.waitForResponse(r=>r.request().method()==='POST'&&new URL(r.url()).pathname==='/vereinsverwaltung/');await form.getByRole('button').last().click();const post=await response;await page.waitForLoadState('domcontentloaded');return post;}
async function download(page,link){const [file]=await Promise.all([page.waitForEvent('download'),link.click()]);return {sha:hash(fs.readFileSync(await file.path())),name:file.suggestedFilename()};}
try{
 for(const app of['front','direct'])for(const js of[true,false])for(const locale of['de','en']){
  const origin=f.origins[app],ctx=await context(origin,js),t=(de,en)=>locale==='en'?en:de,label=app+' '+locale+' '+js;let page=await ctx.newPage();
  const watch=target=>{target.on('pageerror',error=>errors.push(error.message));target.on('dialog',async dialog=>{try{await dialog.accept();}catch(error){if(!target.isClosed()&&!error.message.includes('Not attached to an active page'))throw error;}});};watch(page);await login(page,origin,'reader');await language(page,origin,locale);
  let response=await page.goto(url(origin));
  check(response.status()===200&&await page.locator('[data-volunteer-input]').count()===1&&await page.locator('[data-volunteer-person-picker]').count()===0,'own member with no general finance members or document role can prepare only their personal certificate '+label);
  check(await page.locator('[data-volunteer-workspace] h1').innerText()===t('Mitgliedschaft & Mitarbeit bestätigen','Confirm membership and volunteering'),'existing certificate has an explicitly owned interface language '+label);
  const activity='0\nOwn complete original activity '+index+' <em data-own-form>ä🙂</em>\n'+('ä'.repeat(1680));
  await fit(page,'original personal form '+label);
  let form=page.locator('[data-volunteer-input]');
  if(js){
   await form.locator('[name=first_name]').fill('');await form.getByRole('button').last().click();
   await form.locator('.field-validation-error').filter({hasText:t('Bitte dieses Feld ausfüllen.','Complete this field.')}).waitFor();
   check((await form.locator('.form-validation-summary').innerText()).includes(t('Bitte die markierten Angaben prüfen:','Check the marked fields:')),'actual empty required personal field is explained in the current interface language '+label);
   await fit(page,'localized missing personal field '+label);
  }
  for(const[name,value]of Object.entries(f.data.input))await form.locator('[name='+name+']').fill(value);
  const invalid='🙂'.repeat(1801);await form.locator('[name=activity]').fill(invalid);response=await submit(page,form);
  check(response.status()===422&&await page.locator('[data-volunteer-input] [name=activity]').inputValue()===invalid&&await page.locator('[data-volunteer-preview]').count()===0,'real overlong native proposal is refused with every original Unicode character available for correction '+label);
  await fit(page,'correctable full Unicode proposal '+label);
  form=page.locator('[data-volunteer-input]');await form.locator('[name=first_name]').fill('ä'.repeat(180));await form.locator('[name=activity]').fill(activity);
  response=await submit(page,form);await page.locator('[data-volunteer-preview]').waitFor();
  check(response.status()===200&&await page.locator('[data-volunteer-input] [name=first_name]').inputValue()==='ä'.repeat(180)&&await page.locator('[data-volunteer-input] [name=activity]').inputValue()===activity,'actual complete native preview keeps the full name limit literal markup line breaks and original activity '+label);
  check(await page.locator('[data-own-form]').count()===0,'human certificate input is escaped and never translated as interface markup '+label);
  const previewLink=page.locator('[data-volunteer-preview]').getByRole('link',{name:t('PDF-Vorschau in neuem Tab öffnen','Open the PDF preview in a new tab'),exact:true}),previewUrl=await previewLink.getAttribute('href'),preview=await ctx.request.get(new URL(previewUrl,page.url()).href),previewBytes=await preview.body();
  check(preview.status()===200&&previewBytes.subarray(0,5).toString()==='%PDF-','review link returns the actual private complete original PDF '+label);
  const text=execFileSync('pdftotext',['-','-'],{input:previewBytes,maxBuffer:4194304}).toString().replace(/\s+/gu,' ');
  check(text.includes('Own original club 0')&&text.includes('Own First Chair')&&text.includes('Own complete original activity')&&!text.includes('Own Different Member'),'actual review PDF keeps its original German declaration own current signer and private recipient '+label);
  await fit(page,'complete original preview '+label);
  const receiptBefore=await page.locator('[data-volunteer-confirmation]').getAttribute('href'),key=new URL(receiptBefore,origin).searchParams.get('volunteer_receipt');
  const unconfirmed=await ctx.request.get(new URL(receiptBefore,page.url()).href);
  check(unconfirmed.status()===404&&(await unconfirmed.text()).includes(t('Anlegebestätigung nicht verfügbar.','Creation confirmation unavailable.')),'read-only unconfirmed operation check does not claim a filing or perform a second POST '+label);
  if(js){
   await page.locator('[data-volunteer-input] [name=effort]').fill('Changed proposal');
   check(await page.locator('[data-volunteer-save] button').isDisabled()&&await page.locator('[data-volunteer-dirty]').isVisible(),'actual changed proposal disables the old consent without inventing a new reviewed PDF '+label);
   await page.locator('[data-volunteer-input] [name=effort]').fill('0');response=await submit(page,page.locator('[data-volunteer-input]'));await page.locator('[data-volunteer-preview]').waitFor();
   const repeated=new URL(await page.locator('[data-volunteer-confirmation]').getAttribute('href'),origin).searchParams.get('volunteer_receipt');
   check(repeated===key,'same actual unchanged re-preview keeps its original request identity '+label);
  }
  const submittedActivity=new URLSearchParams(response.request().postData()).get('activity');check(submittedActivity===activity.replace(/\n/g,'\r\n'),'actual native form transport retains the complete original activity with standard form line endings '+label);
  const save=page.locator('[data-volunteer-save]'),saveFields=await save.locator('input').evaluateAll(nodes=>Object.fromEntries(nodes.map(node=>[node.name,node.value])));saveFields.reviewed='1';
  if(js){
   await save.getByRole('button').click();
   await save.locator('.field-validation-error').filter({hasText:t('Bitte dieses Feld bestätigen.','Confirm this field.')}).waitFor();
   check(await save.locator('[name=reviewed]').isChecked()===false,'actual missing review remains unconfirmed with a localized required-consent message '+label);
   await fit(page,'localized missing original review consent '+label);
  }
  const unreviewed={...saveFields};delete unreviewed.reviewed;
  const deniedSave=await ctx.request.post(page.url(),{form:unreviewed,maxRedirects:0});
  check(deniedSave.status()===422&&(await deniedSave.text()).includes(t('Formularangaben vor dem Ablegen bestätigen.','Confirm the form details before filing.')),'actual server refuses an authenticated filing without the explicit original review confirmation '+label);
  await save.locator('[name=reviewed]').check();
  if(js){
   const saveUrl=new URL(await save.getAttribute('action'),page.url());saveUrl.hash='';let accepted;
   const loseResponse=async route=>{if(route.request().method()!=='POST')return route.fallback();const headers=await route.request().allHeaders();if(headers.origin==='null'&&new URL(route.request().url()).origin===origin&&new URL(page.url()).origin===origin)headers['sec-fetch-site']='same-origin';accepted=await route.fetch({maxRedirects:0,headers});if(![302,303].includes(accepted.status()))console.log('OWN REQUEST ORIGIN '+JSON.stringify({origin:headers.origin,site:headers['sec-fetch-site'],host:headers.host,dispatcher:accepted.headers()['x-owned-period-dispatcher'],status:accepted.status()}));await route.abort('failed');};
   await ctx.route(saveUrl.href,loseResponse);
   const lost=page.waitForEvent('requestfailed',{predicate:request=>request.method()==='POST'});
   await Promise.allSettled([lost,save.getByRole('button').click({noWaitAfter:true})]);await ctx.unroute(saveUrl.href,loseResponse);
   if(!accepted||![302,303].includes(accepted.status()))console.log('LOST RESPONSE DIAGNOSTIC '+JSON.stringify({accepted:!!accepted,status:accepted?.status(),text:accepted?(await accepted.text()).slice(0,1000):''}));
   check(accepted&&(accepted.status()===302||accepted.status()===303),'actual server accepts the reviewed original before its browser response is deliberately lost '+label);
   await page.close({runBeforeUnload:false});page=await ctx.newPage();watch(page);await page.goto(url(origin));
   const recovery=page.locator('[data-volunteer-confirmation]');
   check(new URL(await recovery.getAttribute('href'),page.url()).searchParams.get('volunteer_receipt')===key,'reopened native form offers the same original read-only filing check after a lost response '+label);
   await Promise.all([page.waitForURL(u=>u.searchParams.get('volunteer_receipt')===key),recovery.tap()]);
  }else await submit(page,save);
  await page.locator('[data-volunteer-receipt]').waitFor();
  const originalDownload=page.locator('[data-volunteer-receipt]').getByRole('link',{name:t('Abgelegtes PDF herunterladen','Download the filed PDF'),exact:true});
  const document=new URL(await originalDownload.getAttribute('href'),page.url()).searchParams.get('volunteer_file');
  check(document&&new URL(page.url()).searchParams.get('volunteer_receipt')===key,'actual reviewed save opens its own original filing confirmation '+label);
  const downloaded=await download(page,page.locator('[data-volunteer-receipt]').getByRole('link',{name:t('Abgelegtes PDF herunterladen','Download the filed PDF'),exact:true}));
  check(downloaded.sha===hash(previewBytes)&&downloaded.name==='ehrenamtsbestaetigung.pdf','reviewed preview and downloaded filed original are byte-for-byte identical '+label);
  const replay=await ctx.request.post(page.url(),{form:saveFields,maxRedirects:0});
  check(replay.status()===303||replay.status()===302,'actual same authenticated save replay returns its confirmed original instead of another document '+label);
  const location=replay.headers().location;check(new URL(location,page.url()).searchParams.get('volunteer_document')===document,'replayed controller acknowledgement retains the original document identifier '+label);
  response=await page.goto(url(origin,{volunteer_receipt:key}));
  check(response.status()===200&&await page.locator('[data-volunteer-receipt]').count()===1,'original filing can be explicitly reopened by a read after the original POST '+label);
  await fit(page,'original personal filing acknowledgement '+label);
  const denied=await ctx.request.get(url(origin,{person:f.data.other}));check(denied.status()===403&&!(await denied.text()).includes('value="Own Different Member"'),'forged other-profile request has no editable foreign personal details '+label);
  results.push({document,key,activity:submittedActivity,preview_sha256:hash(previewBytes),download_sha256:downloaded.sha});
  response=await page.goto(origin+'/vereinsverwaltung/?view=portal&tab=safety');
  check(response.status()===200&&await page.locator('[data-volunteer-guide] h1').innerText()===t('Sicher helfen im Verein','Safe volunteering in the club'),'native safety guide uses the current interface language and a club-neutral product title '+label);
  const guide=page.locator('[data-volunteer-guide]');
  check((await guide.innerText()).includes(t('Landkreis Wesermarsch','Landkreis Wesermarsch'))&&(await guide.innerText()).includes(t('Diese Behördenhinweise beziehen sich auf Niedersachsen','This authority guidance refers to Niedersachsen')),'regional authority instructions remain explicitly scoped rather than asserted for every club '+label);
  for(const details of await guide.locator('details').all())if(!await details.evaluate(node=>node.open))await details.locator('summary').click();
  check((await guide.innerText()).includes(t('Quellen zuletzt geprüft: 06.09.2026','Sources last checked: 2026-09-06'))&&(await guide.innerText()).includes('112'),'expanded guide retains its actual source date and emergency number '+label);
  const guideLinks=await guide.locator('a[href^="https://"]').evaluateAll(nodes=>nodes.map(node=>({href:node.href,target:node.target,rel:node.rel})));
  check(guideLinks.length>=10&&guideLinks.every(link=>link.target==='_blank'&&link.rel.includes('noopener')),'official source links remain deliberate separate-tab links without automatic external calls '+label);
  const guideRoot=page.locator('[data-volunteer-guide]');await guideRoot.evaluate(node=>node.setAttribute('data-volunteer-workspace',''));
  await fit(page,'expanded original regional safety guide '+label);
  await ctx.unrouteAll({behavior:'wait'});await ctx.close();index++;
 }
 for(const app of['front','direct']){
  const origin=f.origins[app],ctx=await context(origin,true),page=await ctx.newPage();page.on('pageerror',error=>errors.push(error.message));await login(page,origin,'admin');await language(page,origin,'en');let response=await page.goto(url(origin));
  check(response.status()===200&&await page.locator('[data-volunteer-person]').count()===25&&await page.locator('[data-volunteer-input]').count()===0,'current board native picker renders only twenty-five available person names without selecting a substitute '+app);
  const searchForm=page.locator('[data-volunteer-person-picker] form:has(input[type=search][name=volunteer_person_query])'),literal='Own choice 28 <em data-own-person>0</em> ä🙂';
  await searchForm.locator('[name=volunteer_person_query]').fill(literal);
  const searched=page.waitForResponse(response=>response.request().method()==='GET'&&new URL(response.url()).searchParams.get('volunteer_person_query')===literal);
  await searchForm.locator('[name=volunteer_person_query]').press('Enter');const searchResponse=await searched;await page.waitForFunction(()=>!document.getElementById('main')?.hasAttribute('aria-busy'));
  check(searchResponse.status()===200&&await page.locator('[data-volunteer-person]').count()===1&&await page.locator('[data-volunteer-person]').textContent()===literal&&await page.locator('[data-own-person]').count()===0,'actual Enter search reaches a literal human name without treating its original markup as interface HTML '+app);
  await page.goto(url(origin));
  const nav=page.getByRole('navigation',{name:'Person pages',exact:true}).first();await Promise.all([page.waitForURL(u=>u.searchParams.get('volunteer_person_page')==='2'),nav.getByRole('link',{name:'Next page',exact:true}).tap()]);
  check(await page.locator('[data-volunteer-person]').count()===6,'native board page link reaches the remaining actual person names '+app);
  const picker=page.locator('[data-volunteer-person-picker] form:has(select[name=person])');await picker.locator('[name=person]').selectOption(f.data.person);check(await picker.locator('[name=person]').inputValue()===f.data.person,'actual chosen native person is retained before deliberate submission '+app);await Promise.all([page.waitForURL(u=>u.searchParams.get('person')===f.data.person),picker.getByRole('button',{name:'Use the details',exact:true}).click()]);await page.locator('[data-volunteer-input]').waitFor();
  check(await page.locator('[data-volunteer-input]').count()===1&&new URL(page.url()).searchParams.get('volunteer_person_page')==='2','actual native board selection keeps the deliberate person and its current picker page '+app);
  response=await page.goto(url(origin,{volunteer_person_page:'0'}));check(response.status()===422&&await page.locator('[data-volunteer-person]').count()===0&&await page.locator('[data-volunteer-input]').count()===0,'invalid board picker never opens an arbitrary person form '+app);
  const q='🙂'.repeat(241);response=await page.goto(url(origin,{volunteer_person_query:q}));check(response.status()===422&&await page.locator('[name=volunteer_person_query]').inputValue()===q,'complete invalid native person search remains available for correction '+app);
  const otherReceipt=await ctx.request.get(url(origin,{volunteer_receipt:f.data.old_key}));check(otherReceipt.status()===404,'general administrator cannot read another creator original confirmation '+app);
  await ctx.unrouteAll({behavior:'wait'});await ctx.close();
 }
 for(const app of['front','direct']){
  const origin=f.origins[app],ctx=await context(origin,true),page=await ctx.newPage();await login(page,origin,'foreign');await language(page,origin,'en');
  const denied=await ctx.request.get(origin+'/vereinsverwaltung/?volunteer_file='+f.data.old_document);check(denied.status()===403&&(await denied.text()).includes('Volunteer certificate unavailable.'),'other-club original download is refused in the active interface language '+app);
  await ctx.unrouteAll({behavior:'wait'});await ctx.close();
 }
 check(errors.length===0,'native personal certificate interfaces have no JavaScript errors '+JSON.stringify(errors));check(external===0,'native personal certificate interfaces make no external requests');
 fs.writeFileSync(f.root+'/volunteer-interface-results.json',JSON.stringify(results));console.log(JSON.stringify({suite:'volunteer-interface-browser',checks,views,cases:index,external_actions:0}));
}catch(error){
 for(const ctx of browser.contexts())for(const page of ctx.pages())try{console.log('FAILED VOLUNTEER STATE '+JSON.stringify({url:page.url().slice(0,300),alerts:await page.getByRole('alert').allTextContents(),text:(await page.locator('main').innerText()).slice(0,1800)}));}catch{}
 throw error;
}finally{await browser.close();}
