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
 const p=await c.newPage();p.setDefaultTimeout(60000);p.setDefaultNavigationTimeout(90000);p.on('pageerror',e=>errors.push(e.message));
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
 await expose(button);await button.waitFor({state:'visible'});
 if(keyboard)check(await button.evaluate(node=>node===document.activeElement),'actual Tab reaches the complete save action');
 const [reply]=await Promise.all([
  p.waitForResponse(r=>r.request().method()==='POST'&&new URL(r.url()).pathname.startsWith('/vereinsverwaltung/'),{timeout:90000}),
  p.waitForNavigation({waitUntil:'domcontentloaded',timeout:90000}),
  keyboard?button.press('Enter'):button.click(),
 ]);
 console.log('Balance POST '+reply.status()+' expected '+status);if(reply.status()!==status&&! [302,303].includes(reply.status()))await fs.promises.writeFile(artifact+'/failed-post.html',await reply.text());
 check(reply.status()===status,'actual complete POST expected '+status+' got '+reply.status()+(reply.status()===status?'':' '+(await p.locator('body').innerText()).slice(-2000)));views++;
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
const taskTitle={revision:{de:'Abgabeart bearbeiten',en:'Edit submission type'},adjust:{de:'Steuerliche Wertänderung erfassen',en:'Record a tax value adjustment'}};
async function openTask(p,origin,id,operation,locale){
 const r=await p.goto(route(origin,{filing:id,balance_local_details:'1'}),{waitUntil:'domcontentloaded'});check(r.status()===200,'actual specialist selection opens');
 await p.locator('[data-balance-specialist-menu]').waitFor();check(await p.locator('[data-balance-specialist-menu]').count()===1&&await p.locator('form[data-balance-local-form]').count()===0,'selection offers tasks without editable source contexts');
 const article=p.locator('article').filter({has:p.getByRole('heading',{name:taskTitle[operation][locale],exact:true})}),link=article.locator('[data-balance-specialist-open]');await link.waitFor();
 await Promise.all([p.waitForNavigation({waitUntil:'domcontentloaded'}),link.click()]);
 const form=p.locator('form[data-balance-specialist-form]');await form.waitFor();await form.locator('[name=balance_local_complete]').waitFor({state:'attached'});check(await p.locator('form[data-balance-local-form]').count()===1,'exactly one original complete specialist form opens');views++;return form;
}
async function geometry(p,label,outcome=false){
 const root=outcome?p.locator('[data-balance-local-saved]'):p.locator('[data-tax-local][data-balance-local]').first();await root.waitFor();
 check(outcome?await root.count()===1:await root.locator('[data-balance-specialist-form],[data-balance-specialist-menu]').count()===1,label+' measures the actual specialist content, not its history card');
 if(!outcome)await root.locator('details').evaluateAll(nodes=>{for(const node of nodes)node.open=true;});
 const error=p.locator('[data-balance-local-error]');const targets=[root];if(await error.count()===1)targets.push(error);
 if(await error.count()===1){const layout=await error.evaluate(node=>{const style=getComputedStyle(node),space=node.clientWidth-parseFloat(style.paddingLeft)-parseFloat(style.paddingRight),strong=node.querySelector(':scope>strong');return{space,width:strong?.getBoundingClientRect().width??0};});check(layout.width>=layout.space-2,label+' alert uses a readable full-width line '+JSON.stringify(layout));}

 for(const width of[320,390,820,1280])for(const size of[16,32]){
  await p.setViewportSize({width,height:1100});await p.evaluate(size=>document.documentElement.style.setProperty('font-size',size+'px','important'),size);
  const sections=[];
  for(const target of targets)sections.push(await target.evaluate(el=>({page:document.documentElement.scrollWidth,viewport:innerWidth,width:el.getBoundingClientRect().width,scroll:el.scrollWidth,client:el.clientWidth,text:[...el.querySelectorAll('h2,h3,h4,p,dt,dd,label,input:not([type=hidden]),select,textarea,button,a,strong,span,summary,small,li,legend')].filter(n=>n.getClientRects().length).map(n=>({text:(n.textContent||n.name).slice(0,50),size:parseFloat(getComputedStyle(n).fontSize)})),controls:[...el.querySelectorAll('summary,button,input:not([type=hidden]),select,textarea,a.btn')].filter(n=>n.getClientRects().length).map(n=>({text:(n.textContent||n.name).slice(0,50),h:n.getBoundingClientRect().height,w:n.getBoundingClientRect().width}))})));
  check(sections.every(state=>state.page<=state.viewport+2&&state.scroll<=state.client+2),label+' actual form/error fits '+width+'/'+size+' '+JSON.stringify(sections));
  check(sections.every(state=>state.text.every(n=>n.size>=size)),label+' actual form/error has relative text '+width+'/'+size+' '+JSON.stringify(sections.map(s=>s.text.filter(n=>n.size<size))));
  check(sections.every(state=>state.controls.every(n=>n.h>=43.5&&n.w<=state.width+2)),label+' actual form/error retains 44px targets '+width+'/'+size+' '+JSON.stringify(sections.map(s=>s.controls.filter(n=>n.h<43.5||n.w>s.width+2))));fonts++;
  if(width===320&&size===32){if(targets.length>1)await p.screenshot({path:artifact+'/'+label+'.png',fullPage:true,animations:'disabled'});else await root.screenshot({path:artifact+'/'+label+'.png',animations:'disabled'});}
 }
 await p.evaluate(()=>document.documentElement.style.removeProperty('font-size'));await p.setViewportSize({width:390,height:1100});
}
async function originalPost(form,action){return formBody(form,action);}
async function specialistStale(p,origin,id,locale){
 let old=await openTask(p,origin,id,'revision',locale);const oldPage=p;
 const other=await p.context().newPage();try{
  const newer=await openTask(other,origin,id,'revision',locale);await newer.locator('[name=revision_reason]').fill('0 Own newer specialist source Übernahme');await newer.locator('[name=revision_confirmed]').check();
  await submit(other,newer.locator('button[value=tax-filing-balance-revision]'));const first=await saved(other,'revision');
  const reason='0 Own deliberately retained specialist reason <&> 🙂';await old.locator('[name=revision_reason]').fill(reason);await old.locator('[name=revision_confirmed]').check();
  await submit(oldPage,old.locator('button[value=tax-filing-balance-revision]'),409);check(await oldPage.locator('[name=revision_reason]').inputValue()===reason,'actual stale specialist submission keeps every original character');
  const rebase=oldPage.locator('button[value=balance-local-rebase]');await submit(oldPage,rebase,200);check(await oldPage.locator('[name=revision_reason]').inputValue()===reason,'explicit specialist rebase keeps my reason and saves nothing');
  await submit(oldPage,oldPage.locator('form[data-balance-specialist-form] button[value=tax-filing-balance-revision]'));const next=await saved(oldPage,'revision');check(next.result.version===first.result.version+1&&next.result.form_data.balance_preparation.filing_revision.reason===reason,'a separate explicit specialist save applies exactly the consciously rebased details');
 }finally{await other.close();}
}
try{
 for(const app of['front','direct'])for(const js of[true,false])for(const locale of['de','en']){
  const origin=f.origins[app],{c,p}=await context(origin,js,locale);const tag=[app,js,locale].join('-');
  await p.goto(origin+'/vereinsverwaltung/?view=finance&tab=tax&balance=1&balance_year=2025',{waitUntil:'domcontentloaded'});
  const create=p.locator('form:has(input[name=action][value=tax-filing-balance-create])');await choose(create.locator('[name=balance_scope]'),'separate_business_ledger');await submit(p,create.locator('button').last());const created=await saved(p,'create'),id=created.result.id;
  await p.goto(route(origin,{filing:id}),{waitUntil:'domcontentloaded'});let form=p.locator('#balance-local-main');await form.waitFor();
  for(const[name,value]of Object.entries({'profile[business_name]':'0 Own specialist '+tag+' Übernahme <&> 🙂','profile[supplier]':'Own original supplier','profile[company][name]':'Own original fictional club','profile[company][tax_number]':'5192050001276','profile[company][street]':'Own original road','profile[company][house_number]':'1','profile[company][postal_code]':'11111','profile[company][city]':'Own original town','profile[company][country]':'DE'})){const control=form.locator('[name='+JSON.stringify(name)+']');await expose(control);await control.fill(value);}
  for(const[name,value]of[['profile[tax_privileged]','yes'],['profile[company][legal_status]','Other.KOER']]){const control=form.locator('input[name='+JSON.stringify(name)+'][value='+JSON.stringify(value)+']');await expose(control);await control.check();}
  for(const[key,value]of Object.entries({cash:'bs.ass.currAss.cashEquiv.bank',expense:'is.netIncome.regular.operatingTC.otherCost.administration',equity:'bs.eqLiab.equity.retainedEarnings',revenue:'is.netIncome.regular.operatingTC.grossTradingProfit.totalOutput.netSales.grossSales.taxExemptUStG19_1'}))await choose(form.locator('[name='+JSON.stringify('mapping['+key+']')+']'),value);
  for(const name of['profile[scope_confirmed]','profile[asset_values_unchanged]','values_reviewed']){const control=form.locator('[name='+JSON.stringify(name)+']');await expose(control);await control.check();}
  await submit(p,form.locator('button[value=tax-filing-balance-save]'));await saved(p,'save');await p.goto(route(origin,{filing:id}),{waitUntil:'domcontentloaded'});await submit(p,p.locator('button[value=tax-filing-balance-generate]'));const generated=await saved(p,'generate'),generatedUrl=p.url();
  form=await openTask(p,origin,id,'revision',locale);await geometry(p,'revision-'+tag);
  await form.locator('[name=revision_reason]').fill('0 Own specialist dirty reason Übernahme <&> 🙂');
  await submit(p,form.locator('button[value=balance-local-specialist-back]'),409);check(await p.locator('[name=revision_reason]').inputValue()==='0 Own specialist dirty reason Übernahme <&> 🙂','dirty back keeps exact zero Unicode original');
  await geometry(p,'error-'+tag);
  await submit(p,p.locator('button[value=balance-local-specialist-discard]'),422);check(await p.locator('[name=revision_reason]').inputValue()==='0 Own specialist dirty reason Übernahme <&> 🙂','unconfirmed discard keeps exact original');
  const discard=p.locator('[name=balance_local_discard_confirmed]');await expose(discard);await discard.check();await submit(p,p.locator('button[value=balance-local-specialist-discard]'));await p.locator('[data-balance-specialist-menu]').waitFor();check(await p.locator('[data-balance-specialist-menu]').count()===1,'express discard returns to actual bounded menu');
  await geometry(p,'menu-'+tag);
  form=await openTask(p,origin,id,'revision',locale);const initial=await originalPost(form,'balance-local-specialist-back');await submit(p,form.locator('button[value=balance-local-specialist-back]'));await p.locator('[data-balance-specialist-menu]').waitFor();check(await p.locator('[data-balance-specialist-menu]').count()===1,'unchanged complete specialist navigation saves nothing');
  form=await openTask(p,origin,id,'revision',locale);const reason='0 Own accepted specialist revision '+tag+' Übernahme <&> 🙂';await form.locator('[name=revision_reason]').fill(reason);await form.locator('[name=revision_confirmed]').check();
  if(app==='direct'&&!js&&locale==='en'){await form.locator('[name=revision_confirmed]').focus();await p.keyboard.press('Tab');await submit(p,form.locator('button[value=tax-filing-balance-revision]'),303,true);}else await submit(p,form.locator('button[value=tax-filing-balance-revision]'));
  const revised=await saved(p,'revision');check(revised.result.form_data.balance_preparation.filing_revision.reason===reason,'actual specialist save preserves zero Unicode reason');
  form=await openTask(p,origin,id,'adjust',locale);await geometry(p,'adjust-'+tag);await form.locator('[name=adjust_reason]').fill('0 Own specialist value change '+tag+' Übernahme');await choose(form.locator('[name=adjust_debit]'),'expense');await choose(form.locator('[name=adjust_credit]'),'cash');await form.locator('[name=adjust_amount]').fill('0,01');await submit(p,form.locator('button[value=tax-filing-balance-adjust]'));const adjusted=await saved(p,'adjust');
  check(adjusted.result.state==='draft'&&adjusted.result.xml_data===null&&adjusted.result.version===revised.result.version+1,'actual adjustment clears current XML without inventing validation or submission');
  await geometry(p,'outcome-'+tag,true);
  const download=await c.request.get(generatedUrl+'&balance_local_xml=original');check(download.status()===200&&await download.text()===generated.result.xml_data,'first original XML stays exact after real specialist changes');
  if(app==='front'&&js&&locale==='de')await specialistStale(p,origin,id,locale);
  await c.close();
 }
 check(external===0&&errors.length===0,'all actual specialist flows have zero external requests and JavaScript errors '+JSON.stringify(errors));
 const result={suite:'native-balance-filing-specialist-browser',checks,views,fonts,business_posts:posts,external_requests:external,javascript_errors:errors.length,accepted};
 fs.writeFileSync(f.root+'/balance-filing-browser-results.json',JSON.stringify(result,null,2)+'\n');console.log(JSON.stringify({...result,accepted:accepted.length}));
}catch(error){let n=0;for(const c of browser.contexts())for(const p of c.pages())try{fs.writeFileSync(artifact+'/failure-'+n+'.html',await p.content());await p.screenshot({path:artifact+'/failure-'+n+'.png',fullPage:true});n++;}catch{}throw error;}
finally{await browser.close();}
