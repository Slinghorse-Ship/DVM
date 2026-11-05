import fs from 'node:fs';
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {createHash} from 'node:crypto';
const f=JSON.parse(fs.readFileSync(0,'utf8'));
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE);
const browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE,headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
let checks=0,views=0,fonts=0,posts=0,external=0,client=0,responseSerial=0;
const errors=[],accepted=[],check=(value,label)=>{assert(value,label);checks++;};
const artifact=process.env.DVM_BALANCE_FILING_ARTIFACT_DIR||f.root;
fs.mkdirSync(artifact,{recursive:true,mode:0o700});
function route(origin,extra={}){return origin+'/vereinsverwaltung/?'+new URLSearchParams({view:'finance',tab:'tax',tax_filings:'1',tax_year:'2025',...extra});}
async function context(origin,js=true,locale='de',role='own_admin'){
 console.log('Balance filing profile '+origin+' '+js+' '+locale+' '+role);
 const c=await browser.newContext({viewport:{width:390,height:1100},hasTouch:true,javaScriptEnabled:js,serviceWorkers:'block',extraHTTPHeaders:{'X-Owned-Finance-Help-Client':String(++client)}});
 await c.route('**/*',r=>{
  const request=r.request(),url=new URL(request.url());if(url.origin!==origin){external++;return r.abort();}
  return r.continue();
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
 const responseMarker='special-source-response-'+(++responseSerial);await p.evaluate(marker=>document.documentElement.setAttribute('data-owned-response',marker),responseMarker);
 await expose(button);await button.waitFor({state:'visible'});
 if(keyboard)check(await button.evaluate(node=>node===document.activeElement),'actual Tab reaches the complete save action');
 const [reply]=await Promise.all([
  p.waitForResponse(r=>r.request().method()==='POST'&&new URL(r.url()).pathname.startsWith('/vereinsverwaltung/'),{timeout:90000}),
  p.waitForNavigation({waitUntil:'domcontentloaded',timeout:90000}),
  keyboard?button.press('Enter'):button.click(),
 ]);
 await reply.finished();await p.waitForFunction(marker=>document.documentElement.getAttribute('data-owned-response')!==marker,responseMarker,{polling:25,timeout:90000});await p.waitForLoadState('domcontentloaded');
 console.log('Balance POST '+reply.status()+' expected '+status);if(reply.status()!==status&&! [302,303].includes(reply.status()))await fs.promises.writeFile(artifact+'/failed-post.html',await reply.text());
 check(reply.status()===status,'actual complete POST expected '+status+' got '+reply.status()+(reply.status()===status?'':' '+(await p.locator('body').innerText()).slice(-2000)));views++;
}
async function saved(p,operation,record=true){
 const node=p.locator('[data-balance-local-saved]');await node.waitFor();const key=await node.getAttribute('data-balance-local-key'),sha256=(await p.locator('[data-balance-local-sha]').textContent()).trim();
 const result=JSON.parse(await p.locator('[data-balance-local-original]').textContent());if(record)accepted.push({key,sha256,operation});return {key,sha256,result};
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
const flags=['fiscal_confirmed','journal_confirmed'];
const source=f.data.own,documentMutations=[];
let electionRevision=source.election_revision;
async function openOwn(p,origin,locale){
 const response=await p.goto(route(origin,{filing:source.id,balance_local_details:'1'}),{waitUntil:'domcontentloaded'});check(response.status()===200,'actual original development evidence task menu opens');await p.locator('[data-balance-specialist-menu]').waitFor();
 const title=locale==='en'?'Edit development evidence':'Entwicklungsnachweis bearbeiten',link=p.locator('article').filter({has:p.getByRole('heading',{name:title,exact:true})}).locator('[data-balance-specialist-open]');await link.waitFor();await Promise.all([p.waitForNavigation({waitUntil:'domcontentloaded'}),link.click()]);const form=p.locator('form[data-balance-specialist-form]');await form.waitFor();await form.locator('[name=balance_local_complete]').waitFor({state:'attached'});check(await p.locator('form[data-balance-local-form]').count()===1,'one actual complete original development evidence form opens');views++;return form;
}
async function originals(p,origin){
 const root=p.locator('[data-balance-own-original]');await root.waitFor();check(await root.count()===1,'saved original development evidence presentation remains readable');await root.locator('details').evaluateAll(nodes=>nodes.forEach(n=>n.open=true));
 const originals=await root.locator('article[data-balance-own-file]').evaluateAll(nodes=>nodes.map(n=>({href:n.querySelector('a').getAttribute('href'),sha:n.querySelector('code').textContent.trim()})));check(originals.length===6,'all six bound original PDF revisions are explicit');const text=await root.textContent();for(const amount of ['100,00','0,00','-100,00'])check(text.includes(amount),'original independent development amount remains visible '+amount);
 for(const row of originals){const url=new URL(row.href,origin);check(url.origin===origin&&/^[1-9][0-9]*$/.test(url.searchParams.get('revision')),'original file link binds its own explicit revision');const response=await p.context().request.get(url.href);check(response.status()===200&&createHash('sha256').update(await response.body()).digest('hex')===row.sha,'actual original PDF bytes equal the bound first checksum');}
 return originals;
}
function replaceElection(){
 const [php,...args]=source.php_command;const stdout=execFileSync(php,[...args,source.worker],{input:JSON.stringify({document_id:source.source.fiscal_document_id,expected_revision:electionRevision}),encoding:'utf8',timeout:120000});const proof=JSON.parse(stdout);
 check(proof.old_revision===electionRevision&&proof.new_revision===electionRevision+1&&proof.all_other_whole_originals_exact===true,'independent real CLI document service replaces only its current original PDF');electionRevision=proof.new_revision;documentMutations.push(proof);return proof;
}
async function originalXml(c,origin){
 const response=await c.request.get(route(origin,{balance_local_outcome:source.first_xml_key,balance_local_xml:'original'}));check(response.status()===200,'first original generated XML remains authorized and readable');const bytes=await response.body();check(createHash('sha256').update(bytes).digest('hex')===source.first_xml_sha256,'all first generated XML bytes remain exact after later original-source changes');return bytes;
}
async function completeAndGenerate(p,origin){
 await p.goto(route(origin,{filing:source.id}),{waitUntil:'domcontentloaded'});const main=p.locator('#balance-local-main');await main.waitFor();const assetReviewed=main.locator('[name="profile[asset_tax_values_reviewed]"]');check(await assetReviewed.count()===1&&await main.locator('[name="profile[asset_values_unchanged]"]').count()===0,'actual main form offers separate book and tax review for an original development source');await expose(assetReviewed);await assetReviewed.check();const reviewed=main.locator('[name=values_reviewed]');await expose(reviewed);await reviewed.check();await submit(p,main.locator('button[value=tax-filing-balance-save]'));const complete=await saved(p,'save');
 check(complete.result.form_data.balance_preparation.review.complete===true,'separate complete review is required after original-source repair');await p.goto(route(origin,{filing:source.id}),{waitUntil:'domcontentloaded'});await submit(p,p.locator('button[value=tax-filing-balance-generate]'));const generated=await saved(p,'generate');check(generated.result.xml_data?.startsWith('<?xml')&&generated.result.xml_sha256===createHash('sha256').update(generated.result.xml_data).digest('hex'),'actual local Python generates the current source-bound XML');check(generated.result.state==='draft'&&Array.isArray(generated.result.validation)&&generated.result.validation.length===0&&generated.result.runtime_sha256===null,'local XML generation grants no native validation or transmission approval');
}
try{
 for(const [app,origin]of Object.entries(f.origins))for(const js of[true,false])for(const locale of['de','en']){
  const tag=app+'-'+js+'-'+locale,{c,p}=await context(origin,js,locale);try{
   let form=await openOwn(p,origin,locale);for(const key of flags){const checkbox=form.locator('[name='+JSON.stringify('own_review['+key+']')+'][type=checkbox]');await expose(checkbox);await checkbox.check();}
   const beforeOriginals=await originals(p,origin);const replacement=replaceElection();const fresh=await c.newPage();fresh.on('pageerror',e=>errors.push(e.message));try{const current=await openOwn(fresh,origin,locale);check(await current.locator('input[type=checkbox][name^="own_review["]:checked').count()===0,'fresh actual repair form requires new explicit confirmations');check((await current.locator('select[name="own_review[fiscal_document_id]"] option:checked').textContent()).includes(String(electionRevision)),'current original selection visibly identifies the newer PDF revision');await originals(fresh,origin);await geometry(fresh,'repair-'+tag);}finally{await fresh.close();}
   await submit(p,form.locator('button[value=tax-filing-balance-own-intangible]'),409);form=p.locator('form[data-balance-specialist-form]');await form.waitFor({state:'attached'});check(await form.count()===1,'actual stale original PDF refusal keeps the original-review repair form');for(const key of flags)check(await form.locator('[name='+JSON.stringify('own_review['+key+']')+'][type=checkbox]').isChecked(),'stale original source retains my explicit confirmation '+key);const preserved=await originals(p,origin);check(JSON.stringify(preserved)===JSON.stringify(beforeOriginals),'later PDF leaves all bound original file links and hashes exact');await geometry(p,'conflict-'+tag);
   await submit(p,p.locator('button[value=balance-local-rebase]'),200);form=p.locator('form[data-balance-specialist-form]');check(await form.locator('input[type=checkbox][name^="own_review["]:checked').count()===2,'explicit source comparison preserves both original confirmations');
   const first=form.locator('[name="own_review[journal_confirmed]"][type=checkbox]');await expose(first);await first.uncheck();await submit(p,form.locator('button[value=tax-filing-balance-own-intangible]'),422);form=p.locator('form[data-balance-specialist-form]');check(!await form.locator('[name="own_review[journal_confirmed]"][type=checkbox]').isChecked(),'an actually unchecked confirmation remains unchecked after server refusal');await geometry(p,'unchecked-'+tag);
   await form.locator('[name="own_review[journal_confirmed]"][type=checkbox]').check();if(app==='direct'&&!js&&locale==='en'){await form.locator('[name="own_review[journal_confirmed]"][type=checkbox]').focus();await p.keyboard.press('Tab');await submit(p,form.locator('button[value=tax-filing-balance-own-intangible]'),303,true);}else await submit(p,form.locator('button[value=tax-filing-balance-own-intangible]'));const reviewed=await saved(p,'own-intangible'),binding=reviewed.result.form_data.balance_preparation.review.own_intangible_sources[source.item];
   check(binding.journal.fiscal_source.document.revision===electionRevision&&binding.journal.fiscal_source.document.sha256===replacement.file.sha256&&reviewed.result.xml_data===null&&!reviewed.result.form_data.balance_preparation.review.complete,'actual separate source save binds the deliberately reviewed new PDF and invalidates current XML and whole review');
   form=await openOwn(p,origin,locale);const currentOriginals=await originals(p,origin);check(currentOriginals.some(row=>row.sha===replacement.file.sha256),'saved current original presentation links its exact newly reviewed file');await geometry(p,'current-'+tag);await completeAndGenerate(p,origin);await originalXml(c,origin);
  }catch(error){fs.writeFileSync(artifact+'/profile-failure-'+tag+'.html',await p.content());await p.screenshot({path:artifact+'/profile-failure-'+tag+'.png',fullPage:true});console.log('Original development source failed '+p.url()+' '+(await p.locator('body').innerText()).slice(-3500));throw error;}finally{await c.close();}
 }
 check(external===0&&errors.length===0,'all actual source-repair profiles have zero external requests and JavaScript errors '+JSON.stringify(errors));const result={suite:'native-balance-filing-own-browser',checks,views,fonts,business_posts:posts,external_requests:external,javascript_errors:errors.length,accepted,document_mutations:documentMutations};fs.writeFileSync(f.root+'/balance-filing-browser-results.json',JSON.stringify(result,null,2)+'\n');console.log(JSON.stringify({...result,accepted:accepted.length,document_mutations:documentMutations.length}));
}catch(error){let n=0;for(const c of browser.contexts())for(const p of c.pages())try{fs.writeFileSync(artifact+'/failure-'+n+'.html',await p.content());await p.screenshot({path:artifact+'/failure-'+n+'.png',fullPage:true});n++;}catch{}throw error;}
finally{await browser.close();}
