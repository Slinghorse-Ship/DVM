import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {execFileSync} from 'node:child_process';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE),root='/workspace/Kulturhus/';
const setups=[{mode:'integrated',origin:'http://127.0.0.1:8111',config:root+'.local/native-cms-integration/integrated-manager.json'},{mode:'standalone',origin:'http://127.0.0.1:8107',config:'/workspace/.setup/native-demo.json'}];
const browser=await chromium.launch({executablePath:'/usr/bin/chromium',args:['--no-sandbox']});let checks=0,writes=0,external=0;const errors=[];
const check=(ok,label)=>{assert(ok,label);++checks;console.log('PASS '+label);};
const fits=page=>page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1);
try{
 for(const setup of setups){
  const fixture=JSON.parse(await readFile(root+'.local/browser-tests/public-search-'+setup.mode+'.json','utf8'));
  for(const javaScriptEnabled of [true,false]){
   const context=await browser.newContext({javaScriptEnabled,viewport:{width:390,height:844},hasTouch:true,isMobile:true});
   await context.route('**/*',r=>{const req=r.request();if(new URL(req.url()).origin!==setup.origin){++external;return r.abort();}if(!['GET','HEAD'].includes(req.method())){++writes;return r.abort();}return r.continue();});
   const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
   for(const width of [1440,820,390,320]){
    await page.setViewportSize({width,height:844});
    for(const number of [1,3,4]){
     const response=await page.goto(setup.origin+'/search?'+new URLSearchParams({q:fixture.query,seite:String(number)}));
     check(response.status()===200&&await page.locator('[data-search-result]').count()===(number===4?7:20),`actual complete bounded public page ${setup.mode} ${number} ${width} JS=${javaScriptEnabled}`);
     const ids=await page.locator('[data-search-result]').evaluateAll(rows=>rows.map(r=>r.dataset.searchResult));check(JSON.stringify(ids)===JSON.stringify(fixture.rows.slice((number-1)*20,number*20).map(r=>r.id)),`exact immutable public order beyond former cutoff ${setup.mode} ${number} ${width}`);
     check((await page.locator('[data-public-search] [role=status]').innerText())===`67 Treffer · Seite ${number} von 4`, `actual full count and page ${setup.mode} ${number} ${width}`);
     check(await fits(page),`search and pagination fit ${setup.mode} ${width} JS=${javaScriptEnabled}`);
     check(await page.locator('[data-public-search] form button,[data-public-search] nav a').evaluateAll(nodes=>nodes.every(n=>{const r=n.getBoundingClientRect();return r.width>=44&&r.height>=44&&r.right<=innerWidth+1;})),`actual search and page touch targets ${setup.mode} ${width}`);
     check((await page.locator('input[name=q]').inputValue())===fixture.query&&await page.locator('[data-search-result] script').count()===0,`original query and inert original markup ${setup.mode} ${width}`);
    }
   }
   await page.setViewportSize({width:390,height:844});await page.goto(setup.origin+'/suche/?'+new URLSearchParams({q:fixture.query,seite:'999999'}));
   check((await page.locator('[data-public-search] [role=status]').innerText())==='67 Treffer · Seite 4 von 4'&&await page.locator('[data-search-result]').count()===7,'actual alias clamps to last page '+setup.mode+' JS='+javaScriptEnabled);
   const previous=page.locator('[data-public-search] a[rel=prev]').first();await previous.focus();await Promise.all([page.waitForURL('**/suche?**seite=3'),previous.press('Enter')]);
   check((await page.locator('[data-public-search] [role=status]').innerText())==='67 Treffer · Seite 3 von 4','keyboard retains original alias and query '+setup.mode+' JS='+javaScriptEnabled);
   await Promise.all([page.waitForURL('**seite=4'),page.locator('[data-public-search] a[rel=next]').first().tap()]);check(await page.locator('[data-search-result]').count()===7,'touch opens actual final result page '+setup.mode+' JS='+javaScriptEnabled);
   await Promise.all([page.waitForURL('**seite=3'),page.locator('[data-search-pagination=bottom] a[rel=prev]').tap()]);check(await page.locator('[data-search-result]').count()===20,'bottom touch navigation remains usable '+setup.mode+' JS='+javaScriptEnabled);
   await page.locator('input[name=q]').fill('Kein tatsächlicher Suchtreffer');await Promise.all([page.waitForURL('**q=Kein**'),page.locator('[data-public-search] form button').tap()]);check(await page.locator('[data-search-result]').count()===0&&await page.locator('[data-public-search] nav').count()===0,'new query resets page and shows real empty state '+setup.mode+' JS='+javaScriptEnabled);
   await page.locator('input[name=q]').fill(fixture.query);await Promise.all([page.waitForURL('**q=Eigene**'),page.locator('input[name=q]').press('Enter')]);check((await page.locator('[data-public-search] [role=status]').innerText())==='67 Treffer · Seite 1 von 4','native form resets query to first exact page '+setup.mode+' JS='+javaScriptEnabled);
   if(javaScriptEnabled){await page.evaluate(()=>document.documentElement.style.zoom='2');check(await fits(page),'actual search fits 200 percent zoom '+setup.mode);await page.evaluate(()=>document.documentElement.style.zoom='');}
   const first=page.locator('[data-search-result] h2 a').first();const expected=fixture.rows[0];await Promise.all([page.waitForURL(url=>url.pathname.replace(/\/$/,'').endsWith('/'+expected.slug)),first.tap()]);check((await page.locator('h1').innerText())===expected.title,'touch opens exact actual public original '+setup.mode+' JS='+javaScriptEnabled);
   await context.close();
  }
  const response=await browser.newContext();const get=await response.request.get(setup.origin+'/search?'+new URLSearchParams({q:fixture.query,seite:'3'}));const body=await get.text(),etag=get.headers().etag;
  const head=await response.request.head(get.url());check(head.status()===200&&(await head.body()).length===0&&head.headers().etag===etag&&Number(head.headers()['content-length'])===Buffer.byteLength(body),'actual search HEAD retains exact representation '+setup.mode);
  const conditional=await response.request.get(get.url(),{headers:{'If-None-Match':'W/'+etag}});check(conditional.status()===304&&(await conditional.body()).length===0,'actual bounded search weak validator is body-free '+setup.mode);
  for(const query of ['q[]=forged','q=%FF','q=original&seite[]=1','q=original&seite=0']){const r=await response.request.get(setup.origin+'/search?'+query);check(r.status()===(setup.mode==='standalone'?422:400),'actual malformed search request rejected '+setup.mode+' '+query);}
  await response.close();
  const proof=execFileSync(process.env.PHP_CLI_BINARY||'php',[root+'vereinsverwaltung/tests/support/public-search-browser-fixture.php','check'],{encoding:'utf8',env:{...process.env,KH_NATIVE_TEST_CONFIG:setup.config}});check(proof.includes('Every exact original retained'),'all original database bytes retained '+setup.mode);
 }
 check(!writes&&!external&&!errors.length,'search has no business writes, external actions or script errors');console.log(JSON.stringify({checks,business_writes:writes,external_actions:external}));
}finally{await browser.close();}
