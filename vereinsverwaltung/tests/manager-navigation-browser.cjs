'use strict';
// Real PHP/MySQL browser regression; fixture owner and the loopback endpoint are verified.
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {spawnSync}=require('node:child_process');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'C:/Users/wehla/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const root=path.resolve(__dirname,'../..');
const fixtureFile=process.env.KULTURHUS_NAVIGATION_FIXTURE||path.join(root,'.local/manager-navigation-fixture/fixture.json');
const fixture=JSON.parse(fs.readFileSync(fixtureFile,'utf8'));
const base=new URL(fixture.base),out=path.dirname(fixtureFile);
assert.equal(fixture.owner,'manager-navigation');assert.equal(base.hostname,'127.0.0.1');
assert(!['8091','8092','13371'].includes(base.port));assert.equal(base.pathname,'/vereinsverwaltung/');
const php=process.env.KULTURHUS_TEST_PHP||path.join(root,'.local/php-8.5.10/php.exe');
const readback=()=>{const r=spawnSync(php,['-d','extension=pdo_mysql','-d','zend.exception_ignore_args=1',path.join(__dirname,'support/manager-navigation-fixture.php'),'readback'],{encoding:'utf8',cwd:root});assert.equal(r.status,0,'Fixture readback must succeed');return JSON.parse(r.stdout);};
const report={scope:'Real loopback PHP + separately registered MySQL fixture; synthetic data only',focus:process.env.KULTURHUS_NAVIGATION_FOCUS||'all',checks:[],failures:[],routes:[],excluded:[],errors:[],externalBlocked:[],limits:['Website/communication backend disconnected from persistent ProcessWire demo.','No real camera device, bank, mail, tax submission or external publication.']};
const saveReport=()=>{fs.writeFileSync(path.join(out,'browser-report.json'),JSON.stringify(report,null,2));fs.writeFileSync(path.join(out,'browser-'+report.focus+'-report.json'),JSON.stringify(report,null,2));};
const key=href=>{const u=new URL(href,base);u.hash='';u.searchParams.sort();return u.href;};
// GET forms include optional empty fields which their equivalent menu links omit.
const responseKey=href=>{const u=new URL(href,base);for(const [name,value] of [...u.searchParams])if(value==='')u.searchParams.delete(name);return key(u.href);};
const routeKeys=new Set(['view','tab','section','list','status','show_lists','state','mail_state','doc_mode','unassigned','group','kind','setup','period','report_kind','report_type','report_display','from','to','resolution','compare','new_event','meeting']);
const eligible=href=>{const u=new URL(href,base);return u.origin===base.origin&&u.pathname===base.pathname&&!u.hash&&[...u.searchParams.keys()].every(k=>routeKeys.has(k))&&(!u.searchParams.has('period')||['archive','upcoming','current'].includes(u.searchParams.get('period')));};
(async()=>{
 const before=readback();assert.equal(before.driver,'mysql');assert.equal(before.port,13371);report.before=before;
 const browser=await chromium.launch({headless:true});
 const context=await browser.newContext({viewport:{width:1440,height:1050},serviceWorkers:'block'});
 await context.addInitScript(()=>{window.managerModuleErrors=[];document.addEventListener('manager:module-error',event=>window.managerModuleErrors.push({name:event.detail?.name||event.detail?.module||'unknown'}));});
 context.setDefaultTimeout(12000);context.setDefaultNavigationTimeout(30000);
 await context.route('**/*',route=>{if(new URL(route.request().url()).origin!==base.origin){report.externalBlocked.push({url:new URL(route.request().url()).origin,method:route.request().method()});return route.abort();}return route.continue();});
 const page=await context.newPage();page.on('pageerror',e=>report.errors.push({url:page.url(),message:e.message}));
 const marker='manager-navigation-'+Date.now();let documentRequests=0;
 page.on('request',r=>{if(r.isNavigationRequest()&&r.frame()===page.mainFrame())documentRequests++;});
 const run=async(name,fn)=>{try{await fn();report.checks.push(name);console.log('PASS '+name);}catch(e){const failure={name,error:e.message};if(e.message.includes('Oberfläche wurde aktualisiert'))failure.changedAssets=await page.evaluate(async()=>{const old=JSON.parse(document.querySelector('meta[name="manager-navigation"]').content).assets,html=await(await fetch(location.href)).text(),doc=new DOMParser().parseFromString(html,'text/html'),next=JSON.parse(doc.querySelector('meta[name="manager-navigation"]').content).assets;return [...new Set([...Object.keys(old),...Object.keys(next)])].filter(k=>JSON.stringify(old[k])!==JSON.stringify(next[k])).map(name=>({name,before:old[name],after:next[name]}));}).catch(()=>['Unable to compare']);report.failures.push(failure);console.log('FAIL '+name+': '+e.message+(failure.changedAssets?' assets='+JSON.stringify(failure.changedAssets):''));await page.screenshot({path:path.join(out,'failure-'+report.failures.length+'.png'),fullPage:false}).catch(()=>{});if(failure.changedAssets?.length){report.interrupted='Runtime asset manifest changed during browser run';const interruption=new Error(report.interrupted);interruption.environmentInterference=true;throw interruption;}}};
 const identity=async()=>assert.equal(await page.evaluate(()=>window.managerNavigationIdentity),marker,'Full document reload detected');
 const ready=async()=>{await page.waitForFunction(()=>!document.querySelector('main')?.hasAttribute('aria-busy'));assert.equal(await page.locator('[data-manager-navigation-error]').count(),0,'Navigation error banner');await identity();};
 const nav=async(route)=>{const url=new URL(route,base).href;assert.equal(new URL(url).origin,base.origin);const ok=await page.evaluate(async u=>Promise.race([window.KulturhusNavigation.navigate(u),new Promise(resolve=>setTimeout(()=>resolve(false),25000))]),url);assert(ok,'Navigation API failed: '+(await page.locator('[data-manager-navigation-error],dialog[open]').allTextContents()).join(' '));await ready();};
 const clickRoute=async(url)=>{
  const found=await page.evaluate(target=>{document.querySelectorAll('[data-manager-test-link]').forEach(n=>n.removeAttribute('data-manager-test-link'));const key=href=>{const u=new URL(href,location.href);u.hash='';u.searchParams.sort();return u.href;};for(const n of document.querySelectorAll('a[href]')){if(key(n.href)!==key(target))continue;for(let p=n.parentElement;p;p=p.parentElement)if(p.tagName==='DETAILS')p.open=true;if(!n.getClientRects().length||getComputedStyle(n).visibility==='hidden')continue;n.dataset.managerTestLink='1';return true;}return false;},url);
  if(!found){await nav(url);return 'api';}const match=page.locator('[data-manager-test-link]');
  // Module-owned controls may submit an equivalent GET form with optional empty fields.
  const response=page.waitForResponse(r=>responseKey(r.url())===responseKey(url)&&r.request().method()==='GET'&&r.headers()['content-type']?.includes('text/html'));
  const localChange=page.waitForFunction(target=>{const normalize=href=>{const u=new URL(href);u.hash='';for(const [name,value] of [...u.searchParams])if(value==='')u.searchParams.delete(name);u.searchParams.sort();return u.href;};return normalize(location.href)===normalize(target)&&!document.querySelector('main')?.hasAttribute('aria-busy');},url);
  await match.click();await Promise.race([response,localChange]);await page.waitForTimeout(40);await ready();return 'rendered-link';
 };
 try{
  const response=await page.goto(base.href,{waitUntil:'domcontentloaded'});assert.equal(response.headers()['x-kulturhus-local-project'],fixture.project_hash);assert.equal(response.headers()['x-kulturhus-local-app'],'vereinsverwaltung');
  await page.evaluate(value=>window.managerNavigationIdentity=value,marker);
  await run('Global navigation boots on the dashboard',async()=>assert.equal(await page.evaluate(()=>typeof window.KulturhusNavigation?.navigate),'function'));
  if(!report.failures.length&&process.env.KULTURHUS_NAVIGATION_FOCUS!=='functional'){
   const queue=[{url:new URL('?view=dashboard',base).href,source:base.href,label:'Dashboard'}],seen=new Set();
   while(queue.length){
    const item=queue.shift(),id=key(item.url);if(seen.has(id))continue;seen.add(id);assert(seen.size<260,'Unexpected menu route expansion');
    await run('Menu '+new URL(item.url).search,async()=>{
     const clicked=await clickRoute(item.url);const text=await page.locator('main').innerText();assert(text.trim().length>20);assert(!/Fatal error:|SQLSTATE\[|Stack trace:/.test(text));
     report.routes.push({...item,clicked,actual:page.url()});
     const links=await page.locator('#navigation a[href],main nav a[href]').evaluateAll(ns=>ns.map(n=>({url:n.href,label:n.textContent.trim().replace(/\s+/g,' ')})));
     for(const link of links){if(!eligible(link.url)){report.excluded.push(link);continue;}if(!seen.has(key(link.url)))queue.push({...link,source:page.url()});}
    });
    if(report.failures.length>8)break;
   }
  }
  if(process.env.KULTURHUS_NAVIGATION_FOCUS!=='crawl'){
   await run('Dirty form: cancel preserves values, discard switches without reload',async()=>{
    await nav('?view=documents&new_folder=1');const input=page.locator('form [name=name]');await input.fill('Ungespeicherter Navigationsordner');
    const leaving=page.evaluate(()=>window.KulturhusNavigation.navigate('?view=dashboard'));await page.locator('dialog.kh-dialog[open]').waitFor();await page.getByRole('button',{name:'Abbrechen',exact:true}).click();assert.equal(await leaving,false);assert.equal(await input.inputValue(),'Ungespeicherter Navigationsordner');
    const discard=page.evaluate(()=>window.KulturhusNavigation.navigate('?view=dashboard'));await page.getByRole('button',{name:'Änderungen verwerfen',exact:true}).click();assert.equal(await discard,true);await ready();
   });
   await run('Back/Forward and dirty history cancellation preserve document and current form',async()=>{
    await nav('?view=documents');await nav('?view=documents&new_folder=1');await page.locator('form [name=name]').fill('Zurück bleibt erhalten');
    await page.evaluate(()=>history.back());await page.getByRole('button',{name:'Abbrechen',exact:true}).click();await page.waitForFunction(()=>new URL(location.href).searchParams.has('new_folder'));assert.equal(await page.locator('form [name=name]').inputValue(),'Zurück bleibt erhalten');
    await page.evaluate(()=>history.back());await page.getByRole('button',{name:'Änderungen verwerfen',exact:true}).click();await page.waitForFunction(()=>!new URL(location.href).searchParams.has('new_folder'));await ready();
    await page.evaluate(()=>history.forward());await page.waitForFunction(()=>new URL(location.href).searchParams.has('new_folder'));await page.locator('form [name=name]').waitFor();await ready();
   });
   await run('Structural form changes without input/change events are protected',async()=>{
    await nav('?view=documents&new_folder=1');await page.locator('form [name=scope]').evaluate(field=>field.remove());assert(await page.evaluate(()=>window.KulturhusNavigation.isDirty()));
    const leaving=page.evaluate(()=>window.KulturhusNavigation.navigate('?view=dashboard'));await page.getByRole('button',{name:'Abbrechen',exact:true}).click();assert.equal(await leaving,false);assert(new URL(page.url()).searchParams.has('new_folder'));
    const discard=page.evaluate(()=>window.KulturhusNavigation.navigate('?view=dashboard'));await page.getByRole('button',{name:'Änderungen verwerfen',exact:true}).click();assert.equal(await discard,true);await ready();
   });
   await run('Late navigation response cannot replace inputs edited while loading',async()=>{
    await nav('?view=documents&new_folder=1');const original=page.url(),target=new URL('?view=calendar',base).href;let arrived,release;const reached=new Promise(r=>arrived=r),gate=new Promise(r=>release=r);
    const intercept=async route=>{const response=await route.fetch();arrived();await gate;await route.fulfill({response}).catch(()=>{});};await context.route(target,intercept,{times:1});
    try{const pending=page.evaluate(url=>window.KulturhusNavigation.navigate(url),target);await reached;await page.locator('form [name=name]').fill('Eingabe während Abruf');release();assert.equal(await pending,false);assert.equal(page.url(),original);assert.equal(await page.locator('form [name=name]').inputValue(),'Eingabe während Abruf');await identity();}
    finally{release?.();await context.unroute(target,intercept);await page.evaluate(()=>document.querySelectorAll('main form').forEach(f=>window.KulturhusNavigation.markClean(f)));}
   });
   await run('Failed response retains current URL and complete form values',async()=>{
    await nav('?view=documents&new_folder=1');const original=page.url(),target=new URL('?view=calendar',base).href;
    await context.route(target,route=>route.fulfill({status:503,contentType:'text/html',body:'Synthetic navigation availability failure'}),{times:1});
    assert.equal(await page.evaluate(url=>window.KulturhusNavigation.navigate(url),target),false);assert.equal(page.url(),original);assert.equal(await page.locator('form [name=name]').count(),1);assert.equal(await page.locator('[data-manager-navigation-error]').count(),1);await identity();await nav('?view=dashboard');
   });
   await run('Hash and document archive state survive partial navigation; binary endpoints stay native',async()=>{
    await nav('?view=finance&tab=help#hilfe-export');assert.equal(new URL(page.url()).hash,'#hilfe-export');await nav('?view=documents&state=archived');assert.equal(new URL(page.url()).searchParams.get('state'),'archived');
    assert.equal(await page.evaluate(()=>window.KulturhusNavigation.eligible(new URL('?balance_pdf=1',location.href))),false);assert.equal(await page.evaluate(()=>window.KulturhusNavigation.eligible(new URL('?voucher_pdf=test',location.href))),false);await identity();
   });
   await run('Unregistered fetched assets are rejected without a request or page replacement',async()=>{
    await nav('?view=dashboard');const original=page.url(),target=new URL('?view=calendar',base).href;let requested=false;
    const watch=r=>{if(r.url().includes('unregistered-navigation-test.js'))requested=true;};page.on('request',watch);
    await context.route(target,async route=>{const response=await route.fetch();await route.fulfill({response,body:(await response.text()).replace('</head>','<script src="unregistered-navigation-test.js"></script></head>')});},{times:1});
    try{assert.equal(await page.evaluate(url=>window.KulturhusNavigation.navigate(url),target),false);assert.equal(page.url(),original);assert.equal(requested,false);await identity();}finally{page.off('request',watch);}
    await nav('?view=dashboard');
   });
   await run('Fetched template content strips scripts, handlers and javascript URLs before cloning',async()=>{
    const target=new URL('?view=calendar',base).href;
    await context.route(target,async route=>{const response=await route.fetch();const test='<template id="navigation-sanitizer-test"><script>window.navigationUnsafe=true</script><button onclick="window.navigationUnsafe=true">Unsafe action</button><a href="javascript:window.navigationUnsafe=true">Unsafe URL</a></template>';await route.fulfill({response,body:(await response.text()).replace('</main>',test+'</main>')});},{times:1});
    await nav(target);const clean=await page.locator('#navigation-sanitizer-test').evaluate(t=>({scripts:t.content.querySelectorAll('script').length,handlers:t.content.querySelectorAll('[onclick]').length,urls:[...t.content.querySelectorAll('[href]')].map(a=>a.getAttribute('href'))}));assert.equal(clean.scripts,0);assert.equal(clean.handlers,0);assert(!clean.urls.some(u=>u.startsWith('javascript:')));await page.locator('#navigation-sanitizer-test').evaluate(t=>t.after(t.content.cloneNode(true)));assert.equal(await page.evaluate(()=>window.navigationUnsafe),undefined);await identity();
   });
   await run('Live document search works after leaving and returning',async()=>{
    await nav('?view=documents');await nav('?view=dashboard');await nav('?view=documents');await page.locator('.doc-filter input[name=q]').fill('Raummiete');
    await page.waitForFunction(()=>new URL(location.href).searchParams.get('q')==='Raummiete');await ready();assert.match(await page.locator('.doc-table').innerText(),/Raummiete/);assert(!/Bühnenpodest/.test(await page.locator('.doc-table').innerText()));
   });
   await run('Document list table and local filter initialize after revisit',async()=>{
    await nav('?view=documents&doc_mode=sources');const input=page.locator('.simple-list-tools input[type=search]');assert.equal(await input.count(),1);await input.fill('zz-no-synthetic-match');assert.equal(await page.locator('[data-simple-row]:visible').count(),0);await nav('?view=dashboard');await nav('?view=documents&doc_mode=sources');assert.equal(await page.locator('.simple-list-tools input[type=search]').count(),1);
   });
   await run('Financial and tax form widgets initialize after cross-module navigation',async()=>{
    await nav('?view=finance&tab=receipts&new_entry=1&kind=expense');const financial=page.locator('form').filter({has:page.locator('[name=action][value=create-entry]')});assert.equal(await financial.count(),1,'Expense form');assert.equal(await financial.getAttribute('data-finance-preferences-ready'),'1','Finance preferences mounted');
    await page.waitForTimeout(60);assert.equal(await page.evaluate(()=>window.KulturhusNavigation.isDirty()),false,'Empty file input must not dirty an untouched form');
    await financial.locator('[data-reimbursement-source]').selectOption('private_advance');assert.equal(await financial.locator('[data-reimbursement-panel]').isVisible(),true,'Private payment panel');await financial.locator('[data-reimbursement-source]').selectOption('none');assert.equal(await financial.locator('[data-reimbursement-panel]').isVisible(),false,'Private payment panel closes');
    assert.equal(await financial.locator('[name=description]').evaluate(f=>f.reportValidity()),false,'Required description validation');await financial.locator('.field-validation-error').first().waitFor();await financial.locator('[name=description]').fill('Ungespeicherte Testausgabe');
    const leaving=page.evaluate(()=>window.KulturhusNavigation.navigate('?view=settings&section=tax'));await page.getByRole('button',{name:'Änderungen verwerfen',exact:true}).click();assert.equal(await leaving,true,'Dirty financial form can be discarded');await ready();assert.equal(await page.locator('main form[method=post]').count()>0,true,'Tax form exists');assert.equal(await page.evaluate(()=>typeof window.KulturhusFormValidation?.validate),'function');await nav('?view=finance&tab=inbox');assert.equal(await page.locator('.live-upload').count()>0,true,'Inbox uploader initialized');
   });
   await run('Document upload initializes again and stages a real synthetic file',async()=>{
    await nav('?view=documents&new_document=1');await page.waitForTimeout(60);assert.equal(await page.evaluate(()=>window.KulturhusNavigation.isDirty()),false,'Empty document uploader remains clean');await nav('?view=dashboard');await nav('?view=documents&new_document=1');assert.equal(await page.locator('.live-upload').count(),1);await page.locator('input[name="document_files[]"]').setInputFiles({name:'navigation-synthetic.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic local navigation upload. No personal data.')});
    await page.locator('.upload-ready').waitFor();assert.match(await page.locator('.upload-ready').innerText(),/Hochgeladen/);await page.locator('.live-upload-list').getByRole('button',{name:'Entfernen',exact:true}).click();await page.waitForFunction(()=>document.querySelectorAll('.live-upload-list li').length===0);await page.evaluate(()=>document.querySelectorAll('main form').forEach(f=>window.KulturhusNavigation.markClean(f)));
   });
   await run('Voucher scanner initializes after revisit; unsupported camera has a controlled message',async()=>{
    const route='?view=portal&tab=vouchers&voucher_event='+fixture.voucher_event;await nav(route);await nav('?view=dashboard');await nav(route);const scanner=page.locator('[data-voucher-search-form]');assert.equal(await scanner.count(),1);await scanner.locator('[data-voucher-scan]').click();await page.waitForFunction(()=>/Kamera.*(?:nicht|eingeben)|Kamerazugriff nicht/.test(document.querySelector('[data-voucher-scan-status]')?.textContent||''));await identity();
   });
   await run('Chat compose and manual refresh work after leaving and returning',async()=>{
    await nav('?view=chat&chat='+fixture.chat);await nav('?view=dashboard');await nav('?view=chat&chat='+fixture.chat);assert.equal(await page.locator('[data-chat-compose]').count(),1);await page.locator('[data-chat-refresh]').click();await page.waitForFunction(()=>document.querySelector('#chat-notification')?.textContent.includes('aktuell'));await identity();
   });
   await run('Mobile menu navigation preserves the document and fits the viewport',async()=>{await nav('?view=documents');await page.screenshot({path:path.join(out,'navigation-desktop.png'),fullPage:true});await page.setViewportSize({width:390,height:844});await nav('?view=dashboard');await nav('?view=documents');assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));await page.screenshot({path:path.join(out,'navigation-mobile.png'),fullPage:true});});
   await run('Server denies member access to finance and preserves the current partial page',async()=>{
    const memberContext=await browser.newContext({serviceWorkers:'block'});await memberContext.route('**/*',route=>new URL(route.request().url()).origin===base.origin?route.continue():route.abort());const memberPage=await memberContext.newPage();
    try{await memberPage.goto(base.href);const csrf=await memberPage.locator('form').filter({has:memberPage.locator('[name=action][value=persona]')}).locator('[name=csrf]').inputValue();const switched=await memberContext.request.post(base.href,{form:{action:'persona',actor:'demo-member',csrf}});assert.equal(switched.status(),200);await memberPage.goto(new URL('?view=dashboard',base).href);const original=memberPage.url(),main=await memberPage.locator('main').innerText();await memberPage.evaluate(()=>window.memberNavigationIdentity='member-test');const response=memberPage.waitForResponse(r=>new URL(r.url()).searchParams.get('view')==='finance');assert.equal(await memberPage.evaluate(()=>window.KulturhusNavigation.navigate('?view=finance&tab=bank')),false);assert.equal((await response).status(),403);assert.equal(memberPage.url(),original);assert((await memberPage.locator('main').innerText()).includes(main));assert.equal(await memberPage.evaluate(()=>window.memberNavigationIdentity),'member-test');}
    finally{await memberContext.close();}
   });
  }
  await run('No script exceptions, lifecycle initialization errors or full navigation after initial load',async()=>{report.moduleErrors=await page.evaluate(()=>window.managerModuleErrors);assert.deepEqual(report.errors,[]);assert.deepEqual(report.moduleErrors,[]);assert.equal(documentRequests,1);});
  report.after=readback();await run('No outgoing mail and no unexpected business writes',async()=>{assert.equal(report.after.club_outbox,0);assert.equal(report.after.club_chat_messages,before.club_chat_messages);assert.equal(report.after.documents,before.documents);});
 }finally{report.documentRequests=documentRequests;saveReport();await browser.close();}
 console.log(JSON.stringify({passed:report.checks.length,failed:report.failures.length,routes:report.routes.length,externalBlocked:report.externalBlocked.length}));if(report.failures.length)process.exitCode=1;
})().catch(e=>{if(!e.environmentInterference)report.failures.push({name:'Fatal harness error',error:e.message});saveReport();console.error(e.message);process.exitCode=1;});
