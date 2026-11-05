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
async function context(origin,js=true,locale='de',role='reserve_admin'){
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
const titles={intake:{de:'Übernommene Rücklage als Quellenentwurf erfassen',en:'Record a transferred reserve as an evidence draft'},withdraw:{de:'Rücklagen-Quellenentwurf zurücknehmen',en:'Withdraw a reserve evidence draft'}};
async function openReserve(p,origin,operation,locale){
 const response=await p.goto(route(origin,{filing:f.data.reserve.id,balance_local_details:'1'}),{waitUntil:'domcontentloaded'});check(response.status()===200,'actual original source menu opens');await p.locator('[data-balance-specialist-menu]').waitFor();
 check(await p.locator('form[data-balance-local-form]').count()===0,'source menu has no competing editable forms');
 let link=p.locator('article').filter({has:p.getByRole('heading',{name:titles[operation][locale],exact:true})}).locator('[data-balance-specialist-open]');
 for(let page=1;!await link.count()&&page<10;page++){const next=p.getByRole('link',{name:locale==='en'?'Next page':'Nächste Seite',exact:true});check(await next.count()===1,'actual bounded source menu has a next page');await Promise.all([p.waitForNavigation({waitUntil:'domcontentloaded'}),next.click()]);}
 await link.waitFor();await Promise.all([p.waitForNavigation({waitUntil:'domcontentloaded'}),link.click()]);const form=p.locator('form[data-balance-specialist-form]');await form.waitFor();await form.locator('[name=balance_local_complete]').waitFor({state:'attached'});check(await p.locator('form[data-balance-local-form]').count()===1,'one complete actual reserve source form opens');views++;return form;
}
async function inspectOriginal(p,origin,expected){
 const root=p.locator('[data-reserve-source-original]').first();await root.waitFor();check((await root.innerText()).includes(expected.original_taxpayer),'actual saved original retains taxpayer zero and Unicode');
 await root.locator('details').evaluateAll(nodes=>nodes.forEach(node=>node.open=true));
 const links=await root.locator('a').evaluateAll(nodes=>nodes.map(n=>n.getAttribute('href')));check(links.length===4,'actual original shows all four bound document revisions');
 for(const link of links){const url=new URL(link,origin);check(url.origin===origin&&['1','2'].includes(url.searchParams.get('revision')),'original download binds an explicit revision');const response=await p.context().request.get(url.href);check(response.status()===200&&(await response.body()).subarray(0,5).toString()==='%PDF-','actual authorized original download returns PDF bytes');}
}
try{
 for(const [app,origin]of Object.entries(f.origins))for(const js of[true,false])for(const locale of['de','en']){
  const tag=app+'-'+js+'-'+locale;const {c,p}=await context(origin,js,locale);let form=await openReserve(p,origin,'intake',locale);
  const source={...f.data.reserve.source,original_taxpayer:'0 Own accepted source '+tag+' Übernahme <&> 🙂'};
  for(const[key,value]of Object.entries(source)){
   const control=form.locator('[name='+JSON.stringify('financial_reserve['+key+']')+']');await expose(control);
   if(await control.getAttribute('type')==='checkbox')await control.check();else if(await control.evaluate(n=>n.tagName)==='SELECT')await choose(control,value);else await control.fill(value);
  }
  await geometry(p,'source-form-'+tag);
  await submit(p,form.locator('button[value=balance-local-specialist-back]'),409);check(await p.locator('[name="financial_reserve[original_taxpayer]"]').inputValue()===source.original_taxpayer&&await p.locator('[name="financial_reserve[sale_cost]"]').inputValue()==='0','dirty source return retains original zero Unicode and monetary input');
  await submit(p,p.locator('form[data-balance-specialist-form] button[value=tax-filing-balance-financial-reserve]'));const intake=await saved(p,'financial-reserve'),firstUrl=p.url();
  const stored=intake.result.form_data.balance_preparation.financial_reserve_intake;
  check(stored.original_taxpayer===source.original_taxpayer&&stored.original.sale_cost_cents===0&&stored.calculation.tax_basis_cents===13000&&stored.calculation.reserve_closing_cents===6000&&stored.activation_ready===false&&stored.native_xml_qualified===false&&intake.result.xml_data===null,'actual complete source intake preserves values and grants no XML qualification');
  form=await openReserve(p,origin,'withdraw',locale);await inspectOriginal(p,origin,source);await geometry(p,'source-original-'+tag);
  const reason='0 Own deliberate source withdrawal '+tag+' Übernahme <&> 🙂';await form.locator('[name=withdrawal_reason]').fill(reason);await submit(p,form.locator('button[value=tax-filing-balance-financial-reserve-withdraw]'));const withdrawn=await saved(p,'financial-reserve-withdraw');
  const preparation=withdrawn.result.form_data.balance_preparation,history=preparation.financial_reserve_intake_history.at(-1);
  check(!Object.hasOwn(preparation,'financial_reserve_intake')&&history.action==='withdrawn'&&history.reason===reason&&JSON.stringify(history.source)===JSON.stringify(stored),'actual source withdrawal retains every first original and the exact reason');
  form=await openReserve(p,origin,'intake',locale);const historyRoot=p.locator('[data-reserve-source-history]');await historyRoot.waitFor();await historyRoot.evaluate(node=>node.open=true);await historyRoot.locator('details').first().evaluate(node=>node.open=true);
  check((await historyRoot.innerText()).includes(reason)&&(await historyRoot.innerText()).includes(source.original_taxpayer),'actual history keeps saved source and withdrawal reason readable');await geometry(p,'source-history-'+tag);
  await p.goto(firstUrl,{waitUntil:'domcontentloaded'});const first=await saved(p,'financial-reserve',false);check(JSON.stringify(first)===JSON.stringify(intake),'first accepted source receipt remains exact after later withdrawal');
  await c.close();
 }
 check(external===0&&errors.length===0,'all actual source flows have zero external requests and JavaScript errors '+JSON.stringify(errors));
 const result={suite:'native-balance-filing-reserve-browser',checks,views,fonts,business_posts:posts,external_requests:external,javascript_errors:errors.length,accepted};fs.writeFileSync(f.root+'/balance-filing-browser-results.json',JSON.stringify(result,null,2)+'\n');console.log(JSON.stringify({...result,accepted:accepted.length}));
}catch(error){let n=0;for(const c of browser.contexts())for(const p of c.pages())try{fs.writeFileSync(artifact+'/failure-'+n+'.html',await p.content());await p.screenshot({path:artifact+'/failure-'+n+'.png',fullPage:true});n++;}catch{}throw error;}
finally{await browser.close();}
