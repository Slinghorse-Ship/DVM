'use strict';
// Own isolated fixture only. No permissions, subscription, worker, push or mail delivery.
const {chromium}=require('playwright'),fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),{spawnSync}=require('node:child_process');
(async()=>{
 const file=process.env.KULTURHUS_MEMBER_PROFILE_FIXTURE,php=process.env.KULTURHUS_TEST_PHP;assert(file&&php);
 const fixture=JSON.parse(fs.readFileSync(file,'utf8').replace(/^\uFEFF/,'')),base=new URL(fixture.base);
 assert(['127.0.0.1','localhost'].includes(base.hostname)&&base.port==='8091'&&fixture.club.startsWith('member-profile-'));
 const read=()=>{const r=spawnSync(php,[path.join(__dirname,'support/notification-settings-readback.php'),file],{encoding:'utf8',env:process.env});assert.equal(r.status,0,r.stderr+r.stdout);return JSON.parse(r.stdout);};
 const before=read();assert.deepEqual(before.admin.selected,['chat']);assert.deepEqual(before.own.selected,['chat']);
 const browser=await chromium.launch({channel:'msedge',headless:true});let n=0;
 const ck=(ok,label)=>{assert(ok,label);n++;console.log('PASS '+label);};
 try{
  const context=await browser.newContext({viewport:{width:1280,height:900},serviceWorkers:'block'});
  await context.addInitScript(()=>{Object.defineProperty(window,'PushManager',{value:undefined,configurable:true});});
  await context.route('**/*',r=>{
   const req=r.request(),u=new URL(req.url());
   if(u.origin!==base.origin)return r.abort();
   if(req.method()!=='GET'&&!(req.method()==='POST'&&new URLSearchParams(req.postData()||'').get('action')==='settings-notifications'))return r.abort();
   return r.continue();
  });
  await context.addCookies([{name:'KH_VEREIN_DEMO',value:fixture.sessions.admin,url:base.origin+'/'}]);
  const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  const route=base.origin+'/?view=settings&tab=account#notifications';const response=await page.goto(route);
  assert.equal(response.headers()['x-kulturhus-local-project'],fixture.project_hash,'correct checkout required');
  const form=page.locator('form[data-notification-settings]');
  ck(await page.locator('details[data-account-panel=notifications]').evaluate(e=>e.open),'notification anchor opens its account section');
  ck(await form.locator('input[name="topics[]"]:checked').count()===1&&await form.locator('input[value=chat]').isChecked(),'chat enabled by default; other notification categories initially off');
  ck(await page.locator('#push-settings').count()===1,'one device-activation panel without duplicate IDs');
  const available=Object.keys(before.admin.available);assert(available.length>0);
  const legacy=await context.request.post(route,{form:{action:'settings-notifications',csrf:await form.locator('[name=csrf]').inputValue(),version:await form.locator('[name=version]').inputValue(),'topics[]':'events'}});
  assert.equal(legacy.status(),200);assert.deepEqual([...read().admin.selected].sort(),['chat','events']);
  ck(true,'older open form without chat marker preserves enabled chat when saving another topic');
  await page.reload();

  for(const topic of available)await form.locator(`input[value="${topic}"]`).check();
  const save=()=>Promise.all([page.waitForNavigation(),form.getByRole('button',{name:'Benachrichtigungen speichern'}).click()]);
  await save();ck(page.url().endsWith('#notifications'),'save returns directly to notification card');
  assert.deepEqual([...read().admin.selected].sort(),available.sort());ck(true,'all selected topics really persisted');
  assert.deepEqual(read().own,before.own);ck(true,'another member remains unchanged');
  await page.reload();ck(await form.locator('input[name="topics[]"]:checked').count()===available.length,'reload restores actual selected topics');
  await form.locator('input[name="topics[]"]').first().uncheck();await form.locator('[name=version]').evaluate(e=>e.value='0');
  const stable=read();await save();ck(await page.locator('.error[role=alert]').count()===1,'stale form fails visibly');assert.deepEqual(read(),stable);ck(true,'stale save changes no preference');
  ck(await form.locator('input[name="topics[]"]:checked').count()===available.length-1,'failed save retains edited checkbox state');
  await page.goto('about:blank');await page.goto(route);for(const input of await form.locator('input[name="topics[]"]').all())await input.uncheck();await save();assert.deepEqual(read().admin.selected,[]);ck(true,'unchecking every topic really disables all new categories');
  const csrf=await form.locator('[name=csrf]').inputValue();const version=await form.locator('[name=version]').inputValue();
  const denied=await context.request.post(route,{form:{action:'settings-notifications',csrf:'wrong',version,'topics[]':available[0]}});ck(denied.status()===403,'missing or invalid CSRF is rejected');
  assert.deepEqual(read().admin.selected,[]);
  const own=await browser.newContext({viewport:{width:390,height:844},serviceWorkers:'block'});
  await own.addInitScript(()=>{Object.defineProperty(window,'PushManager',{value:undefined,configurable:true});});
  await own.route('**/*',r=>new URL(r.request().url()).origin===base.origin?r.continue():r.abort());
  await own.addCookies([{name:'KH_VEREIN_DEMO',value:fixture.sessions.own,url:base.origin+'/'}]);
  const op=await own.newPage();await op.goto(route);const of=op.locator('form[data-notification-settings]');
  ck(await of.locator('input[value=invoices],input[value=payments]').count()===0,'ordinary member sees no finance notification topics');
  for(const topic of ['documents','contributions','minutes'])await of.locator(`input[value="${topic}"]`).check();
  await Promise.all([op.waitForNavigation(),of.getByRole('button',{name:'Benachrichtigungen speichern'}).click()]);
  assert.deepEqual([...read().own.selected].sort(),['chat','contributions','documents','minutes']);ck(true,'member chat, own documents, contribution and minutes topics persist');
  const ownBefore=read();const forbidden=await own.request.post(route,{form:{action:'settings-notifications',csrf:await of.locator('[name=csrf]').inputValue(),version:await of.locator('[name=version]').inputValue(),'topics[]':'invoices'}});
  ck((await forbidden.text()).includes('role="alert"'),'forged finance opt-in is visibly rejected');assert.deepEqual(read(),ownBefore);ck(true,'forged topic changes neither member nor administrator settings');await own.close();
  await page.setViewportSize({width:390,height:844});await page.goto(route);ck(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'390px account page has no horizontal overflow');
  await page.locator('#notifications').screenshot({path:path.join(path.dirname(file),'notifications-390.png')});assert.deepEqual(errors,[]);ck(true,'no page JavaScript errors or external delivery');
  const pushContext=await browser.newContext({viewport:{width:390,height:844},serviceWorkers:'block'});
  const publicKey=Buffer.concat([Buffer.from([4]),Buffer.alloc(64,7)]).toString('base64url'),auth=Buffer.alloc(16,8).toString('base64url');
  await pushContext.addCookies([{name:'KH_VEREIN_DEMO',value:fixture.sessions.admin,url:base.origin+'/'}]);
  await pushContext.addInitScript(({key,auth,endpoint})=>{
   let subscription=null;window.__syntheticPushCalls=0;
   Object.defineProperty(window,'Notification',{configurable:true,value:{permission:'default',requestPermission:async()=>{window.__syntheticPushCalls++;Notification.permission='granted';return 'granted';}}});
   Object.defineProperty(window,'PushManager',{configurable:true,value:function(){}});
   const registration={scope:location.origin+'/',active:{state:'activated'},pushManager:{getSubscription:async()=>subscription,subscribe:async()=>{window.__syntheticPushCalls++;subscription={toJSON:()=>({endpoint,keys:{p256dh:key,auth}}),unsubscribe:async()=>true};return subscription;}}};
   Object.defineProperty(navigator,'serviceWorker',{configurable:true,value:{register:async()=>registration,getRegistration:async()=>registration}});
  },{key:publicKey,auth,endpoint:'https://fcm.googleapis.com/fcm/send/'+fixture.club});
  await pushContext.route('**/*',async r=>{
   const req=r.request(),u=new URL(req.url());if(u.origin!==base.origin)return r.abort();
   if(req.method()==='POST'&&!['push-save','push-remove-all','settings-notifications'].includes(new URLSearchParams(req.postData()||'').get('action')))return r.abort();
   if(req.method()==='GET'&&u.searchParams.get('view')==='settings'){
    const response=await r.fetch();assert.equal(response.headers()['x-kulturhus-local-project'],fixture.project_hash);
    return r.fulfill({response,body:(await response.text()).replace('data-key=""','data-key="'+publicKey+'"')});
   }return r.continue();
  });
  const pp=await pushContext.newPage();await pp.goto(route);
  ck(await pp.locator('#push-settings').getAttribute('data-context')==='account','actual account panel has account context');
  ck(await pp.evaluate(()=>window.__syntheticPushCalls)===0,'opening account requests no permission or subscription');
  await pp.locator('[data-push-enable]').click();await pp.waitForFunction(()=>document.querySelector('[data-push-status]').textContent.includes('ausgewählten Themen'));
  ck((await pp.locator('[data-push-status]').innerText()).includes('ausgewählten Themen'),'activation success describes selected account topics');
  ck(read().subscriptions===1,'synthetic subscription stored through real local endpoint');
  const pf=pp.locator('form[data-notification-settings]');
  for(const topic of ['chat','events'])await pf.locator(`input[value="${topic}"]`).check();
  const psave=()=>Promise.all([pp.waitForNavigation(),pf.getByRole('button',{name:'Benachrichtigungen speichern'}).click()]);
  await psave();const deviceStable=read();
  await pf.locator('input[value=chat]').uncheck();await psave();await pp.reload();
  assert.deepEqual(read().admin.selected,['events']);
  ck(!await pf.locator('input[value=chat]').isChecked()&&await pf.locator('input[value=events]').isChecked(),'chat off survives save and reload while another topic stays enabled');
  assert.equal(read().subscriptions,deviceStable.subscriptions);assert.deepEqual(read().own,deviceStable.own);
  ck(true,'chat opt-out preserves registered device and another member preferences');
  await pf.locator('input[value=chat]').check();await psave();await pp.reload();
  assert.deepEqual([...read().admin.selected].sort(),['chat','events']);
  ck(await pf.locator('input[value=chat]').isChecked(),'chat enabled again survives reload');
  assert.equal(read().subscriptions,deviceStable.subscriptions);
  ck(await pp.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'390px chat preference roundtrip has no horizontal overflow');

  const removal=pp.locator('form').filter({has:pp.locator('[name=action][value=push-remove-all]')});
  await pp.getByText('Weitere Hinweise und alle Geräte abmelden',{exact:true}).click();
  ck(await removal.isVisible(),'device removal disclosure opens with one click');
  await Promise.all([pp.waitForNavigation(),removal.getByRole('button',{name:'Alle meine Push-Geräte abmelden'}).click()]);
  ck(pp.url().endsWith('?view=settings&tab=account#notifications'),'all-device removal returns to account notifications');
  ck(read().subscriptions===0,'all-device removal actually clears own synthetic subscription');
  await pushContext.close();
  console.log(JSON.stringify({checks:n,scope:'synthetic own preferences; mocked browser APIs, local subscription records only, no provider/push/mail'}));
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
