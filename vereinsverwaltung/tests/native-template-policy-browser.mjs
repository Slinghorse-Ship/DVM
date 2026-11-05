import {readFile,writeFile} from 'node:fs/promises';
import assert from 'node:assert/strict';
const start=Date.now(),f=JSON.parse(await readFile(process.env.DVM_TEMPLATE_POLICY_FIXTURE,'utf8'));
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE||'/usr/bin/chromium',args:['--no-sandbox']});
let checks=0,states=0,posts=0,external=0,active;const errors=[];
const check=(ok,label)=>{assert.ok(ok,label);checks++;};
const base=app=>f.origins[app]+(app==='integrated'?'/vereinsverwaltung/':'/');
const pageURL=(app,id)=>base(app)+'?view=website&'+(app==='integrated'?'tab=pages&native_item=':'item=')+id;
const modelURL=app=>base(app)+(app==='integrated'?'?view=website&tab=technical':'?view=model');
const wire=(app,post)=>app==='integrated'?{...post,action:'native-module'}:post;
const rejected=async response=>response.status()>=400&&response.status()<500||response.status()===200&&/Berechtigung|inzwischen geändert/.test(await response.text());
async function context(app,js,role='admin'){
 const c=await browser.newContext({javaScriptEnabled:js,viewport:{width:390,height:844}});
 await c.route('**/*',r=>new URL(r.request().url()).origin===f.origins[app]?r.continue():(external++,r.abort()));
 const entry=await c.request.get(f.origins[app]+'/own-test-enter?role='+role,{maxRedirects:0});check(entry.status()===303,app+' actual personal '+role+' authentication');
 c.on('page',p=>{p.on('pageerror',e=>errors.push(e.message));p.on('request',r=>{if(r.method()==='POST')posts++;});});return c;
}
async function publicFiles(c,flow,status,label){
 for(const path of ['/site/'+flow.slug,'/media/'+flow.image+'/medium','/website-document/'+flow.document]){const r=await c.request.get(f.origins.integrated+path);check(r.status()===status,label+' '+path+' '+r.status());if(status===404)check(!(await r.text()).includes('Own published original exact bytes'),label+' no stale original bytes');}
}
async function layout(page,locator,label){
 for(const width of [320,390,1024])for(const font of [16,32]){
  await page.setViewportSize({width,height:844});await page.evaluate(n=>document.documentElement.style.fontSize=n+'px',font);
  const v=await locator.evaluate(n=>({fits:document.documentElement.scrollWidth<=innerWidth+1,height:n.getBoundingClientRect().height,targets:[...n.querySelectorAll('button,summary,input:not([type=hidden]):not([type=checkbox]),select')].filter(e=>e.getClientRects().length).map(e=>({w:e.getBoundingClientRect().width,h:e.getBoundingClientRect().height})),exception:/SQLSTATE\[|Fatal error:|Stack trace:/.test(document.body.innerText)}));
  if(!v.fits)console.error('OWN_POLICY_OVERFLOW',JSON.stringify(await page.evaluate(()=>({width:innerWidth,scroll:document.documentElement.scrollWidth,nodes:[...document.querySelectorAll('body *')].map(n=>({tag:n.tagName,cl:n.className,right:n.getBoundingClientRect().right,width:n.getBoundingClientRect().width,scroll:n.scrollWidth,client:n.clientWidth,overflow:getComputedStyle(n).overflowX,text:n.tagName==='SELECT'?n.name:n.textContent.trim().slice(0,45)})).filter(n=>n.width>0&&(n.right>innerWidth+1||n.scroll>n.client+1)).slice(0,35)}))));check(v.fits,label+' viewport '+width+'/'+font);check(!v.exception,label+' runtime '+width+'/'+font);check(v.height<2400,label+' bounded panel '+width+'/'+font+' '+v.height);for(const t of v.targets)check(t.h>=43&&t.w>=43,label+' touch '+width+'/'+font+' '+JSON.stringify(t));states++;
 }
 await page.setViewportSize({width:390,height:844});await page.evaluate(()=>document.documentElement.style.fontSize='16px');
}
try{
 for(const app of ['native','integrated'])for(const js of [true,false]){
  const mode=js?'js':'basic',flow=f.flows[app][mode],c=await context(app,js),p=await c.newPage();active=p;
  await publicFiles(c,flow,200,app+' original current public');
  const initial=await c.request.get(f.origins.integrated+'/site/'+flow.slug);check((await initial.text()).includes('Own published original exact bytes'),app+' original rendered before cache warm');
  const response=await p.goto(modelURL(app)+'&model_kind=template&model_key='+encodeURIComponent(flow.template),{waitUntil:'networkidle'});check(response.status()===200,app+' actual model management GET');
  const model=p.locator('form').filter({has:p.locator('input[name=operation][value=model-template]')}).filter({has:p.locator('input[name=key][value="'+flow.template+'"]')});
  check(await model.count()===1,app+' exact existing template form');
  const rules=model.locator('.template-settings');await rules.locator(':scope > summary').click();await rules.locator('[name=policy_enabled]').check();
  const family=rules.locator('.template-family-settings');await family.locator(':scope > summary').click();await family.locator('[name=policy_children_mode]').selectOption('none');await family.locator(':scope > summary').click();const presentation=rules.locator('.template-presentation-settings');await presentation.locator(':scope > summary').click();await presentation.locator('[name=policy_theme]').selectOption('natur');await presentation.locator('[name=policy_cache]').selectOption('off');await presentation.locator(':scope > summary').click();
  await layout(p,rules,app+' '+mode+' template rules');
  for(const [selector,label] of [['.template-family-settings','family'],['.template-access-settings','access'],['.template-presentation-settings','presentation']]){const group=rules.locator(selector);await group.locator(':scope > summary').click();await layout(p,group,app+' '+mode+' '+label+' rules');await group.locator(':scope > summary').click();}
  const contexts=model.locator('.template-field-contexts');await contexts.locator(':scope > summary').click();await contexts.locator('details > summary').click();
  const n=suffix=>'[name="template_contexts[policy_browser_field]['+suffix+']"]';
  await contexts.locator(n('enabled')).check();await contexts.locator(n('label_mode')).selectOption('custom');await contexts.locator(n('label')).fill('Kurzer Originaltitel');await contexts.locator(n('required')).selectOption('0');await contexts.locator(n('min_mode')).selectOption('custom');await contexts.locator(n('min')).fill('3');await contexts.locator(n('max_mode')).selectOption('custom');await contexts.locator(n('max')).fill('5');await contexts.locator(n('display')).selectOption('visible');
  const csrf=await model.locator('[name=csrf]').inputValue(),version=await model.locator('[name=version]').inputValue();
  const bad=await c.request.post(modelURL(app),{headers:{Accept:'application/json'},form:wire(app,{operation:'model-template',csrf:'wrong',version,key:flow.template,label:'Rejected original',renderer:'standard'})});check(await rejected(bad),app+' actual model POST rejects invalid CSRF');
  await model.getByRole('button',{name:'Vorlagenänderung speichern',exact:true}).click();await p.waitForFunction(({key,version})=>Number([...document.querySelectorAll('form')].find(n=>n.querySelector('input[name=key]')?.value===key)?.querySelector('[name=version]')?.value)>Number(version),{key:flow.template,version});check(await model.locator('[name=policy_theme]').inputValue()==='natur'&&await model.locator('[name=policy_enabled]').isChecked(),app+' real model POST persists chosen template rules');
  const changed=await c.request.get(f.origins.integrated+'/site/'+flow.slug);const changedText=await changed.text();check(changed.status()===200&&changedText.includes('design-natur')&&changedText.includes('Own published original exact bytes'),app+' template theme changes live without rewriting published content '+JSON.stringify({status:changed.status(),theme:/design-natur/.test(changedText),original:changedText.includes('Own published original exact bytes')}));
  await p.goto(pageURL(app,flow.id),{waitUntil:'networkidle'});
  const form=()=>p.locator('form').filter({has:p.locator('input[name=operation][value=page-save]')});
  check(await form().getByLabel('Kurzer Originaltitel',{exact:true}).count()===1,app+' contextual label reaches actual page form');await form().locator('[name="custom_fields[policy_browser_field]"]').fill('abc');
  const save=async()=>{const previous=Number(await form().locator('[name=version]').inputValue());await form().getByRole('button',{name:'Entwurf speichern',exact:true}).click();await p.waitForFunction(v=>Number(document.querySelector('input[name=operation][value="page-save"]')?.form.querySelector('[name=version]')?.value)>v,previous);};
  const policy=()=>form().locator('.page-policy-panel');await policy().locator('summary').click();await layout(p,policy(),app+' '+mode+' page rules');await policy().locator('[name=page_policy_public]').selectOption('0');
  await save();check(await form().locator('[name="custom_fields[policy_browser_field]"]').inputValue()==='abc',app+' actual contextual typed save');
  await publicFiles(c,flow,404,app+' live per-page public revocation');
  const edit=await context(app,js,'editor'),ep=await edit.newPage();active=ep;await ep.goto(pageURL(app,flow.id),{waitUntil:'networkidle'});check(await ep.locator('.page-policy-panel').count()===0,app+' ordinary editor has no unusable administration controls');
  const ef=ep.locator('form').filter({has:ep.locator('input[name=operation][value=page-save]')}),ecsrf=await ef.locator('[name=csrf]').inputValue(),ev=await ef.locator('[name=version]').inputValue();
  const forged=await edit.request.post(pageURL(app,flow.id),{headers:{Accept:'application/json'},form:wire(app,{operation:'page-save',csrf:ecsrf,id:flow.id,version:ev,page_policy_present:'1',page_policy_public:'1'})});check(await rejected(forged),app+' real controller rejects forged editor policy form');
  await ef.getByRole('button',{name:'Entwurf speichern',exact:true}).click();await ep.waitForFunction(v=>Number(document.querySelector('input[name=operation][value="page-save"]')?.form.querySelector('[name=version]')?.value)>Number(v),ev);await publicFiles(edit,flow,404,app+' older editor form preserves original explicit policy');await edit.close();active=p;
  await p.goto(pageURL(app,flow.id),{waitUntil:'networkidle'});await policy().locator('summary').click();await policy().locator('[name=page_policy_public]').selectOption('inherit');await policy().locator('[name=page_policy_read]').selectOption('manage');await save();
  const denied=await context(app,js,'editor'),dp=await denied.newPage();active=dp;await dp.goto(pageURL(app,flow.id),{waitUntil:'networkidle'});check(await dp.locator('input[name=operation][value=page-save]').count()===0&&!(await dp.locator('body').innerText()).includes('Own policy original '+app+' '+mode),app+' known direct ID reveals neither edit form nor protected original title');await denied.close();active=p;
  await publicFiles(c,flow,404,app+' strict read rule also keeps public content private');
  await p.goto(pageURL(app,flow.id),{waitUntil:'networkidle'});await policy().locator('summary').click();await policy().locator('[name=page_policy_public]').selectOption('1');await policy().locator('[name=page_policy_read]').selectOption('inherit');await save();await publicFiles(c,flow,200,app+' deliberate current grant restores exact original publication');
  const original=await c.request.get(f.origins.integrated+'/site/'+flow.slug);const body=await original.text();check(body.includes('Own published original exact bytes')&&body.includes('Original value'),app+' original field and body remain published despite new private draft/context');await c.close();
 }
 check(external===0,'both applications make zero external requests');check(errors.length===0,'both applications have zero JavaScript errors '+errors.join(';'));
 const result={suite:'native-template-policy-browser',applications:2,flows:4,states,checks,actual_ui_posts:posts,external_requests:external,javascript_errors:errors,duration_ms:Date.now()-start,live_transport:false};await writeFile(f.result,JSON.stringify(result,null,2)+'\n');console.log(JSON.stringify(result));
}catch(error){if(active&&!active.isClosed())console.error('OWN_POLICY_FAILURE',JSON.stringify({url:active.url(),text:(await active.locator('body').innerText()).slice(0,5500),invalid:await active.locator(':invalid').evaluateAll(ns=>ns.map(n=>({tag:n.tagName,name:n.name,message:n.validationMessage}))),errors}));throw error;}finally{await browser.close();}
