'use strict';
// Local regression for stable branding and a new build arriving in an open tab.
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'C:/Users/wehla/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const root=path.resolve(__dirname,'../..');
const fixtureFile=process.env.KULTURHUS_NAVIGATION_FIXTURE||path.join(root,'.local/manager-navigation-fixture/fixture.json');
const fixture=JSON.parse(fs.readFileSync(fixtureFile,'utf8')),base=new URL(fixture.base);
assert.equal(fixture.owner,'manager-navigation');assert.equal(base.hostname,'127.0.0.1');
assert(!['8091','8092','13371'].includes(base.port));assert.equal(base.pathname,'/vereinsverwaltung/');
const report={scope:'Local registered PHP/MySQL fixture; response-only build updates; no files or business data mutated',checks:[],failures:[],pageErrors:[],moduleErrors:[],externalBlocked:[],browserDialogs:[]};
const output=path.join(path.dirname(fixtureFile),'navigation-update-report.json');
const href=q=>new URL(q,base).href;
const ready=async p=>p.waitForFunction(()=>window.KulturhusNavigation&&!document.querySelector('main')?.hasAttribute('aria-busy'));
const nav=async(p,q)=>{assert.equal(await p.evaluate(q=>window.KulturhusNavigation.navigate(q),q),true);await ready(p);};
const appearance=p=>p.evaluate(()=>{
 const css=(selector,properties)=>{const node=document.querySelector(selector),s=getComputedStyle(node);return Object.fromEntries(properties.map(p=>[p,s.getPropertyValue(p)]));};
 return {brand:document.body.getAttribute('data-brand'),body:css('body',['background-color','color','--orange','--nav']),sidebar:css('#navigation',['background-color','color']),heading:css('main h1',['font-size','color']),styles:[...document.querySelectorAll('link[rel=stylesheet]')].filter(l=>l.media!=='not all').map(l=>new URL(l.href).pathname)};
});
// Only change the HTML response's manifest. No runtime file is rewritten while testing.
async function updateNextResponse(context,q,gate) {
 const target=href(q);
 const handler=async route=>{
  if(route.request().headers()['x-kulturhus-navigation']!=='1')return route.continue();
  await context.unroute(target,handler);
  const response=await route.fetch();
  const body=(await response.text()).replace(/(manager-navigation\.js\?v=)[a-f0-9]+/g,'$1ffffffffffffffff');
  if(gate)await gate();
  await route.fulfill({response,body}).catch(()=>{});
 };
 await context.route(target,handler);
}
(async()=>{
 const browser=await chromium.launch({headless:true});
 const run=async(name,fn)=>{
  if(process.env.KULTURHUS_UPDATE_TEST_FILTER&&!name.includes(process.env.KULTURHUS_UPDATE_TEST_FILTER))return;
  const context=await browser.newContext({viewport:{width:1440,height:1000},serviceWorkers:'block'});
  context.setDefaultTimeout(15000);
  await context.route('**/*',route=>{if(new URL(route.request().url()).origin!==base.origin){report.externalBlocked.push(new URL(route.request().url()).origin);return route.abort();}return route.continue();});
  await context.addInitScript(()=>document.addEventListener('manager:module-error',e=>console.error('navigation-test-module-error:'+e.detail?.name)));
  const page=await context.newPage();let documents=0;
  page.on('request',r=>{if(r.isNavigationRequest()&&r.frame()===page.mainFrame())documents++;});
  page.on('pageerror',e=>report.pageErrors.push({test:name,message:e.message}));
  page.on('console',m=>{if(m.text().startsWith('navigation-test-module-error:'))report.moduleErrors.push(m.text());});
  page.on('dialog',async d=>{report.browserDialogs.push({test:name,type:d.type()});await d.dismiss();});
  try{
   const response=await page.goto(base.href);assert.equal(response.headers()['x-kulturhus-local-project'],fixture.project_hash);
   await ready(page);await page.evaluate(()=>window.updateTestMarker='initial');
   await fn({page,context,documents:()=>documents});report.checks.push(name);console.log('PASS '+name);
  }catch(e){report.failures.push({name,error:e.message});console.log('FAIL '+name+': '+e.message);}
  finally{await context.close();}
 };
 try{
  await run('Bank appearance matches direct load before and after module revisits',async({page,context,documents})=>{
   const direct=await context.newPage();await direct.goto(href('?view=finance&tab=bank'));await ready(direct);const expected=await appearance(direct);await direct.close();
   await nav(page,'?view=finance&tab=bank');assert.deepEqual(await appearance(page),expected);
   await nav(page,'?view=documents');await nav(page,'?view=finance&tab=bank');assert.deepEqual(await appearance(page),expected);
   assert.equal(documents(),1);assert.equal(await page.evaluate(()=>window.updateTestMarker),'initial');
  });
  await run('Explicit brand, empty original brand and absent brand remain distinct',async({page,context,documents})=>{
   for(const brand of ['sport','',null]){
    await context.route(href('?view=dashboard'),async route=>{const response=await route.fetch();await route.fulfill({response,body:(await response.text()).replace(/<body data-brand="[^"]*">/,brand===null?'<body>':`<body data-brand="${brand}">`)});},{times:1});
    await nav(page,'?view=dashboard');assert.equal(await page.locator('body').getAttribute('data-brand'),brand);
   }
   assert.equal(documents(),1);
  });
  await run('Clean page opens new build once and resumes partial navigation',async({page,context,documents})=>{
   await page.locator('#navigation details').evaluateAll(nodes=>nodes.forEach(n=>n.open=true));
   await updateNextResponse(context,'?view=finance&tab=bank');
   await Promise.all([page.waitForEvent('load'),page.getByRole('link',{name:'Bank & Kasse',exact:true}).click()]);await ready(page);
   assert.equal(page.url(),href('?view=finance&tab=bank'));assert.equal(documents(),2);assert.equal(await page.locator('[data-manager-navigation-error]').count(),0);
   await page.evaluate(()=>window.updateTestMarker='fresh');await nav(page,'?view=documents');assert.equal(documents(),2);assert.equal(await page.evaluate(()=>window.updateTestMarker),'fresh');
  });
  await run('Update preserves a dirty form when discard is cancelled',async({page,context,documents})=>{
   await nav(page,'?view=documents&new_folder=1');await page.locator('form [name=name]').fill('Ungespeicherter Testordner');
   await updateNextResponse(context,'?view=dashboard');
   const result=page.evaluate(()=>window.KulturhusNavigation.navigate('?view=dashboard'));await page.getByRole('button',{name:'Abbrechen',exact:true}).click();assert.equal(await result,false);
   assert.equal(await page.locator('form [name=name]').inputValue(),'Ungespeicherter Testordner');assert.equal(documents(),1);assert.equal(await page.locator('[data-manager-navigation-error]').count(),0);
  });
  await run('Confirmed discard loads the new build without a second browser prompt',async({page,context,documents})=>{
   await nav(page,'?view=documents&new_folder=1');await page.locator('form [name=name]').fill('Verwerfbarer Testordner');
   await updateNextResponse(context,'?view=dashboard');
   const result=page.evaluate(()=>{void window.KulturhusNavigation.navigate('?view=dashboard');});await result;
   await Promise.all([page.waitForEvent('load'),page.getByRole('button',{name:'Änderungen verwerfen',exact:true}).click()]);await ready(page);
   assert.equal(page.url(),href('?view=dashboard'));assert.equal(documents(),2);assert.equal(await page.locator('[data-manager-navigation-error]').count(),0);
  });
  for(const structural of [false,true])await run((structural?'Structural form change':'Typing')+' during update fetch cancels reload',async({page,context,documents})=>{
   await nav(page,'?view=documents&new_folder=1');const original=page.url();let reached,release;const arrived=new Promise(r=>reached=r),gate=new Promise(r=>release=r);
   await updateNextResponse(context,'?view=calendar',async()=>{reached();await gate;});
   try{
    const result=page.evaluate(()=>window.KulturhusNavigation.navigate('?view=calendar'));await arrived;
    if(structural)await page.locator('form [name=scope]').evaluate(n=>n.remove());
    else await page.locator('form [name=name]').fill('Eingabe während Versionswechsel');
    release();assert.equal(await result,false);assert.equal(page.url(),original);assert.equal(documents(),1);
    if(!structural)assert.equal(await page.locator('form [name=name]').inputValue(),'Eingabe während Versionswechsel');
   }finally{release?.();}
  });
  await run('Build refresh retains the requested fragment and replace semantics',async({page,context,documents})=>{
   const length=await page.evaluate(()=>history.length);await updateNextResponse(context,'?view=finance&tab=help');
   await Promise.all([page.waitForEvent('load'),page.evaluate(()=>{void window.KulturhusNavigation.navigate('?view=finance&tab=help#hilfe-export',{replace:true});})]);await ready(page);
   assert.equal(new URL(page.url()).hash,'#hilfe-export');assert.equal(documents(),2);assert.equal(await page.evaluate(()=>history.length),length);
  });
  await run('Back across an update reloads the selected history entry without adding a slot',async({page,context,documents})=>{
   await nav(page,'?view=documents');await nav(page,'?view=finance&tab=bank');const length=await page.evaluate(()=>history.length);
   await updateNextResponse(context,'?view=documents');await Promise.all([page.waitForEvent('load'),page.evaluate(()=>history.back())]);await ready(page);
   assert.equal(page.url(),href('?view=documents'));assert.equal(documents(),2);assert.equal(await page.evaluate(()=>history.length),length);
   await Promise.all([page.waitForEvent('load'),page.evaluate(()=>history.forward())]);await ready(page);assert.equal(page.url(),href('?view=finance&tab=bank'));assert.equal(await page.evaluate(()=>history.length),length);
  });
  await run('Dirty Back during an update can be cancelled and then explicitly discarded',async({page,context,documents})=>{
   await nav(page,'?view=documents');await nav(page,'?view=documents&new_folder=1');await page.locator('form [name=name]').fill('Zurück schützt Eingaben');const length=await page.evaluate(()=>history.length);
   assert.equal(documents(),1,'Setup must remain in the initial build');assert.equal(await page.evaluate(()=>history.state?.khManager?.index),2);
   await updateNextResponse(context,'?view=documents');await page.evaluate(()=>history.back());await page.getByRole('button',{name:'Abbrechen',exact:true}).click();
   assert.equal(await page.locator('form [name=name]').inputValue(),'Zurück schützt Eingaben');assert.equal(documents(),1);
   await page.evaluate(()=>history.back());await Promise.all([page.waitForEvent('load'),page.getByRole('button',{name:'Änderungen verwerfen',exact:true}).click()]);await ready(page);
   assert.equal(page.url(),href('?view=documents'));assert.equal(documents(),2);assert.equal(await page.evaluate(()=>history.length),length);
  });
 }finally{
  await browser.close();fs.writeFileSync(output,JSON.stringify(report,null,2)+'\n');
  console.log(JSON.stringify({passed:report.checks.length,failed:report.failures.length,pageErrors:report.pageErrors.length,moduleErrors:report.moduleErrors.length,externalBlocked:report.externalBlocked.length,browserDialogs:report.browserDialogs.length}));
  if(report.failures.length||report.pageErrors.length||report.moduleErrors.length||report.externalBlocked.length||report.browserDialogs.length)process.exitCode=1;
 }
})().catch(e=>{console.error(e.message);process.exitCode=1;});
