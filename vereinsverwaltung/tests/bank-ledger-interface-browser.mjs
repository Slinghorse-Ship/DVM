import fs from 'node:fs';
import crypto from 'node:crypto';
const fixture=JSON.parse(fs.readFileSync(0,'utf8'));
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE);
const browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE,headless:true});
let checks=0,views=0,external=0,activeJs=false,stage='start',activePage=null;const errors=[],accepted={};
const check=(ok,label)=>{if(!ok)throw Error(label);checks++;};
const go=(page,url)=>page.goto(url,{waitUntil:'domcontentloaded'});
const url=(origin,query={})=>origin+'/vereinsverwaltung/?'+new URLSearchParams({view:'finance',tab:'bank',...query});
async function login(page,origin,role,locale){
 await go(page,origin+'/login');await page.locator('[name=email]').fill(fixture.accounts[role].email);await page.locator('[name=password]').fill(fixture.password);
 await Promise.all([page.waitForResponse(r=>r.request().method()==='POST'&&new URLSearchParams(r.request().postData()||'').get('operation')==='login'),page.getByRole('button',{name:/^(Anmelden und Vereinsmanager öffnen|Sign in and open club manager)$/}).click()]);
 await page.waitForFunction(()=>!document.querySelector('form [name=operation][value=login]')&&document.querySelector('main')&&!/^\/login\/?$/.test(location.pathname));
 await go(page,origin+'/account?section=language');const form=page.locator('form:has([name=operation][value=select-language])');await form.locator('[name=interface_locale]').selectOption(locale);await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),form.getByRole('button').click()]);
 return page.locator('form:has([name=operation][value=select-language]) [name=csrf]').inputValue();
}
async function fit(page,label,scope){
 for(const width of [320,390,820])for(const size of [16,32]){
  await page.setViewportSize({width,height:1000});await page.evaluate(value=>document.documentElement.style.fontSize=value+'px',size);
  await page.waitForFunction(()=>document.documentElement.scrollWidth<=innerWidth+1,null,{timeout:3000}).catch(()=>{});
  const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1);
  if(overflow)console.error(await page.evaluate(()=>[...document.querySelectorAll('body *')].filter(e=>e.getBoundingClientRect().right>innerWidth+1).slice(0,10).map(e=>({tag:e.tagName,cls:e.className,right:e.getBoundingClientRect().right,overflow:getComputedStyle(e).overflow,width:e.getBoundingClientRect().width,parent:e.parentElement?.className}))));
  check(!overflow,label+' fits '+width+'/'+size);
  check(await page.locator(scope+' button,'+scope+' input:not([type=hidden]):not([type=radio]),'+scope+' select').evaluateAll(nodes=>nodes.filter(n=>n.getClientRects().length).every(n=>n.getBoundingClientRect().height>=43.5)),label+' has44px controls');views++;
 }
 await page.evaluate(()=>document.documentElement.style.fontSize='16px');await page.setViewportSize({width:390,height:1000});
}
async function visibleAmounts(page,profile,label){
 await page.waitForLoadState('load');
 for(const width of [320,390,820,1440])for(const size of [16,32]){
  await page.setViewportSize({width,height:1000});await page.evaluate(value=>document.documentElement.style.fontSize=value+'px',size);
  // Never scroll an amount into view: horizontal clipping is the original bug.
  const geometry=await page.locator('[data-bank-transaction-amount]').evaluateAll(nodes=>nodes.map(node=>{
   const region=node.closest('.table-scroll'),box=node.getBoundingClientRect(),parent=region.getBoundingClientRect();
   const range=document.createRange();range.selectNodeContents(node);const text=range.getBoundingClientRect();
   return {scroll:region.scrollLeft,left:box.left,right:box.right,textLeft:text.left,textRight:text.right,min:Math.max(0,parent.left),max:Math.min(innerWidth,parent.right),value:node.textContent,direction:node.nextElementSibling.textContent};
  }));
  if(geometry.some(g=>g.scroll!==0||g.left<g.min-1||g.right>g.max+1||g.textLeft<g.min-1||g.textRight>g.max+1))console.error(JSON.stringify({label,width,size,geometry}));
  check(geometry.length>0&&geometry.every(g=>g.scroll===0&&g.left>=g.min-1&&g.right<=g.max+1&&g.textLeft>=g.min-1&&g.textRight<=g.max+1),label+' whole original amount visible without horizontal scroll '+width+'/'+size);
  check(geometry.every(g=>/^[+−-]/.test(g.value)&&g.direction===(profile.locale==='en'?(g.value.startsWith('+')?'Incoming':'Outgoing'):(g.value.startsWith('+')?'Eingang':'Ausgang'))),label+' signed original amount and translated direction');
  check(await page.locator('[data-bank-journal-row] .status').count()===geometry.length,label+' independent allocation status remains visible');
  check(await page.locator('[data-bank-journal-row] .status').evaluateAll(nodes=>nodes.every(node=>{const box=node.getBoundingClientRect(),range=document.createRange();range.selectNodeContents(node);const text=range.getBoundingClientRect();return box.left>=-1&&box.right<=innerWidth+1&&text.left>=-1&&text.right<=innerWidth+1;})),label+' complete allocation status fits at '+width+'/'+size);
  if(width===320||width===820){const artifacts=new URL('../../.local/bank-ledger-amounts/',import.meta.url);fs.mkdirSync(artifacts,{recursive:true});await page.locator('[data-bank-journal-row]').first().evaluate(node=>window.scrollTo(0,scrollY+node.getBoundingClientRect().top-8));await page.screenshot({path:new URL('amount-'+profile.tag+'-'+label+'-'+width+'-'+size+'.png',artifacts).pathname,fullPage:false});}
  views++;
 }
 await page.evaluate(()=>document.documentElement.style.fontSize='16px');await page.setViewportSize({width:390,height:1000});
}
async function post(page,form,action,{confirm=false}={}){
 const nonce=crypto.randomBytes(12).toString('hex');await form.evaluate((node,value)=>node.dataset.ownLedgerSubmit=value,nonce);
 const match=r=>r.method()==='POST'&&new URLSearchParams(r.postData()||'').get('action')===action;
 const request=page.waitForRequest(match),response=page.waitForResponse(r=>match(r.request()));
 const loaded=activeJs?Promise.resolve():page.waitForNavigation({waitUntil:'domcontentloaded'});
 const submit=action==='finance-account-settings'?form.getByRole('button',{name:/^(Änderungen speichern|Save changes)$/}):form.getByRole('button').first();const click=submit.press('Enter');await click;
 if(confirm&&activeJs){const dialog=page.locator('dialog.kh-dialog[open]');await dialog.waitFor();await dialog.getByRole('button').last().click();}
 const [sent]=await Promise.all([request,response,loaded]);if(activeJs)await page.waitForFunction(nonce=>!document.querySelector('[data-own-ledger-submit="'+nonce+'"]'),nonce);
 return new URLSearchParams(sent.postData());
}
try{
 const profiles=fixture.data.profiles.filter(p=>!process.env.DVM_BANK_LEDGER_CASE||process.env.DVM_BANK_LEDGER_CASE===p.tag);
 for(const [index,profile] of profiles.entries()){
  stage=profile.tag;console.log('Bank ledger '+stage);activeJs=profile.js;const origin=fixture.origins[profile.app],data=fixture.data.cases[profile.tag],en=profile.locale==='en';
  const context=await browser.newContext({javaScriptEnabled:profile.js,viewport:{width:390,height:1000},hasTouch:true,serviceWorkers:'block',extraHTTPHeaders:{'X-Owned-Bank-Ledger-Client':String(index+1)}});
  await context.route('**/*',route=>new URL(route.request().url()).origin===origin?route.continue():(external++,route.abort()));
  const page=await context.newPage();activePage=page;page.on('pageerror',e=>errors.push(e.message));await login(page,origin,'admin',profile.locale);
  await go(page,url(origin,{account:fixture.data.bank,bank_page:'2'}));await page.locator('[data-bank-journal-paged]').waitFor();
  check(await page.getByRole('heading',{name:en?'Bank & cash':'Bank & Kasse',exact:true}).count()===1,'actual workspace heading follows personal language');
  check(await page.locator('[data-bank-journal-row]').count()===40,'real complete ledger remains bounded');
  check((await page.locator('[data-bank-allocation-help]').innerText()).includes(en?'Invoice payment status':'kein Rechnungsstatus'),'allocation meaning follows personal language');
  check(await page.locator('[data-bank-catalogue-summary]').innerText()=== (en?'Page 2 of 3 · 82 matches':'Seite 2 von 3 · 82 Treffer'),'actual complete pager follows personal language');
  await fit(page,'journal','[data-bank-journal]');
  await visibleAmounts(page,profile,'journal');
  for(const [name,query] of [['outgoing','Ledger original 81'],['large','Ledger original 79'],['partial','Ledger original 1'],['assigned','Ledger original 2']]){await go(page,url(origin,{account:fixture.data.bank,bank_q:query}));await page.locator('[data-bank-transaction-amount]').first().waitFor();await visibleAmounts(page,profile,name);}
  const filter=page.locator('[data-bank-journal-filter]');await filter.locator('[name=bank_q]').fill('<&>');await filter.locator('[name=bank_amount]').fill('2,50');await filter.locator('[name=bank_from]').fill('2022-01-01');await filter.locator('[name=bank_to]').fill('2021-01-01');
  await Promise.all([page.waitForURL(u=>u.searchParams.get('bank_from')==='2022-01-01'),filter.getByRole('button').click()]);await page.locator('[data-bank-journal-filter]').waitFor();
  check(await page.locator('[data-bank-journal-filter] [name=bank_q]').inputValue()==='<&>'&&await page.locator('[data-bank-journal-filter] [name=bank_amount]').inputValue()==='2,50','actual rejected filter retains literal search and amount');
  check((await page.locator('[data-bank-journal] [role=alert]').innerText()).includes(en?'start date':'Von-Datum'),'actual rejected period message follows personal language');
  await go(page,url(origin,{account:fixture.data.bank,bank_q:'<&>'}));const row=page.locator('[data-bank-transaction="'+fixture.data.sources[0]+'"]');
  check(await row.locator('td').nth(4).locator('a').first().innerText()===fixture.data.description,'raw bank wording remains exact in both languages');
  check((await row.locator('.remaining-note').innerText()).includes(en?'Still to allocate':'Noch zuzuordnen'),'remaining real money has an explicit allocation label');
  const target=new URL(await row.locator('td').nth(4).locator('a').first().getAttribute('href'),origin);check(target.searchParams.get('transaction')===fixture.data.sources[0]&&target.searchParams.get('bank_q')==='<&>','assignment entry point retains the actual source and full filter');
  await go(page,url(origin,{account:fixture.data.cash,from:'2021-06-02',to:'2021-06-01'}));
  check(await page.locator('[data-cashbook-filter] [name=from]').inputValue()==='2021-06-02'&&await page.locator('[data-cashbook-error]').count()===1,'actual invalid cash dates retain usable correction controls');await fit(page,'cash date error','[data-cashbook-filter]');
  await go(page,url(origin,{account:data.account,edit_account:'1'}));await page.locator('#account-settings').waitFor();await fit(page,'account settings','#account-settings');
  const settings=page.locator('form:has([name=action][value=finance-account-settings])');const version=await settings.locator('[name=version]').inputValue();await settings.locator('[name=name]').fill('Unchanged original request <&> ä🙂');await settings.locator('[name=fints_read]').selectOption('0');await settings.locator('[name=fints_write]').selectOption('1');
  await post(page,settings,'finance-account-settings');const rejected=page.locator('form:has([name=action][value=finance-account-settings])');
  check(await rejected.locator('[name=name]').inputValue()==='Unchanged original request <&> ä🙂'&&await rejected.locator('[name=version]').inputValue()===version&&await rejected.locator('[name=fints_write]').inputValue()==='1'&&await rejected.locator('[name=fints_read]').inputValue()==='0','actual rejected account edit retains name version and both FinTS choices');
  check((await page.locator('[role=alert]').first().innerText()).includes(en?'also requires read access':'benötigt auch FinTS-Lesen'),'actual account edit rejection is translated');
  await go(page,url(origin,{new_account:'1'}));await page.locator('[data-account-create]').waitFor();const create=page.locator('[data-account-create]');await create.locator('[name=kind][value=cash]').check();await create.locator('[name=name]').fill('Umsätze ä🙂');await create.locator('[name=opening]').fill('17,00');await create.locator('[name=opened_on]').fill('2021-06-01');
  if(profile.js)check(await create.getByRole('button').innerText()===(en?'Save account':'Konto speichern'),'script uses the current language after account type choice');
  else check(await page.locator('[data-account-help]:visible').count()===3,'all choice guidance stays accessible without scripts');
  await post(page,create,'create-account');check(await page.locator('[data-account-create] [name=name]').inputValue()==='Umsätze ä🙂'&&await page.locator('[data-account-create] [name=opening]').inputValue()==='17,00'&&await page.locator('[data-account-create] [name=kind][value=cash]').isChecked(),'actual duplicate account error preserves the whole selected cashbox draft');
  check((await page.locator('[role=alert]').first().innerText()).includes(en?'already in use':'bereits vergeben'),'duplicate name rejection is translated');await fit(page,'account creation error','.account-create-content');
  const name='Created cash '+profile.tag+' <&> ä🙂';await page.locator('[data-account-create] [name=name]').fill(name);await post(page,page.locator('[data-account-create]'),'create-account');await page.locator('[data-bank-ledger-main]').waitFor();
  const account=new URL(page.url()).searchParams.get('account');check(!!account&&account!==data.account,'actual chosen new cashbox opens after acceptance');
  await go(page,url(origin,{account:data.account,new_transaction:'1'}));const movement=page.locator('form.transaction-new');const key=await movement.locator('[name=request_key]').inputValue();
  await movement.locator('[name=amount]').fill('3,007');await movement.locator('[name=booked_on]').fill('2021-06-01');await movement.locator('[name=counterparty]').fill('0 <&> ä🙂');const description='Actual recorded money '+profile.tag+' <&> ä🙂';await movement.locator('[name=description]').fill(description);
  await post(page,movement,'create-transaction');const correction=page.locator('form.transaction-new');
  check(await correction.locator('[name=amount]').inputValue()==='3,007'&&await correction.locator('[name=request_key]').inputValue()===key&&await correction.locator('[name=description]').inputValue()===description&&await correction.locator('[name=counterparty]').inputValue()==='0 <&> ä🙂','actual movement failure preserves all originals and retry identity');
  check((await page.locator('[role=alert]').first().innerText()).includes(en?'two decimal places':'zwei Nachkommastellen'),'actual invalid amount rejection follows personal language');await fit(page,'movement correction','form.transaction-new');
  await correction.locator('[name=amount]').fill('3,07');const sent=await post(page,correction,'create-transaction');const transaction=new URL(page.url()).searchParams.get('transaction');check(!!transaction,'actual recorded movement opens its own assignment');
  const response=await context.request.post(url(origin,{account:data.account,new_transaction:'1'}),{form:Object.fromEntries(sent),maxRedirects:0});check(response.status()===303&&new URL(response.headers().location,origin).searchParams.get('transaction')===transaction,'exact actual accepted request retries the same single movement');
  await go(page,url(origin,{account:data.cash,from:'2021-06-01',to:'2021-06-02'}));await page.locator('#cash-close > summary').click();const close=page.locator('form:has([name=action][value=close-cashbook])');await close.locator('[name=through]').fill('2021-06-01');await close.locator('[name=counted]').fill('99,97');
  await post(page,close,'close-cashbook',{confirm:true});check(await page.locator('#cash-close').getAttribute('open')!==null&&await page.locator('[name=counted]').inputValue()==='99,97'&&await page.locator('[name=through]').inputValue()==='2021-06-01','actual refused cash closing reopens with exact counted amount and date');
  check((await page.locator('[role=alert]').first().innerText()).includes(en?'does not match':'stimmt nicht'),'actual count mismatch is translated');await fit(page,'cash closing correction','#cash-close');
  await page.locator('[name=counted]').fill('100,00');await post(page,page.locator('form:has([name=action][value=close-cashbook])'),'close-cashbook',{confirm:true});
  check((await page.locator('body').innerText()).includes(en?'Cash period closed.':'Kassenzeitraum abgeschlossen.'),'actual corrected closing has a translated success notice');
  accepted[profile.tag]={account,name,transaction,key,description};await context.close();
  for(const [role,offset] of [['reader',20],['foreign',40]]){
   const restricted=await browser.newContext({javaScriptEnabled:profile.js,extraHTTPHeaders:{'X-Owned-Bank-Ledger-Client':String(index+offset)}});await restricted.route('**/*',route=>new URL(route.request().url()).origin===origin?route.continue():(external++,route.abort()));const restrictedPage=await restricted.newPage();restrictedPage.on('pageerror',e=>errors.push(e.message));await login(restrictedPage,origin,role,profile.locale);
   await go(restrictedPage,url(origin,{account:fixture.data.bank,bank_q:'<&>'}));
   if(role==='reader')check(await restrictedPage.locator('[data-bank-journal-row]').count()===1&&await restrictedPage.locator('[data-bank-workspace] form').count()===0&&await restrictedPage.locator('.bank-heading .actions a:not([href="#fints-schedule"])').count()===0,'current reader inspects exact own originals without write controls');
   else check(await restrictedPage.locator('[data-bank-journal-row]').count()===0&&!(await restrictedPage.locator('body').innerText()).includes(fixture.data.description),'foreign actor receives no known own source original');
   await go(restrictedPage,origin+'/account?section=language');const restrictedCsrf=await restrictedPage.locator('form:has([name=operation][value=select-language]) [name=csrf]').inputValue();
   const denied=await restricted.request.post(url(origin,{account:data.account}),{form:{csrf:restrictedCsrf,action:'create-transaction',request_key:crypto.randomBytes(16).toString('hex'),account:data.account,booked_on:'2021-06-01',direction:'income',amount:'1.00',description:'Denied restricted money'}});const rejection=await denied.text();const alerts=await restrictedPage.evaluate(html=>[...new DOMParser().parseFromString(html,'text/html').querySelectorAll('[role=alert],.error')].map(node=>node.textContent),rejection);const permissionDenied=alerts.some(text=>role==='reader'?/Keine ausreichende Finanzberechtigung|Insufficient finance permission/.test(text):/Konto nicht verfügbar|Account unavailable/.test(text));if(!permissionDenied)console.error(JSON.stringify({role,status:denied.status(),alerts}));check([200,403,422].includes(denied.status())&&!denied.headers().location?.includes('transaction=')&&permissionDenied,'restricted direct POST with its own current CSRF is denied by its actual permission or club boundary');await restricted.close();
  }
 }
 check(external===0&&errors.length===0,'all actual contexts have no external requests or script errors');fs.writeFileSync(fixture.root+'/ledger-results.json',JSON.stringify({accepted}));console.log(JSON.stringify({suite:'bank-ledger-interface-browser',checks,views,profiles:profiles.length,external_actions:external}));
}catch(error){console.error('Bank ledger stage '+stage);if(activePage&&!activePage.isClosed()){await activePage.screenshot({path:'/workspace/kulturhus-gen42-browser-failure.png',fullPage:false});fs.writeFileSync('/workspace/kulturhus-gen42-browser-failure.html',await activePage.content());console.error(JSON.stringify({url:activePage.url(),alerts:await activePage.locator('[role=alert]').allTextContents()}));}throw error;}finally{await browser.close();}
