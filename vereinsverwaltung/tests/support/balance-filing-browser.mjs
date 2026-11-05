import fs from 'node:fs';
import assert from 'node:assert/strict';
const f=JSON.parse(fs.readFileSync(0,'utf8'));
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE);
const browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE,headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
let checks=0,views=0,fonts=0,posts=0,external=0,client=0;
const rewrittenBodies=new WeakMap();
const errors=[],accepted=[],check=(value,label)=>{assert(value,label);checks++;};
const artifact=process.env.DVM_BALANCE_FILING_ARTIFACT_DIR||f.root;
fs.mkdirSync(artifact,{recursive:true,mode:0o700});
function route(origin,extra={}){return origin+'/vereinsverwaltung/?'+new URLSearchParams({view:'finance',tab:'tax',tax_filings:'1',tax_year:'2025',...extra});}
async function context(origin,js=true,locale='de',role='admin'){
 console.log('Balance filing profile '+origin+' '+js+' '+locale+' '+role);
 const c=await browser.newContext({viewport:{width:390,height:1100},hasTouch:true,javaScriptEnabled:js,serviceWorkers:'block',extraHTTPHeaders:{'X-Owned-Finance-Help-Client':String(++client)}});
 await c.route('**/*',r=>{
  const request=r.request(),url=new URL(request.url());if(url.origin!==origin){external++;return r.abort();}
  const page=request.frame().page(),body=rewrittenBodies.get(page);
  return request.method()==='POST'&&url.pathname==='/vereinsverwaltung/'&&url.searchParams.get('view')==='finance'&&body!==undefined?r.continue({postData:body}):r.continue();
 });
 c.on('request',r=>{if(r.method()==='POST'&&new URL(r.url()).pathname.startsWith('/vereinsverwaltung/'))posts++;});
 const p=await c.newPage();p.on('pageerror',e=>errors.push(e.message));
 const login=await p.goto(origin+'/login',{waitUntil:'domcontentloaded'});
 check(login.status()===200&&await p.locator('[name=email]').count()===1,'actual personal login opens');
 await p.locator('[name=email]').fill(f.accounts[role].email);await p.locator('[name=password]').fill(f.password);
 await Promise.all([p.waitForURL(u=>u.pathname==='/vereinsverwaltung/',{waitUntil:'domcontentloaded'}),p.getByRole('button',{name:/^(Anmelden und Vereinsmanager öffnen|Sign in and open club manager)$/}).click()]);
 await p.goto(origin+'/account?section=language',{waitUntil:'domcontentloaded'});
 const language=p.locator('form:has([name=operation][value=select-language])');await language.locator('[name=interface_locale]').selectOption(locale);
 await Promise.all([p.waitForNavigation({waitUntil:'domcontentloaded'}),language.getByRole('button').click()]);
 await p.locator('html[lang^="'+locale+'"]').waitFor({state:'attached'});
 return {c,p};
}
async function expose(control){
 for(let depth=0;depth<16;depth++){const outer=control.locator('xpath=ancestor::details[not(@open) and not(ancestor::details[not(@open)])]');if(!await outer.count())return;await outer.first().locator(':scope>summary').tap();}
 throw new Error('Original details nesting exceeds its explicit bound');
}
async function submit(p,button,status=303,keyboard=false){
 await expose(button);const response=p.waitForResponse(r=>r.request().method()==='POST'&&new URL(r.url()).pathname.startsWith('/vereinsverwaltung/'));
 const nav=p.waitForNavigation({waitUntil:'domcontentloaded'});if(keyboard){check(await button.evaluate(node=>node===document.activeElement),'actual Tab reaches the complete save action');await button.press('Enter');}else await button.click();const reply=await response;await nav;
 console.log('Balance POST '+reply.status()+' expected '+status);if(reply.status()!==status&&! [302,303].includes(reply.status()))await fs.promises.writeFile(artifact+'/failed-post.html',await reply.text());
 check(reply.status()===status,'actual complete POST expected '+status+' got '+reply.status()+(reply.status()===status?'':' '+(await p.locator('body').innerText()).slice(-2000)));
 views++;
}
async function choose(select,value){
 await expose(select);
 if(await select.getAttribute('aria-hidden')==='true'){
  const label=await select.evaluate((node,value)=>[...node.options].find(o=>o.value===value)?.textContent.trim(),value);assert(label);
  const input=select.locator('xpath=preceding-sibling::input[@data-select-search-input][1]');await input.fill(label);const id=await input.getAttribute('aria-controls');await select.page().locator('#'+id).getByRole('option',{name:label,exact:true}).click();
 }else await select.selectOption(value);
 check(await select.inputValue()===value,'actual choice preserves its original machine value');
}
async function saved(p,operation,record=true){
 const node=p.locator('[data-balance-local-saved]');await node.waitFor();const key=await node.getAttribute('data-balance-local-key'),sha256=(await p.locator('[data-balance-local-sha]').textContent()).trim();
 const result=JSON.parse(await p.locator('[data-balance-local-original]').textContent());if(record)accepted.push({key,sha256,operation});return {key,sha256,result};
}
async function formBody(form,action){
 return form.evaluate((node,action)=>{const values=new FormData(node);values.set('action',action);return new URLSearchParams(values).toString();},action);
}
async function rewrite(p,button,body,status){
 check(!rewrittenBodies.has(p),'one explicit original-body override per actual page');rewrittenBodies.set(p,body);
 try{await submit(p,button,status);}finally{rewrittenBodies.delete(p);}
}

