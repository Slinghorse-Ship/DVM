import {readFile,writeFile} from 'node:fs/promises';
import assert from 'node:assert/strict';
const f=JSON.parse(await readFile(process.env.DVM_TYPED_APP_FIXTURE,'utf8'));
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const browser=await chromium.launch({executablePath:process.env.CHROMIUM_EXECUTABLE||'/usr/bin/chromium'});
let checks=0,states=0,posts=0,external=0,active=null;const errors=[];
const check=(ok,label)=>{assert(ok,label);checks++;};
const base=app=>f.origins[app]+(app==='integrated'?'/vereinsverwaltung/':'/');
const url=(app,id)=>base(app)+'?view=website&'+(app==='integrated'?'tab=pages&native_item=':'item=')+id;
const key=suffix=>f.prefix+'_'+suffix;
async function context(app,js,role='admin'){
 const c=await browser.newContext({javaScriptEnabled:js,viewport:{width:390,height:844}});
 await c.route('**/*',route=>new URL(route.request().url()).origin===f.origins[app]?route.continue():(external++,route.abort()));
 const login=await c.request.get(f.origins[app]+'/own-test-enter?role='+role,{maxRedirects:0});check(login.status()===303,app+' original Native Auth session established');
 c.on('page',p=>{p.on('pageerror',e=>errors.push(e.message));p.on('request',r=>{if(r.method()==='POST')posts++;});p.on('response',r=>{if(r.status()>=400&&/\.(?:js|css)(?:\?|$)/.test(r.url()))errors.push('Asset '+r.status()+' '+new URL(r.url()).pathname);});});return c;
}
try{
 for(const app of ['native','integrated'])for(const js of [true,false]){
  const mode=js?'js':'basic',flow=f.flows[app][mode],c=await context(app,js),page=await c.newPage();active=page;
  const response=await page.goto(url(app,flow.id),{waitUntil:'networkidle'});check(response.status()===200,app+' actual '+mode+' page editor loads');
  const form=()=>page.locator('form').filter({has:page.locator('input[name=operation][value=page-save]')});
  const collection=suffix=>form().locator('[data-typed-collection][data-model-field="'+key(suffix)+'"]').first();
  const rows=block=>block.locator(':scope > [data-collection-items] > [data-collection-item]');
  if(js)await page.waitForFunction(()=>window.dvmTypedFieldsReady===true);
  check(await collection('members').count()===1,app+' actual model controls present');
  for(const width of [1280,768,320])for(const font of [16,32]){
   await page.setViewportSize({width,height:900});await page.evaluate(size=>document.documentElement.style.fontSize=size+'px',font);
   const info=await collection('members').evaluate(panel=>({fits:document.documentElement.scrollWidth<=innerWidth+1,height:panel.getBoundingClientRect().height,targets:[...panel.querySelectorAll('button,summary')].filter(n=>n.checkVisibility({contentVisibilityAuto:true})&&n.getBoundingClientRect().height>0).map(n=>({label:n.textContent.trim().slice(0,40),width:n.getBoundingClientRect().width,height:n.getBoundingClientRect().height})),exception:/Fatal error:|SQLSTATE\[|Stack trace:/.test(document.body.innerText)}));
   check(info.fits,app+' '+mode+' actual viewport '+width+'/'+font);check(!info.exception,app+' '+mode+' no runtime exception '+width+'/'+font);check(info.height<1300,app+' '+mode+' compact collapsed collection '+width+'/'+font);
   for(const target of info.targets)check(target.width>=43&&target.height>=43,app+' actual touch target '+target.label+' '+width+'/'+font+' '+JSON.stringify(target));states++;
  }
  await page.setViewportSize({width:390,height:844});await page.evaluate(()=>document.documentElement.style.fontSize='16px');
  const blank=rows(collection('members')).last();await blank.locator(':scope > summary').click();
  const upTarget=await blank.locator('[data-collection-up]').boundingBox();check(upTarget.width>=43&&upTarget.height>=43,app+' visible reorder target '+JSON.stringify(upTarget));
  const guest='Eigener neuer Kontakt '+app+' '+mode;
  await blank.locator('input[name$="['+key('name')+']"]').fill(guest);
  const rich=blank.locator('[data-kh-richtext]');
  if(js)await rich.locator('[data-richtext-surface]').fill('Eigener formatierter Text '+mode);
  else check(await rich.locator('[data-richtext-value]').inputValue()===''&&await rich.locator('noscript').count()===1,app+' basic editor preserves formatted content without JavaScript');
  const pdf=blank.locator('[data-reference-kind=file]');
  if(js){await pdf.locator('[data-reference-query]').fill('Merkblatt');await pdf.locator('[data-reference-action=search]').click();await page.waitForFunction(()=>!document.querySelector('[data-reference-kind=file][aria-busy=true]'));check(await pdf.locator('[data-reference-select] option').count()===2,app+' actual guarded PDF endpoint');}
  await pdf.locator('[data-reference-select]').selectOption(f.document);
  const csrf=await form().locator('input[name=csrf]').inputValue();
  const bad=await c.request.post(url(app,flow.id),{headers:{Accept:'application/json'},form:{...(app==='integrated'?{action:'native-module'}:{}),operation:'page-save',csrf:'wrong',id:flow.id,version:String(flow.version)}});check(bad.status()>=400&&bad.status()<500,app+' actual page save refuses invalid CSRF');
  await form().getByRole('button',{name:'Entwurf speichern',exact:true}).click();
  await page.waitForFunction(({key})=>document.querySelectorAll('[data-typed-collection][data-model-field="'+key+'"] > [data-collection-items] > [data-collection-item]').length===3,{key:key('members')});
  const stored=rows(collection('members')).nth(1);await stored.locator(':scope > summary').click();
  check(await stored.locator('input[name$="['+key('name')+']"]').inputValue()===guest,app+' '+mode+' real POST persists new original value');
  check((await stored.locator('input[name$="[id]"]').inputValue()).length===32&&await stored.locator('input[name$="[version]"]').inputValue()==='1',app+' '+mode+' real POST returns server identity/counter');
  check(await form().locator('input[type=password]').inputValue()==='',app+' '+mode+' original password stays write-only');
  // Stored arrays must follow DOM reordering in the real generic save transport.
  const tags=rows(collection('tags'));await tags.nth(1).locator(':scope > summary').click();
  if(js){await tags.nth(1).locator('[data-collection-up]').click();await form().getByRole('button',{name:'Entwurf speichern',exact:true}).click();await page.waitForFunction(({name})=>document.querySelector('[name="'+name+'"]')?.value==='Original B',{name:'custom_fields['+key('tags')+'][0][value]'});check(true,app+' real save persists array reorder');}
  await c.close();
  const editor=await context(app,js,'editor'),p=await editor.newPage();active=p;await p.goto(url(app,flow.id),{waitUntil:'networkidle'});
  const editorForm=p.locator('form').filter({has:p.locator('input[name=operation][value=page-save]')});
  check(await editorForm.locator('input[name$="[preserve]"]').count()===1,app+' '+mode+' restricted original uses protected original stub');
  await editorForm.getByRole('button',{name:'Entwurf speichern',exact:true}).click();
  await p.waitForLoadState('networkidle');
  check(!(await p.locator('body').innerText()).includes('Datensatzformular unvollständig'),app+' '+mode+' normal editor saves while preserving restricted item');
  await editor.close();
 }
 check(errors.length===0,'both genuine apps without script/asset exceptions '+errors.join('; '));check(external===0,'both genuine apps make no external requests');
 const result={suite:'native-typed-fields-app-browser',applications:2,flows:4,states,checks,posts,external_requests:external,javascript_errors:errors,live_transport:false};await writeFile(f.result,JSON.stringify(result,null,2));console.log(JSON.stringify(result));
}catch(error){if(active&&!active.isClosed())console.error('TYPED_APP_FAILURE',JSON.stringify({url:active.url(),text:(await active.locator('body').innerText()).slice(0,4500),errors}));throw error;}finally{await browser.close();}
