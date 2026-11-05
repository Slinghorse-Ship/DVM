import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = fileURLToPath(new URL('../../', import.meta.url));
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_EXECUTABLE || '/usr/bin/chromium' });
let checks=0, transitions=0;
const check=(ok,label)=>{assert(ok,label);checks++;console.log('PASS '+label);};
try{
 for(const mode of ['integrated','standalone']){
  const origin=mode==='integrated'?'http://127.0.0.1:8111':'http://127.0.0.1:8107';
  const fixture=JSON.parse(await readFile(root+'.local/browser-tests/page-trash-'+mode+'.json','utf8'));
  const context=await browser.newContext(),errors=[],external=[];
  const restrict=client=>client.route('**/*',r=>new URL(r.request().url()).origin===origin?r.continue():(external.push(r.request().url()),r.abort()));
  await restrict(context);const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
  const credentials=mode==='integrated'?JSON.parse(await readFile(root+'.local/native-cms-integration/integrated-manager-runtime/browser-credentials.json','utf8')):null;
  const login=async(p,role='admin')=>{if(!credentials)return;await p.goto(origin+'/login/');await p.locator('[name=email]').fill(credentials[role].email);await p.locator('[name=password]').fill(credentials[role].password);await Promise.all([p.waitForURL('**/vereinsverwaltung/**'),p.getByRole('button',{name:'Anmelden und Vereinsmanager öffnen',exact:true}).click()]);};
  await login(page);
  const url=(id='',view='website',extra={})=>origin+(mode==='integrated'?'/vereinsverwaltung/?':'/?')+new URLSearchParams(mode==='integrated'?{view:'website',tab:({website:'pages',news:'news',events:'events',media:'gallery'})[view],...(id?{native_item:id}:{}),...extra}:{view,...(id?{item:id}:{}),...extra});
  const submit=async(p,restore=false,noJs=false)=>{
   const form=p.locator('.page-trash-panel form');if(!restore)await form.locator('[name=trash_confirm]').check();
   const response=p.waitForResponse(r=>r.request().method()==='POST'&&r.request().postData()?.includes(restore?'page-trash-restore':'page-trash'));
   await form.getByRole('button',{name:restore?'Privat zurückholen':'In den Papierkorb verschieben',exact:true}).click();
   const res=await response;check(res.status()===(mode==='standalone'&&!noJs?200:303),mode+' actual '+(restore?'restore':'trash')+' HTTP flow');
   await p.waitForLoadState('networkidle');transitions++;
  };
  for(const width of [1440,820,390]){
   await page.setViewportSize({width,height:1000});
   for(const[view,row]of Object.entries(fixture.source)){
    await page.goto(url(row.id,view));const panel=page.locator('.page-trash-panel');
    check(await panel.getByRole('button',{name:'In den Papierkorb verschieben',exact:true}).count()===1,mode+' shared action '+view+' '+width);
    check(await panel.locator('[name=trash_confirm]').getAttribute('required')!==null,mode+' visible confirmation '+view+' '+width);
    check(!await panel.evaluate(p=>p.getBoundingClientRect().right>innerWidth+1||p.scrollWidth>p.clientWidth+1),mode+' panel fits '+view+' '+width);
    await panel.getByRole('button',{name:'In den Papierkorb verschieben',exact:true}).focus();check(await panel.getByRole('button',{name:'In den Papierkorb verschieben',exact:true}).evaluate(b=>b===document.activeElement),mode+' keyboard focus '+view+' '+width);
    await submit(page);
    check(await page.locator('[data-trash-row="'+row.id+'"]').count()===1,mode+' original appears in trash '+view+' '+width);
    await page.goto(url(row.id,view));
    check(await page.locator('.page-trash-panel h2').textContent()==='Im Papierkorb',mode+' direct editor identifies retired original');
    check(await page.locator('form [name=operation][value="page-publish"],form [name=operation][value="article-save"],form [name=operation][value="album-save"],form [name=operation][value="page-save"]').count()===0,mode+' retired original offers no content write');
    const publicResponse=await context.request.get(origin+'/site/'+row.slug+(mode==='integrated'?'/':''));check(publicResponse.status()===404,mode+' retired original is publicly unavailable');
    await submit(page,true);
    check(await page.locator('.page-trash-panel [name=id]').inputValue()===row.id,mode+' restored original returns to correct '+view+' editor');
    check((await context.request.get(origin+'/site/'+row.slug+(mode==='integrated'?'/':''))).status()===404,mode+' restore stays private');
   }
  }
  await page.goto(url(fixture.parent.id));check(await page.locator('.page-trash-panel').getByRole('link',{name:fixture.child.title,exact:true}).count()===2,mode+' blocker names exact retained child');
  check(await page.locator('.page-trash-panel [name=trash_confirm]').count()===0,mode+' blocked original has no retirement form');
  const row=fixture.source.website;await page.goto(url(row.id));const values=await page.locator('.page-trash-panel form').evaluate(f=>Object.fromEntries(new FormData(f)));
  const missing=await context.request.post(url(row.id),{form:values});check(missing.status()===422,mode+' missing confirmation rejected over HTTP');
  const csrf=await context.request.post(url(row.id),{form:{...values,csrf:'wrong',trash_confirm:'1'}});check([403,422].includes(csrf.status()),mode+' forged CSRF rejected over HTTP');
  const noJs=await browser.newContext({javaScriptEnabled:false,storageState:await context.storageState()});await restrict(noJs);const basic=await noJs.newPage();
  await basic.goto(url(row.id));await submit(basic,false,true);await basic.goto(url(row.id));await submit(basic,true,true);await noJs.close();
  const second=await context.newPage();await second.goto(url(row.id));const old=await second.locator('.page-trash-panel [name=version]').inputValue();
  await page.goto(url(row.id));await submit(page);await page.goto(url(row.id));await submit(page,true);
  await second.locator('[name=trash_confirm]').check();const conflict=second.waitForResponse(r=>r.request().method()==='POST');await second.getByRole('button',{name:'In den Papierkorb verschieben',exact:true}).click();
  check((await conflict).status()===(mode==='standalone'?409:422),mode+' actual stale tab rejected');await second.waitForLoadState('networkidle');
  check(await second.locator('.page-trash-panel [name=version]').inputValue()===old,mode+' stale form cannot adopt newer version');await second.close();
  if(mode==='integrated'){const memberContext=await browser.newContext();await restrict(memberContext);const member=await memberContext.newPage();await login(member,'member');const res=await member.goto(url(row.id));check(res.status()===403||await member.locator('.page-trash-panel form').count()===0,'member cannot retire or restore originals');await memberContext.close();}
  check(errors.length===0,mode+' no browser errors');check(external.length===0,mode+' no external requests');await context.close();
 }
 const result={suite:'native-page-trash-browser',checks,transitions,widths:[1440,820,390],external_actions:0};await writeFile(root+'.local/browser-tests/page-trash-result.json',JSON.stringify(result)+'\n',{mode:0o600});console.log(JSON.stringify(result));
}finally{await browser.close();}