async function loseCommittedCreate(c,p,button){
 const devtools=await c.newCDPSession(p);let lost=false,resolveLost;const sawLost=new Promise(resolve=>resolveLost=resolve);
 devtools.on('Fetch.requestPaused',async event=>{
  if(!lost&&event.request.method==='POST'&&event.responseStatusCode===303){lost=true;await devtools.send('Fetch.failRequest',{requestId:event.requestId,errorReason:'ConnectionReset'});resolveLost();}
  else await devtools.send('Fetch.continueRequest',{requestId:event.requestId});
 });
 await devtools.send('Fetch.enable',{patterns:[{urlPattern:'*vereinsverwaltung/*',requestStage:'Response'}]});
 try{const nav=p.waitForNavigation({waitUntil:'domcontentloaded'}).catch(()=>null);await button.click();await Promise.race([sawLost,new Promise((_,reject)=>setTimeout(()=>reject(new Error('Actual committed 303 response was not intercepted')),30000))]);await nav;check(lost,'actual server 303 after COMMIT was lost at the response stage');}
 finally{await devtools.send('Fetch.disable');await devtools.detach();}
}
async function errorsAndStale(p,origin,id){
 for(const kind of ['footer','array','csrf','source','field']){
  await p.goto(route(origin,{filing:id}),{waitUntil:'domcontentloaded'});const form=p.locator('#balance-local-main');await form.waitFor({state:'attached'});
  const data=new URLSearchParams(await formBody(form,'tax-filing-balance-save'));data.set('profile[supplier]','0 Own rejected Übernahme <&> 🙂');
  if(kind==='footer')data.delete('balance_local_complete');
  if(kind==='array'){data.delete('profile[supplier]');data.set('profile[supplier][0]','0 Own rejected Übernahme <&> 🙂');}
  if(kind==='csrf')data.set('csrf','own-invalid-csrf');
  if(kind==='source')data.set('balance_local_token','0'.repeat(64));
  if(kind==='field')data.delete('profile[company][city]');
  await rewrite(p,p.locator('button[value=tax-filing-balance-save]'),data.toString(),kind==='csrf'?403:kind==='source'?409:422);
  const raw=p.locator('[data-balance-local-raw]');await raw.waitFor({state:'attached'});const original=JSON.parse(await raw.textContent());
  check((kind==='array'?original.profile.supplier[0]:original.profile.supplier)==='0 Own rejected Übernahme <&> 🙂'&&!Object.hasOwn(original,'csrf'),'actual '+kind+' failure retains complete zero Unicode and arrays without CSRF');
 }
 await p.goto(route(origin,{filing:id}),{waitUntil:'domcontentloaded'});const stalePage=await p.context().newPage();
 try{
  await stalePage.goto(route(origin,{filing:id}),{waitUntil:'domcontentloaded'});const mine=stalePage.locator('[name="profile[supplier]"]');await expose(mine);await mine.fill('0 Own deliberate stale correction Übernahme');
  const current=p.locator('[name="profile[supplier]"]');await expose(current);await current.fill('Own actual independent later supplier');
  await submit(p,p.locator('button[value=tax-filing-balance-save]'));const newer=await saved(p,'save');
  await submit(stalePage,stalePage.locator('button[value=tax-filing-balance-save]'),409);
  await stalePage.locator('[data-balance-local-comparison]').waitFor({state:'attached'});
  check(await mine.inputValue()==='0 Own deliberate stale correction Übernahme','actual stale complete form retains my original change');
  await submit(stalePage,stalePage.locator('button[value=balance-local-rebase]'),200);
  check(await mine.inputValue()==='0 Own deliberate stale correction Übernahme','explicit rebase preserves my input and only updates the starting revision');
  await submit(stalePage,stalePage.locator('button[value=tax-filing-balance-save]'));const rebased=await saved(stalePage,'save');
  check(rebased.result.version===newer.result.version+1&&rebased.result.form_data.balance_preparation.profile.supplier==='0 Own deliberate stale correction Übernahme','a separate explicit save applies exactly my consciously rebased details');
 }finally{await stalePage.close();}
}
async function dimensions(p,js,label){
 const root=p.locator('[data-balance-local]').filter({has:p.locator('#balance-local-main')}).first();await root.waitFor();
 await root.locator('details').evaluateAll(nodes=>{for(const node of nodes)node.open=true;});
 if(js)await p.evaluate(()=>new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r))));
 for(const width of [320,390,820,1280])for(const size of [16,32]){
  await p.setViewportSize({width,height:1100});await p.evaluate(size=>document.documentElement.style.setProperty('font-size',size+'px','important'),size);
  const state=await root.evaluate(el=>({width:el.getBoundingClientRect().width,page:document.documentElement.scrollWidth,viewport:innerWidth,scroll:el.scrollWidth,client:el.clientWidth,text:[...el.querySelectorAll('h2,h3,h4,p,dt,dd,label,input:not([type=hidden]),select,textarea,button,a,strong,span,summary,pre,small,li,legend')].filter(n=>n.getClientRects().length).map(n=>({text:n.textContent.slice(0,60),size:parseFloat(getComputedStyle(n).fontSize)})),controls:[...el.querySelectorAll('summary,button,input:not([type=hidden]),select,textarea')].filter(n=>n.getClientRects().length).map(n=>({text:(n.textContent||n.name).slice(0,50),h:n.getBoundingClientRect().height,w:n.getBoundingClientRect().width}))}));
  if(state.page>state.viewport+2||state.scroll>state.client+2)fs.writeFileSync(artifact+'/geometry.json',JSON.stringify({label,width,size,state},null,2));
  check(state.page<=state.viewport+2&&state.scroll<=state.client+2,label+' fits '+width+'/'+size+' '+JSON.stringify(state));
  check(state.text.every(n=>n.size>=size),label+' relative font '+width+'/'+size+' '+JSON.stringify(state.text.filter(n=>n.size<size)));
  check(state.controls.every(n=>n.h>=43.5&&n.w<=state.width+2),label+' touch targets '+width+'/'+size+' '+JSON.stringify(state.controls.filter(n=>n.h<43.5||n.w>state.width+2)));fonts++;
  if(width===320&&size===32)await root.screenshot({path:artifact+'/main-'+label.replaceAll(/[^a-zA-Z0-9-]/g,'-')+'.png',animations:'disabled'});
 }
 await p.evaluate(()=>document.documentElement.style.removeProperty('font-size'));await p.setViewportSize({width:390,height:1100});
}
async function primary(){
 for(const app of ['front','direct'])for(const js of [true,false])for(const locale of ['de','en'])for(const role of ['admin','reader']){
  const origin=f.origins[app],{c,p}=await context(origin,js,locale,role);const reply=await p.goto(route(origin,{filing:f.data.id}),{waitUntil:'domcontentloaded'});check(reply.status()===200,'actual original primary opens '+app+'/'+js+'/'+locale+'/'+role);
  await p.locator('#balance-local-main').waitFor();if(locale==='en')check(await p.getByText('Club and sender',{exact:true}).count()===1,'actual English form labels');
  if(role==='reader')check(await p.locator('button[value=tax-filing-balance-save]').count()===0,'reader has no local write action');
  await dimensions(p,js,[app,js,locale,role].join('-'));views++;await c.close();
 }
}
try{
 if(process.env.DVM_BALANCE_FILING_PRIMARY_ONLY==='1'){await primary();console.log(JSON.stringify({primary:true,checks,views,fonts,business_posts:posts,external_requests:external,javascript_errors:errors.length}));await browser.close();process.exit(0);}
 if(process.env.DVM_BALANCE_FILING_SKIP_PRIMARY!=='1')await primary();
 for(const app of ['front','direct'])for(const js of [true,false])for(const locale of ['de','en']){
  const origin=f.origins[app],{c,p}=await context(origin,js,locale);
  let response=await p.goto(origin+'/vereinsverwaltung/?view=finance&tab=tax&balance=1&balance_year=2025',{waitUntil:'domcontentloaded'});check(response.status()===200,'actual closed original ledger opens');
  const create=p.locator('form:has(input[name=action][value=tax-filing-balance-create])');await create.waitFor();
  check(await create.locator('[name=balance_local_complete]').inputValue()==='1','actual create has its final completeness footer');
  await choose(create.locator('[name=balance_scope]'),'separate_business_ledger');const createBody=await formBody(create,'tax-filing-balance-create'),createKey=new URLSearchParams(createBody).get('balance_local_key');
  if(app==='front'&&js&&locale==='de'){await loseCommittedCreate(c,p,create.locator('button').last());await p.goto(route(origin,{balance_local_outcome:createKey}),{waitUntil:'domcontentloaded'});}
  else await submit(p,create.locator('button').last());
  const first=await saved(p,'create');const id=first.result.id;
  await p.goto(origin+'/vereinsverwaltung/?view=finance&tab=tax&balance=1&balance_year=2025',{waitUntil:'domcontentloaded'});
  await rewrite(p,p.locator('form:has(input[name=action][value=tax-filing-balance-create]) button').last(),createBody,303);
  const repeated=await saved(p,'create',false);check(repeated.key===first.key&&repeated.sha256===first.sha256&&JSON.stringify(repeated.result)===JSON.stringify(first.result),'actual identical complete creation replay reads the exact first draft');
  check(first.result.version===1&&first.result.state==='draft','actual create result is one local production draft');
  await p.goto(route(origin,{filing:id}),{waitUntil:'domcontentloaded'});
  const form=p.locator('#balance-local-main');await form.waitFor({state:'attached'});
  const fields={'profile[business_name]':'0 Own '+app+' '+js+' Übernahme <&> 🙂','profile[supplier]':'Own original supplier','profile[company][name]':'Own original invented company','profile[company][tax_number]':'5192050001276','profile[company][street]':'Own invented road','profile[company][house_number]':'1','profile[company][postal_code]':'11111','profile[company][city]':'Own invented town','profile[company][country]':'DE'};
  for(const [name,value]of Object.entries(fields)){const control=form.locator('[name='+JSON.stringify(name)+']');await expose(control);await control.fill(value);}
  await expose(form.locator('input[name="profile[tax_privileged]"][value=yes]'));await form.locator('input[name="profile[tax_privileged]"][value=yes]').check();
  await expose(form.locator('input[name="profile[company][legal_status]"][value="Other.KOER"]'));await form.locator('input[name="profile[company][legal_status]"][value="Other.KOER"]').check();
  const mapping={cash:'bs.ass.currAss.cashEquiv.bank',expense:'is.netIncome.regular.operatingTC.otherCost.administration',equity:'bs.eqLiab.equity.retainedEarnings',revenue:'is.netIncome.regular.operatingTC.grossTradingProfit.totalOutput.netSales.grossSales.taxExemptUStG19_1'};
  for(const [key,value]of Object.entries(mapping))await choose(form.locator('[name='+JSON.stringify('mapping['+key+']')+']'),value);
  for(const name of ['profile[scope_confirmed]','profile[asset_values_unchanged]','values_reviewed']){const control=form.locator('[name='+JSON.stringify(name)+']');await expose(control);await control.check();}
  await submit(p,p.locator('button[name=action][value=tax-filing-balance-generate]'),409);
  check(await form.locator('[name="profile[business_name]"]').inputValue()===fields['profile[business_name]'],'unsaved XML refusal retains every literal original business character');
  const byKeyboard=app==='direct'&&!js&&locale==='en';if(byKeyboard){await form.locator('[name=values_reviewed]').focus();await p.keyboard.press('Tab');}
  await submit(p,form.locator('button[name=action][value=tax-filing-balance-save]'),303,byKeyboard);const written=await saved(p,'save');
  check(written.result.form_data.balance_preparation.profile.business_name===fields['profile[business_name]'],'actual complete main save retains zero Unicode original');
  await p.goto(route(origin,{filing:id}),{waitUntil:'domcontentloaded'});await submit(p,p.locator('button[name=action][value=tax-filing-balance-generate]'));const xml=await saved(p,'generate');
  check(xml.result.xml_sha256&&xml.result.xml_data.includes('<?xml')&&xml.result.state==='draft'&&xml.result.validation.length===0,'actual real Python XML remains a local draft without native validation');
  const originalUrl=p.url(),download=await c.request.get(originalUrl+'&balance_local_xml=original');check(download.status()===200&&await download.text()===xml.result.xml_data,'original XML download returns every exact first byte');
  await p.goto(route(origin,{filing:id}),{waitUntil:'domcontentloaded'});check(await p.locator('[name=certificate_pin]').count()===0,'local XML does not invent certificate or send approval');
  await submit(p,p.locator('button[name=action][value=balance-local-details]'));await p.waitForURL(u=>u.searchParams.get('balance_local_details')==='1');await p.getByRole('link',{name:locale==='en'?'Back to details and XML preparation':'Zurück zu Angaben und XML-Vorbereitung',exact:true}).waitFor();await p.locator('#balance-local-main').waitFor({state:'detached'});check(new URL(p.url()).searchParams.get('balance_local_details')==='1'&&await p.locator('#balance-local-main').count()===0,'specialist actions open only after a complete unchanged main form');
  if(app==='front'&&js&&locale==='de')await errorsAndStale(p,origin,id);
  await p.goto(originalUrl,{waitUntil:'domcontentloaded'});await p.screenshot({path:artifact+'/balance-'+app+'-'+js+'-'+locale+'-outcome.png',fullPage:true});
  await c.close();
 }
 for(const role of ['general','foreign']){
  const origin=f.origins.front,{c,p}=await context(origin,true,'en',role);const target=accepted[0];
  const response=await p.goto(route(origin,{filing:f.data.id}),{waitUntil:'domcontentloaded'});
  check(response.status()===(role==='general'?403:404),'actual '+role+' original detail access is refused');
  const read=await p.goto(route(origin,{balance_local_outcome:target.key}),{waitUntil:'domcontentloaded'});
  check(read.status()===(role==='general'?403:200)&&await p.locator('[data-balance-local-saved]').count()===0,'actual '+role+' cannot read another club original through a receipt');
  await c.close();
 }
 check(external===0&&errors.length===0,'all actual local flows have zero external requests and JavaScript errors '+JSON.stringify(errors));
 const result={suite:'native-balance-filing-browser',checks,views,fonts,business_posts:posts,external_requests:external,javascript_errors:errors.length,accepted};
 fs.writeFileSync(f.root+'/balance-filing-browser-results.json',JSON.stringify(result,null,2)+'\n');console.log(JSON.stringify({...result,accepted:accepted.length}));
}catch(error){let n=0;for(const c of browser.contexts())for(const p of c.pages())try{fs.writeFileSync(artifact+'/failure-'+n+'.html',await p.content());await p.screenshot({path:artifact+'/failure-'+n+'.png',fullPage:true});n++;}catch{}throw error;}
finally{await browser.close();}
