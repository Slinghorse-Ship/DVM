import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {execFileSync} from 'node:child_process';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE),root='/workspace/Kulturhus/',origin='http://127.0.0.1:8111';
const fixture=JSON.parse(await readFile(root+'.local/browser-tests/inbox-history.json','utf8')),storage=root+'.local/browser-tests/controls-sessions/admin.json';
const browser=await chromium.launch({executablePath:'/usr/bin/chromium',args:['--no-sandbox']});let checks=0,writes=0,external=0;const errors=[];
const check=(ok,label)=>{assert(ok,label);++checks;console.log('PASS '+label);};
const url=(f,args={})=>origin+'/vereinsverwaltung/?'+new URLSearchParams({view:'social',section:'inbox',thread:f.thread,q:fixture.name,inbox_channel:f.channel||'all',...args});
const fits=page=>page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1);
try{
 for(const javaScriptEnabled of [true,false]){
  const context=await browser.newContext({javaScriptEnabled,storageState:storage,hasTouch:true,isMobile:true,viewport:{width:390,height:844}});
  await context.route('**/*',r=>{const req=r.request();if(new URL(req.url()).origin!==origin){++external;return r.abort();}if(!['GET','HEAD'].includes(req.method())){++writes;return r.abort();}return r.continue();});
  const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
  for(const width of [1440,820,390,320]){
   await page.setViewportSize({width,height:844});
   for(const[channel,f]of Object.entries(fixture.scenarios)){
    const base={...f,channel};await page.goto(url(base));check(await page.locator('.chat-person a').filter({hasText:'Frühere Fassungen'}).count()===1,'current private chat exposes retained history '+channel+' '+width+' JS='+javaScriptEnabled);
    await Promise.all([page.waitForURL('**inbox_history=1**'),page.locator('.chat-person a').filter({hasText:'Frühere Fassungen'}).tap()]);
    check(await page.locator('[data-inbox-history]').count()===1&&(await page.locator('.chat-messages .social-caption').allTextContents())[0]===f.latest_text,'actual history link opens exact current-account previous original '+channel+' '+width);
    check(await page.locator('.social-chat-thread textarea,.social-chat-thread form[method=post],.social-chat-thread input[name=operation]').count()===0,'historical chat has no send, mail, draft or provider action '+channel+' '+width);
    check((await page.locator('[data-inbox-history] .chat-platform').innerText())===(channel==='facebook'?'Facebook':'Instagram')&&(await page.locator('[data-inbox-history] .chat-kind').innerText())==='Nachricht','past version retains clear platform and message identity '+channel+' '+width);
    await page.locator('.chat-plan-menu summary').click();check(await page.locator('.chat-history-choices a').count()===25&&await fits(page),'history choices stay bounded and fit '+channel+' '+width);
    check(await page.locator('.chat-history-choices a').evaluateAll(nodes=>nodes.every(n=>{const r=n.getBoundingClientRect();return r.height>=44&&r.width>=44;})),'native historical touch targets '+channel+' '+width);
    await page.goto(url(base,{inbox_history:'1',inbox_history_page:'3',inbox_version:f.earliest_id}));check((await page.locator('.chat-messages .social-caption').allTextContents())[0]===f.earliest_text,'explicit earliest original remains exact beyond 25/50 versions '+channel+' '+width);
    await page.goto(url(base,{inbox_history:'1',inbox_history_kind:'connections',inbox_connection:f.connection}));check(JSON.stringify(await page.locator('.chat-messages .social-caption').allTextContents())===JSON.stringify(f.messages),'actual previous account selects only its exact native/mail timeline page '+channel+' '+width);
    check(await page.locator('.chat-messages .chat-bubble').count()<=40&&await fits(page),'previous account timeline is bounded and fits '+channel+' '+width);
    await page.goto(url(base,{inbox_history:'1',inbox_connection:f.connection,inbox_version:f.older_version}));check((await page.locator('.chat-messages .social-caption').allTextContents())[0]===f.older_text,'earlier account versions retain their exact scoped bytes '+channel+' '+width);
   }
  }
  await page.setViewportSize({width:390,height:844});const f={...fixture.scenarios.facebook,channel:'facebook'};
  await page.goto(url(f,{inbox_history:'1',inbox_history_kind:'connections',inbox_connection:f.connection}));let native=0,mail=0;
  for(let number=1;number<=4;number++){
   const texts=await page.locator('.chat-messages .social-caption').allTextContents();native+=texts.filter(t=>!t.startsWith('Eigene Mailantwort')).length;mail+=texts.filter(t=>t.startsWith('Eigene Mailantwort')).length;
   if(number<4){const older=page.locator('.chat-messages a').filter({hasText:'Ältere Nachrichten'});await older.focus();await Promise.all([page.waitForURL(u=>u.searchParams.get('message_page')===String(number+1)),older.press('Enter')]);}
  }
  check(native===85&&mail===70,'keyboard traverses every exact original previous account message and bound human mail JS='+javaScriptEnabled);
  const newer=page.locator('.chat-messages a').filter({hasText:'Neuere Nachrichten'});await Promise.all([page.waitForURL(u=>u.searchParams.get('message_page')==='3'),newer.tap()]);check(await page.locator('.chat-messages .chat-bubble').count()===40,'touch traverses actual previous original timeline JS='+javaScriptEnabled);
  await page.goto(url(f,{inbox_history:'1',inbox_history_kind:'connections',inbox_history_page:'2'}));check((await page.locator('.chat-messages .social-caption').allTextContents()).length===1&&!((await page.locator('.chat-messages').innerText()).includes('Eigene Mailantwort')),'different earlier connection never borrows another account mail JS='+javaScriptEnabled);
  if(javaScriptEnabled){await page.evaluate(()=>document.documentElement.style.zoom='2');check(await fits(page),'previous account fits at 200 percent zoom');await page.evaluate(()=>document.documentElement.style.zoom='');}
  const back=page.locator('.social-chat-thread a').filter({hasText:'Zum aktuellen Chat'});await back.focus();await Promise.all([page.waitForURL(u=>!u.searchParams.has('inbox_history')),back.press('Enter')]);check(await page.locator('[data-inbox-history]').count()===0&&await page.locator('.chat-person a').filter({hasText:'Frühere Fassungen'}).count()===1,'explicit keyboard return keeps real current chat JS='+javaScriptEnabled);
  await context.close();
 }
 check(!writes&&!external&&!errors.length,'history browsing makes no business writes, external calls or script errors');
 const proof=execFileSync(process.env.PHP_CLI_BINARY||'php',[root+'vereinsverwaltung/tests/support/inbox-history-browser-fixture.php','check'],{encoding:'utf8',env:{...process.env,KH_NATIVE_TEST_CONFIG:root+'.local/native-cms-integration/integrated-manager.json'}});check(proof.includes('All exact native original bytes retained'),'every exact original account, message, mail, document and job remains unchanged');console.log(JSON.stringify({checks,business_writes:writes,external_actions:external}));
}finally{await browser.close();}
