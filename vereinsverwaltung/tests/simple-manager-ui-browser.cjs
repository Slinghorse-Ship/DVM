'use strict';
const {chromium}=require('playwright'),fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),{spawnSync}=require('node:child_process');
(async()=>{
 const file=process.env.KULTURHUS_MEMBER_PROFILE_FIXTURE,php=process.env.KULTURHUS_TEST_PHP;assert(file&&php);assert.equal(process.env.VEREIN_MODE,'synthetic');
 const fixture=JSON.parse(fs.readFileSync(file,'utf8').replace(/^\uFEFF/,'')),base=new URL(fixture.base);assert(['localhost','127.0.0.1'].includes(base.hostname)&&base.port==='8091'&&fixture.club.startsWith('member-profile-'));
 const state=()=>{const r=spawnSync(php,[path.join(__dirname,'support/simple-ui-fixture.php'),file],{encoding:'utf8',env:process.env});assert.equal(r.status,0,r.stderr);return JSON.parse(r.stdout);};
 const browser=await chromium.launch({channel:'msedge',headless:true});let checks=0;const ck=(ok,label)=>{assert(ok,label);console.log('PASS '+(++checks)+' '+label);};
 const original=state();assert.equal(original.outbox,0);
 try{
  const context=await browser.newContext({viewport:{width:1360,height:940},serviceWorkers:'block'});
  await context.route('**/*',r=>{const req=r.request();if(new URL(req.url()).origin!==base.origin)return r.abort();if(req.method()!=='GET'&&!(req.method()==='POST'&&['settings-save','settings-mail-channels','settings-invoice-mailbox','mailing-save'].includes(new URLSearchParams(req.postData()||'').get('action'))))return r.abort();return r.continue();});
  await context.addCookies([{name:'KH_VEREIN_DEMO',value:fixture.sessions.admin,url:base.origin+'/'}]);const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  const go=async query=>{const r=await page.goto(base.origin+'/'+query);if(r){assert.equal(r.status(),200);assert.equal(r.headers()['x-kulturhus-local-project'],fixture.project_hash);}ck(!/Fatal error:|Warning:|SQLSTATE\[/.test(await page.locator('main').innerText()),'route renders '+query);};
  await go('?view=settings');
  const form=page.locator('form[data-settings-form]');ck(await form.locator('details.settings-panel').count()===3,'club settings grouped into three sections');
  ck(await form.locator('[name^="data["]').count()===15,'all club fields rendered once; the obsolete cash switch is gone');
  await page.locator('#settings-club-address>summary').click();await form.locator('[name="data[street]"]').fill('Musterweg 8');await form.locator('[name="data[postcode]"]').fill('01234');await form.locator('[name="data[phone]"]').fill('0123 456');await page.locator('#settings-club-address>summary').click();
  await page.locator('#settings-club-options>summary').click();ck(await form.locator('[name="data[cash_enabled]"]').count()===0,'handkasse has no club-level off switch');await page.locator('#settings-club-options>summary').click();
  await Promise.all([page.waitForNavigation(),form.getByRole('button',{name:'Speichern',exact:true}).last().click()]);const saved=state();ck(saved.club.data.street==='Musterweg 8'&&saved.club.data.postcode==='01234'&&saved.club.data.phone==='0123 456'&&!Object.hasOwn(saved.club.data,'cash_enabled'),'closed fields really persist without a legacy cash switch');
  await page.reload();ck(await form.locator('[name="data[street]"]').inputValue()==='Musterweg 8','reload shows persisted address');
  await form.locator('[name=version]').evaluate(n=>n.value='0');await form.locator('[name="data[name]"]').fill('Synthetic unsaved title');await Promise.all([page.waitForNavigation(),form.getByRole('button',{name:'Speichern',exact:true}).last().click()]);
  ck(await page.locator('.error[role=alert]').count()===1&&await form.locator('details[open]').count()===3,'stale settings error exposes all sections');assert.deepEqual(state().club,saved.club);ck(await form.locator('[name="data[name]"]').inputValue()==='Synthetic unsaved title','failed save retains typed title');
  await go('?view=settings&section=mail');ck((await page.locator('h1').innerText())==='E-Mail-Einstellungen','mail page has its own heading');ck(await page.locator('form form').count()===0,'mailbox forms remain independent');
  ck(await page.locator('#mail-newsletter-basis').getAttribute('open')===null&&await page.locator('#mail-postfaecher').evaluate(n=>n.compareDocumentPosition(document.getElementById('mail-newsletter-basis'))&Node.DOCUMENT_POSITION_FOLLOWING),'actual mailboxes precede collapsed legacy settings');
  ck(!/noch keinen angeschlossenen Versandablauf|kein Versand freier Ausgangsrechnungen angeschlossen|sobald ein entsprechender Versandablauf angeschlossen/.test(await page.locator('main').innerText()),'obsolete unsupported-sending messages removed');
  await go('?view=settings&section=mail#mail-versandzwecke');ck(await page.locator('#mail-versandzwecke').getAttribute('open')!==null,'direct route-selection anchor opens disclosure');
  const channels=page.locator('#mail-postfaecher form');await channels.locator('[name="data[routes][invitations]"]').selectOption('newsletter');await Promise.all([page.waitForNavigation(),channels.getByRole('button',{name:'Postfächer und Versandzuordnung speichern'}).click()]);
  ck(state().channels.data.routes.invitations==='newsletter'&&state().channels.data.routes.contributions==='board','explicit route changes saved; other mail purposes preserved');
  await go('?view=settings&section=mail#rechnungsfach');ck(await page.locator('#rechnungsfach').getAttribute('open')!==null,'invoice-mailbox anchor opens correct panel');
  ck(await page.locator('#rechnungsfach input[type=password]').evaluateAll(ns=>ns.every(n=>n.value==='')),'saved credentials never rendered into forms');
  await go('?view=settings&section=tax');ck(await page.locator('details.settings-panel').count()===4,'tax settings grouped by purpose');
  ck(await page.locator('form[data-settings-form] [name^="data["]').count()===20,'all original tax fields retained once');
  for(const query of ['?view=settings','?view=settings&section=mail','?view=settings&section=tax','?view=settings&section=bank','?view=settings&section=fields','?view=portal&tab=documents','?view=portal&tab=safety','?view=messages','?view=inventory&tab=locations','?view=free','?view=portal&tab=application']){
   await page.setViewportSize({width:390,height:844});await go(query);ck(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'mobile has no document overflow '+query);
  }
  await page.setViewportSize({width:1360,height:940});await go('?view=messages');
  const list=page.locator('[data-simple-list]'),search=page.getByLabel('Sendungen suchen (Betreff oder Status)',{exact:true});ck(await list.locator('tbody tr:visible').count()===20,'long mailing list limited to 20 visible entries');
  await page.getByRole('button',{name:'Weiter',exact:true}).click();ck(await list.locator('tbody tr:visible').count()===6,'next page displays remaining six drafts');
  await search.fill('buhnenprobe');ck(await list.locator('tbody tr:visible').count()===1&&await list.locator('tbody tr:visible').innerText().then(t=>t.includes('Bühnenprobe')),'live search spans all pages and handles accents');
  await search.fill('missing-entry');ck(await list.locator('tbody tr:visible').count()===0&&await page.getByRole('status').filter({hasText:'Keine passenden Einträge'}).count()===1,'empty search has clear feedback');
  await search.fill('Bühnenprobe');await list.getByRole('link').filter({hasText:'Bühnenprobe'}).click();
  ck(await page.locator('form [name=action][value="mailing-save"]').count()===0&&await page.getByRole('link',{name:'Entwurf weiterbearbeiten'}).isVisible(),'saved draft opens preview without second edit form');
  await page.getByRole('link',{name:'Entwurf weiterbearbeiten'}).click();ck(await page.locator('form [name=action][value="mailing-save"]').count()===1&&await page.locator('form [name=action][value="mailing-queue"]').count()===0,'editing has one clear save action and no simultaneous send control');
  await page.locator('[name=subject]').fill('Synthetic revised draft');await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Entwurf speichern und Vorschau öffnen'}).click()]);
  ck(await page.getByRole('heading',{name:'Synthetic revised draft'}).count()===1&&await page.getByRole('link',{name:'Entwurf weiterbearbeiten'}).isVisible(),'real edit returns to persisted preview');ck(state().outbox===0,'no message ever queued or sent');
  for(const [name,query] of [['mail','?view=settings&section=mail'],['club','?view=settings'],['sendungen','?view=messages'],['safety','?view=portal&tab=safety']]){
   await page.setViewportSize({width:1360,height:940});await go(query);await page.screenshot({path:path.join(path.dirname(file),name+'-desktop.png'),fullPage:true});await page.setViewportSize({width:390,height:844});await page.locator('.sidebar').evaluate(node=>node.getAnimations().map(a=>a.finish()));await page.screenshot({path:path.join(path.dirname(file),name+'-mobile.png'),fullPage:true});
  }
  assert.deepEqual(errors,[]);ck(true,'no JavaScript errors');await context.close();
 }finally{await browser.close();}
})();
