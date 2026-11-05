'use strict';
// Genuine isolated profile POST/GET/readback; sidebar uses its actual PHP partial.
// Never contacts ProcessWire, mail, push or another origin.
const {chromium}=require('playwright'),fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),{spawnSync}=require('node:child_process');
(async()=>{
 const file=process.env.KULTURHUS_MEMBER_PROFILE_FIXTURE,php=process.env.KULTURHUS_TEST_PHP;assert(file&&php);
 const fixture=JSON.parse(fs.readFileSync(file,'utf8').replace(/^\uFEFF/,'')),base=new URL(fixture.base);
 assert(['127.0.0.1','localhost'].includes(base.hostname)&&base.port==='8091'&&fixture.club.startsWith('member-profile-'));
 const cli=(name,...args)=>{const r=spawnSync(php,[path.join(__dirname,'support',name),...args],{encoding:'utf8',env:process.env});assert.equal(r.status,0,r.stderr+r.stdout);return r.stdout;};
 const read=()=>JSON.parse(cli('account-settings-readback.php',file));const original=read();
 const sidebar=cli('sidebar-account-fixture.php');assert(!cli('sidebar-account-fixture.php','no-logout').includes('logout=1'));
 const browser=await chromium.launch({channel:'msedge',headless:true});let checks=0;const ck=(ok,label)=>{assert(ok,label);console.log('PASS '+(++checks)+' '+label);};
 try{
  const context=await browser.newContext({viewport:{width:1360,height:940},serviceWorkers:'block'});
  await context.addInitScript(()=>Object.defineProperty(window,'PushManager',{value:undefined,configurable:true}));
  await context.route('**/*',r=>{const request=r.request();if(new URL(request.url()).origin!==base.origin)return r.abort();if(request.method()!=='GET'&&!(request.method()==='POST'&&['settings-save','settings-signature'].includes(new URLSearchParams(request.postData()||'').get('action'))))return r.abort();return r.continue();});
  await context.addCookies([{name:'KH_VEREIN_DEMO',value:fixture.sessions.admin,url:base.origin+'/'}]);
  const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  const route=base.origin+'/?view=settings&tab=account';let response=await page.goto(route);
  assert.equal(response.headers()['x-kulturhus-local-project'],fixture.project_hash);
  ck(await page.locator('[data-account-panel]').count()===4&&await page.locator('[data-account-panel][open]').count()===0,'four closed, clearly named sections on initial entry');
  ck(await page.locator('form form').count()===0,'independent forms are never nested');
  const profile=page.locator('[data-account-panel=profile]'),form=page.locator('[data-account-profile]');
  await profile.locator('summary').click();
  ck(await form.locator('[name^="data["]').count()===4&&await form.getByRole('button').count()===1,'all four original settings and one explicit save action remain');
  await form.locator('[name="data[display_name]"]').fill('Alex Muster Synthetic');
  await form.locator('[name="data[reply_email]"]').fill('alex@example.invalid');
  await form.locator('[name="data[start_view]"]').selectOption('calendar');
  await form.locator('[name="data[page_size]"]').selectOption('40');
  await Promise.all([page.waitForNavigation(),form.getByRole('button',{name:'Anzeigeeinstellungen speichern'}).click()]);
  const saved=read();assert.deepEqual(saved.admin.data,{display_name:'Alex Muster Synthetic',reply_email:'alex@example.invalid',start_view:'calendar',page_size:'40'});
  ck(true,'profile changes really persist through HTTP, service and database');
  ck(await profile.getAttribute('open')!==null&&await profile.locator('summary').innerText().then(t=>t.includes('Kalender')&&t.includes('40')),'saved section stays open and summary reflects stored values');
  assert.deepEqual(saved.own,original.own);ck(true,'another member is unchanged');
  await page.reload();ck(await form.locator('[name="data[reply_email]"]').inputValue()==='alex@example.invalid','reload reads the stored setting');
  await profile.locator('summary').click();await form.locator('[name="data[display_name]"]').fill('Synthetic unsaved draft');await form.locator('[name=version]').evaluate(e=>e.value='0');
  await Promise.all([page.waitForNavigation(),form.getByRole('button').click()]);
  ck(await page.locator('.error[role=alert]').count()===1&&await profile.getAttribute('open')!==null,'stale save fails visibly with its section open');
  ck(await form.locator('[name="data[display_name]"]').inputValue()==='Synthetic unsaved draft','failed save retains the draft');assert.deepEqual(read(),saved);ck(true,'stale save writes no setting');
  await page.goto(route);await profile.locator('summary').click();await form.locator('[name="data[reply_email]"]').fill('not-an-email');await profile.locator('summary').click();
  await form.evaluate(f=>f.requestSubmit());await page.waitForFunction(()=>document.activeElement?.getAttribute('name')==='data[reply_email]');
  ck(await profile.getAttribute('open')!==null&&await form.locator('[name="data[reply_email]"]').getAttribute('aria-invalid')==='true','invalid field in closed section opens, marks red and receives focus');assert.deepEqual(read(),saved);
  for(const [hash,panel] of [['account-profile','profile'],['device-session','security'],['notifications','notifications'],['push-settings','notifications'],['vorstands-unterschrift','signature']]){
   await page.goto(route+'#'+hash);ck(await page.locator('[data-account-panel='+panel+']').getAttribute('open')!==null&&await page.locator('#'+hash).isVisible(),'direct #'+hash+' opens its section');
  }
  await page.goto(route+'#vorstands-unterschrift');const signature=page.locator('[data-board-signature]');
  response=await context.request.post(route,{form:{action:'settings-signature',csrf:await signature.locator('[name=csrf]').inputValue(),version:'0'}});
  const failedHtml=await response.text();ck(failedHtml.includes('data-account-panel="signature" open')&&failedHtml.includes('role="alert"'),'signature POST error leaves the authorized section open');
  await page.goto(route);await page.screenshot({path:path.join(path.dirname(file),'account-overview-desktop.png'),fullPage:true});
  await page.setViewportSize({width:390,height:844});await page.goto(route);
  ck(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'390px closed overview has no horizontal overflow');
  ck(await page.locator('[data-account-panel]>summary').evaluateAll(nodes=>nodes.every(n=>n.getBoundingClientRect().height>=48)),'all section headers have generous touch targets');
  await page.screenshot({path:path.join(path.dirname(file),'account-overview-390.png'),fullPage:true});
  await page.goto(route+'#account-profile');ck(await form.locator('input:not([type=hidden]),select,button').evaluateAll(nodes=>nodes.every(n=>n.getBoundingClientRect().height>=48)),'390px profile controls are at least 48px high');
  await page.screenshot({path:path.join(path.dirname(file),'account-profile-390.png'),fullPage:true});
  // Exact production partial; only the local DOM is changed. Delegated real app.js stays active.
  const injectSidebar=async()=>{await page.locator('.demo-access').evaluate((node,html)=>node.outerHTML=html,sidebar);};
  await page.setViewportSize({width:1360,height:940});await page.goto(route);await injectSidebar();
  const menu=page.locator('[data-sidebar-account]'),summary=menu.locator('summary');
  await summary.focus();await page.keyboard.press('Enter');ck(await menu.getAttribute('open')!==null,'keyboard opens account menu');
  ck(await menu.getByRole('link',{name:'Abmelden',exact:true}).getAttribute('href')==='/login/?logout=1','existing logout target stays unchanged');
  await page.locator('[data-collapse]').click();await summary.focus();await page.keyboard.press('Enter');
  const box=await menu.locator('.subnav').boundingBox();ck(box&&box.x>=80&&box.y>=0&&box.x+box.width<=1360,'collapsed icon opens a visible account flyout');
  await page.keyboard.press('Escape');ck(await menu.getAttribute('open')===null&&await summary.evaluate(n=>n===document.activeElement),'Escape closes flyout and restores focus');
  await summary.click();await menu.getByRole('link',{name:'Account-Einstellungen'}).click();ck(page.url()===route,'account link really opens its destination in collapsed navigation');
  await injectSidebar();await page.setViewportSize({width:390,height:844});await page.locator('[data-menu]').click();await summary.click();
  ck(await menu.getByRole('link',{name:'Account-Einstellungen'}).isVisible()&&await menu.getByRole('link',{name:'Abmelden',exact:true}).isVisible(),'mobile navigation exposes both account actions');
  await page.screenshot({path:path.join(path.dirname(file),'account-sidebar-390.png'),fullPage:true});
  await page.keyboard.press('Escape');ck(!await page.locator('body').evaluate(n=>n.classList.contains('menu-open')),'Escape closes mobile menu');
  const own=await browser.newContext({viewport:{width:390,height:844},serviceWorkers:'block'});await own.addCookies([{name:'KH_VEREIN_DEMO',value:fixture.sessions.own,url:base.origin+'/'}]);await own.route('**/*',r=>new URL(r.request().url()).origin===base.origin&&r.request().method()==='GET'?r.continue():r.abort());
  const ownPage=await own.newPage();await ownPage.goto(route);ck(await ownPage.locator('[data-account-panel=signature]').count()===0,'ordinary member has no signature section or signature form');await own.close();
  assert.deepEqual(errors,[]);ck(true,'no JavaScript errors during all account and menu flows');await context.close();
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
