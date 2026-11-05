import {readFile,writeFile} from 'node:fs/promises';
import assert from 'node:assert/strict';

const started=Date.now(),f=JSON.parse(await readFile(process.env.DVM_PAGE_LIFECYCLE_FIXTURE,'utf8'));
for(const origin of Object.values(f.origins))assert.match(origin,/^http:\/\/127\.0\.0\.1:[0-9]+$/);
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE||'/usr/bin/chromium',args:['--no-sandbox']});
let checks=0,states=0,posts=0,external=0,activePage=null;const errors=[];
function check(okay,label){assert.ok(okay,label);checks++;}
function base(app){return f.origins[app]+(app==='integrated'?'/vereinsverwaltung/':'/');}
function pageURL(app,id){return base(app)+'?view=website&'+(app==='integrated'?'tab=pages&native_item=':'item=')+id;}
function wire(app,form){return app==='integrated'?{...form,action:'native-module'}:form;}
async function openContext(app,js=true,viewport={width:390,height:844},role='admin'){
 const c=await browser.newContext({javaScriptEnabled:js,viewport});
 await c.route('**/*',route=>{if(new URL(route.request().url()).origin!==f.origins[app]){external++;return route.abort();}return route.continue();});
 const enter=await c.request.get(f.origins[app]+'/own-test-enter?role='+role,{maxRedirects:0});assert.equal(enter.status(),303);
 c.on('page',page=>{page.on('pageerror',error=>errors.push(error.message));page.on('request',request=>{if(request.method()==='POST')posts++;});});return c;
}
async function observe(c,app,id){const r=await c.request.get(f.origins[app]+'/own-test-observe?job='+id,{headers:{'X-Own-Lifecycle-Proof':f.proof_secret}});assert.equal(r.status(),200);const v=await r.json();return {copies:Number(v.copies),public:Number(v.public_copies)};}
async function rejected(response,message){if(response.status()>=400&&response.status()<500)return true;let text=await response.text();try{text=JSON.stringify(JSON.parse(text));}catch{}return response.status()===200&&text.includes(message);}
async function layout(page,selector,label,font){
 await page.evaluate(font=>document.documentElement.style.fontSize=font+'px',font);
 const v=await page.locator(selector).evaluate(panel=>({fits:document.documentElement.scrollWidth<=innerWidth+1,height:panel.getBoundingClientRect().height,buttons:[...panel.querySelectorAll('button,summary')].filter(n=>n.getClientRects().length).map(n=>({label:n.textContent.trim(),height:n.getBoundingClientRect().height,width:n.getBoundingClientRect().width})),exception:/Fatal error:|SQLSTATE\[|Stack trace:/.test(document.body.innerText)}));
 check(v.fits,label+' fits viewport');check(!v.exception,label+' has no application exception');
 for(const button of v.buttons)check(button.height>=43&&button.width>=43,label+' touch target '+button.label);
 check(v.height<2400,label+' compact workspace remains bounded');states++;
}
try{
 for(const app of ['native','integrated'])for(const js of [true,false]){
  const mode=js?'js':'basic',flow=f.flows[app][mode],c=await openContext(app,js),page=await c.newPage();activePage=page;
  const sourceURL=pageURL(app,flow.source);
  const first=await page.goto(sourceURL,{waitUntil:'networkidle'});check(first.status()===200,app+' actual authenticated source editor');
  const panel=page.locator('.page-copy-panel');await panel.locator('summary').click();
  await panel.locator('[name="copy_title"]').fill('Own private copied '+app+' '+mode);await panel.locator('[name="copy_slug"]').fill(flow.copy_slug);await panel.locator('[name="copy_tree"]').check();await panel.locator('[name="copy_job"]').check();
  const csrf=await panel.locator('[name="csrf"]').inputValue();
  const invalid=await c.request.post(sourceURL,{headers:{Accept:'application/json'},form:wire(app,{operation:'page-copy',csrf:'wrong',id:flow.source,version:String(flow.source_version),copy_title:'Rejected original',copy_slug:flow.copy_slug+'-bad',copy_tree:'1',copy_job:'1'})});check(invalid.status()>=400&&invalid.status()<500,app+' actual copy POST refuses invalid CSRF');
  await panel.getByRole('button',{name:'Unveröffentlichten Entwurf anlegen',exact:true}).click();
  const jobPanel=page.locator('[data-tree-copy-job]');await jobPanel.waitFor();const id=await jobPanel.getAttribute('data-tree-copy-job');check(new URL(page.url()).searchParams.get('copy_job')===id,app+' redirect keeps exact copy job identity');
  check((await jobPanel.innerText()).includes('0 von 27'),app+' actual job captures complete source selection');check((await observe(c,app,id)).copies===0,app+' preparation initially creates no partial copied pages');
  for(const [width,height] of [[320,720],[390,844],[1024,768]])for(const font of [16,32]){await page.setViewportSize({width,height});await layout(page,'[data-tree-copy-job]',[app,mode,width,font,'copy'].join(' '),font);}
  await page.setViewportSize({width:390,height:844});await page.evaluate(()=>document.documentElement.style.fontSize='16px');
  await jobPanel.getByRole('button',{name:'Nächste 25 Seiten vorbereiten',exact:true}).click();await page.locator('[data-tree-copy-job]').filter({hasText:'25 von 27'}).waitFor();check((await observe(c,app,id)).copies===0,app+' 25 prepared items expose no partial tree');
  const resumeURL=page.url();await c.close();const resumed=await openContext(app,js),p=await resumed.newPage();activePage=p;await p.goto(resumeURL,{waitUntil:'networkidle'});check((await p.locator('[data-tree-copy-job]').innerText()).includes('25 von 27'),app+' new personally authenticated session resumes saved checkpoint');
  await p.getByRole('button',{name:'Nächste 25 Seiten vorbereiten',exact:true}).click();await p.locator('[data-tree-copy-job]').filter({hasText:'27 von 27'}).waitFor();check((await observe(resumed,app,id)).copies===0,app+' fully prepared selection remains private and uncreated');
  const complete=p.locator('form').filter({has:p.locator('input[value="tree-copy-complete"]')});
  const jobVersion=await complete.locator('[name="version"]').inputValue(),newCSRF=await complete.locator('[name="csrf"]').inputValue();
  const unconfirmed=await resumed.request.post(p.url(),{headers:{Accept:'application/json'},form:wire(app,{operation:'tree-copy-complete',csrf:newCSRF,copy_job:id,version:jobVersion})});check(await rejected(unconfirmed,'Vollständigen Kopierabschluss bestätigen'),app+' actual completion requires explicit full-tree confirmation');check((await observe(resumed,app,id)).copies===0,app+' rejected final confirmation inserts no pages');
  await complete.locator('[name="copy_confirm"]').check();await complete.getByRole('button',{name:'Vollständigen Teilbaum privat anlegen',exact:true}).click();await p.locator('[data-tree-copy-job]').filter({hasText:'Vollständig angelegt'}).waitFor();const copy=await observe(resumed,app,id);check(copy.copies===27&&copy.public===0,app+' all 27 copied pages exist together as private drafts '+JSON.stringify(copy));
  check(await p.getByRole('link',{name:'Kopierten Teilbaum öffnen',exact:true}).count()===1,app+' completed job has one direct usable next action');
  const copyURL=new URL(await p.getByRole('link',{name:'Kopierten Teilbaum öffnen',exact:true}).getAttribute('href'),p.url()).href;
  const forget=p.locator('form').filter({has:p.locator('input[value="tree-copy-forget"]')});await forget.locator('xpath=..').locator(':scope > summary').click();
  const forgetCSRF=await forget.locator('[name=csrf]').inputValue(),forgetVersion=await forget.locator('[name=version]').inputValue();
  const forgetBad=await resumed.request.post(p.url(),{headers:{Accept:'application/json'},form:wire(app,{operation:'tree-copy-forget',csrf:'wrong',copy_job:id,version:forgetVersion,copy_forget_confirm:'1'})});check(forgetBad.status()>=400&&forgetBad.status()<500,app+' actual metadata cleanup rejects invalid CSRF');
  const forgetMissing=await resumed.request.post(p.url(),{headers:{Accept:'application/json'},form:wire(app,{operation:'tree-copy-forget',csrf:forgetCSRF,copy_job:id,version:forgetVersion})});check(await rejected(forgetMissing,'ausdrücklich zur Entfernung bestätigen'),app+' actual metadata cleanup requires explicit terminal confirmation');
  await forget.locator('[name=copy_forget_confirm]').check();await forget.getByRole('button',{name:'Auftragsdaten entfernen',exact:true}).click();await p.waitForURL(url=>!url.searchParams.has('copy_job'));await p.locator('.tree-copy-jobs').waitFor();check(!new URL(p.url()).searchParams.has('copy_job')&&await p.locator('[data-tree-copy-job="'+id+'"]').count()===0&&await p.locator('.tree-copy-jobs a[href*="'+id+'"]').count()===0,app+' removed closed job disappears from actual recent jobs');
  await p.goto(copyURL,{waitUntil:'networkidle'});check(await p.locator('input[name=operation][value=page-save]').count()===1&&(await p.locator('body').innerText()).includes('noch nicht veröffentlicht'),app+' removing only job metadata preserves the private copied original');
  await p.goto(pageURL(app,flow.purge),{waitUntil:'networkidle'});const trash=p.locator('.page-trash-panel');await trash.waitFor();check(!await trash.locator('.page-purge').evaluate(n=>n.open),app+' destructive removal is initially collapsed');
  for(const [width,height] of [[320,720],[390,844],[1024,768]])for(const font of [16,32]){await p.setViewportSize({width,height});await layout(p,'.page-trash-panel',[app,mode,width,font,'trash'].join(' '),font);}
  await p.setViewportSize({width:390,height:844});await p.evaluate(()=>document.documentElement.style.fontSize='16px');await trash.locator('.page-purge summary').click();
  const purgeForm=trash.locator('.page-purge form'),purgeCSRF=await purgeForm.locator('[name="csrf"]').inputValue();
  const purgeInvalid=await resumed.request.post(p.url(),{headers:{Accept:'application/json'},form:wire(app,{operation:'page-purge',csrf:'wrong',id:flow.purge,version:String(flow.purge_version),purge_confirm:'1'})});check(purgeInvalid.status()>=400&&purgeInvalid.status()<500,app+' actual irreversible POST rejects invalid CSRF');
  const purgeMissing=await resumed.request.post(p.url(),{headers:{Accept:'application/json'},form:wire(app,{operation:'page-purge',csrf:purgeCSRF,id:flow.purge,version:String(flow.purge_version)})});check(await rejected(purgeMissing,'Endgültiges Löschen ausdrücklich'),app+' actual irreversible POST requires explicit checkbox');
  await purgeForm.locator('[name="purge_confirm"]').check();await purgeForm.getByRole('button',{name:'Seite endgültig löschen',exact:true}).click();await p.locator('.page-trash-list').waitFor();check(new URL(p.url()).searchParams.get('section')==='trash',app+' successful purge returns to bounded trash workspace');check(await p.locator('[data-trash-row="'+flow.purge+'"]').count()===0,app+' removed original is absent from actual trash listing');
  await resumed.close();
 }
 check(errors.length===0,'actual lifecycle browsers have no JavaScript exception');check(external===0,'actual lifecycle browsers make no external requests');
 const result={suite:'native-page-lifecycle-browser',applications:2,flows:4,states,checks,protected_http_posts:posts,external_requests:external,javascript_errors:errors,duration_ms:Date.now()-started,live_transport:false};await writeFile(f.result,JSON.stringify(result,null,2)+'\n');console.log(JSON.stringify(result));
}catch(error){if(activePage&&!activePage.isClosed())console.error('OWN_LIFECYCLE_FAILURE',JSON.stringify({url:activePage.url(),visible_text:(await activePage.locator('body').innerText()).slice(0,6000),javascript_errors:errors}));throw error;}finally{await browser.close();}
