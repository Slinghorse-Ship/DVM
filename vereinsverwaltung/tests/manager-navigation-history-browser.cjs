'use strict';
// Focused browser history regression against the registered real PHP/MySQL demo.
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'C:/Users/wehla/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const root=path.resolve(__dirname,'../..');
const fixture=JSON.parse(fs.readFileSync(path.join(root,'.local/manager-navigation-fixture/fixture.json'),'utf8'));
const base=new URL(fixture.base);
assert.equal(fixture.owner,'manager-navigation');assert.equal(base.hostname,'127.0.0.1');assert(!['8091','8092'].includes(base.port));
const report={checks:[],errors:[],externalRequests:0};
(async()=>{
 const browser=await chromium.launch({headless:true});
 try{
  const context=await browser.newContext({serviceWorkers:'block'});
  await context.route('**/*',route=>{if(new URL(route.request().url()).origin!==base.origin){report.externalRequests++;return route.abort();}return route.continue();});
  const page=await context.newPage();page.on('pageerror',error=>report.errors.push(error.message));
  await page.addInitScript(()=>{window.historyModuleErrors=[];document.addEventListener('manager:module-error',event=>window.historyModuleErrors.push(event.detail.module));});
  const response=await page.goto(new URL('?view=dashboard',base).href);
  assert.equal(response.headers()['x-kulturhus-local-project'],fixture.project_hash);
  await page.evaluate(()=>{
   window.historyDocumentMarker='same-document';window.fragmentEvents=0;
   window.addEventListener('hashchange',()=>window.fragmentEvents++);
   for(const id of ['navigation-fragment-one','navigation-fragment-two']){
    const section=document.createElement('section');section.id=id;section.tabIndex=-1;
    const link=document.createElement('a');link.href='#'+id;link.textContent=id;link.id=id+'-link';section.append(link);document.querySelector('main').append(section);
   }
  });
  await page.locator('#navigation-fragment-one-link').click();
  assert.equal(await page.evaluate(()=>window.fragmentEvents),1);
  await page.locator('#navigation-fragment-two-link').click();
  assert.equal(await page.evaluate(()=>window.fragmentEvents),2);
  report.checks.push('Fragment clicks dispatch one hashchange each without document reload');
  await page.evaluate(()=>history.back());
  await page.waitForFunction(()=>location.hash==='#navigation-fragment-one'&&window.fragmentEvents>=3);
  await page.waitForTimeout(80);assert.equal(await page.evaluate(()=>window.fragmentEvents),3);
  report.checks.push('Fragment Back dispatches exactly one native hashchange');
  await page.evaluate(()=>history.forward());
  await page.waitForFunction(()=>location.hash==='#navigation-fragment-two'&&window.fragmentEvents>=4);
  await page.waitForTimeout(80);assert.equal(await page.evaluate(()=>window.fragmentEvents),4);
  assert.equal(await page.evaluate(()=>window.historyDocumentMarker),'same-document');
  report.checks.push('Fragment Forward dispatches exactly one native hashchange and preserves the document');
  const handbook=await context.request.get(new URL('?view=handbook',base).href);assert.equal(handbook.status(),410);
  report.checks.push('Disabled handbook still responds with HTTP 410');
  assert.deepEqual(await page.evaluate(()=>window.historyModuleErrors),[]);assert.deepEqual(report.errors,[]);assert.equal(report.externalRequests,0);
 }finally{await browser.close();fs.writeFileSync(path.join(root,'.local/manager-navigation-fixture/history-report.json'),JSON.stringify(report,null,2));}
 console.log(JSON.stringify({passed:report.checks.length,errors:report.errors.length,externalRequests:report.externalRequests}));
})().catch(error=>{console.error(error.message);process.exitCode=1;});
