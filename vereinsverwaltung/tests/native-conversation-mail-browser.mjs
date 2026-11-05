import assert from 'node:assert/strict';
import {readFile,writeFile} from 'node:fs/promises';
import {spawnSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const root=fileURLToPath(new URL('../../',import.meta.url)),origin='http://127.0.0.1:8111';
const fixture=JSON.parse(await readFile(root+'.local/browser-tests/conversation-mail.json','utf8'));
const credentials=JSON.parse(await readFile(root+'.local/native-cms-integration/integrated-manager-runtime/browser-credentials.json','utf8'));
const browser=await chromium.launch({executablePath:process.env.CHROMIUM_EXECUTABLE||'/usr/bin/chromium',args:['--no-sandbox']});
let checks=0;const errors=[],external=[],writes=[];
const check=(ok,label)=>{assert(ok,label);++checks;console.log('PASS '+label);};
async function client(role,javaScriptEnabled=true,state=null){
 if(!state&&process.env.CONVERSATION_MAIL_STORAGE_DIR)state=JSON.parse(await readFile(process.env.CONVERSATION_MAIL_STORAGE_DIR+'/'+role+'.json','utf8'));
 const context=await browser.newContext({storageState:state||undefined,javaScriptEnabled,hasTouch:true});
 await context.route('**/*',route=>{const req=route.request();if(new URL(req.url()).origin!==origin){external.push(req.url());return route.abort();}if(req.method()==='POST'&&new URL(req.url()).pathname!=='/login/')writes.push(new URLSearchParams(req.postData()||'').get('operation'));return route.continue();});
 const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
 if(!state){await page.goto(origin+'/login/');await page.locator('[name=email]').fill(credentials[role].email);await page.locator('[name=password]').fill(credentials[role].password);await Promise.all([page.waitForURL('**/vereinsverwaltung/**'),page.getByRole('button',{name:'Anmelden und Vereinsmanager öffnen',exact:true}).click()]);}
 return {context,page};
}
function form(page,operation){return page.locator('form').filter({has:page.locator('[name=operation][value='+operation+']')});}
function currentAnswer(page){return page.locator('.chat-mail-answer').filter({has:page.locator('[name=operation][value=social-mail-use]')});}
async function audit(page,label){const r=await page.evaluate(()=>({overflow:document.documentElement.scrollWidth>innerWidth+2,small:[...document.querySelectorAll('.chat-mail button,.chat-mail summary')].filter(n=>n.checkVisibility()).filter(n=>{const r=n.getBoundingClientRect();return r.height<43.5||r.width<43.5}).map(n=>n.textContent.trim())}));check(!r.overflow,label+' has no horizontal overflow');check(!r.small.length,label+' has real 44-pixel touch actions '+r.small.join(','));}
try{
 const admin=await client('admin');
 for(const width of [1440,820,390]){
  await admin.page.setViewportSize({width,height:width===390?844:1050});
  await admin.page.goto(origin+'/vereinsverwaltung/?view=social&section=mail');
  check(await admin.page.locator('[name="mail[target]"]').inputValue()==='mail-helper@example.invalid','actual configured target shown '+width);
  await admin.page.locator('.settings-panel>summary').tap();
  check(await admin.page.locator('[name="mail[imap_password]"]').inputValue()===''&&!(await admin.page.content()).includes('Synthetic-conversation-mail-secret'),'stored password never rendered '+width);
  const settings=form(admin.page,'social-mail-settings'),old=await settings.evaluate(n=>Object.fromEntries(new FormData(n)));
  await settings.getByRole('button',{name:'Einstellungen speichern',exact:true}).tap();await admin.page.waitForFunction(v=>Number(document.querySelector('[name=mail_version]')?.value)===v+1,Number(old.mail_version));
  check((await admin.context.request.post(origin+'/vereinsverwaltung/?view=social',{headers:{Accept:'application/json'},form:old})).status()===422,'actual stale settings rejected '+width);
  await admin.page.goto(origin+'/vereinsverwaltung/?view=social&section=review&attention='+fixture.sources.return.id);
  check(await admin.page.locator('.chat-mail').count()===1&&!(await admin.page.locator('.chat-mail').evaluate(n=>n.open)),'email stays collapsed in actual chat '+width);
  await admin.page.locator('.chat-mail>summary').tap();await currentAnswer(admin.page).locator(':scope>summary').tap();await audit(admin.page,'return '+width);
  check(await admin.page.locator('.chat-person .chat-kind').innerText()==='Kommentar'&&await admin.page.locator('.chat-person .chat-platform').innerText()==='Facebook','original kind and platform remain clear beside email '+width);
  check(await currentAnswer(admin.page).locator('.social-caption').innerText()===fixture.human&&await currentAnswer(admin.page).locator('.badge').innerText()==='E-Mail','exact genuine human mail is visibly separate '+width);
  const use=form(admin.page,'social-mail-use'),version=Number(await use.locator('[name=reply_version]').inputValue());
  check(!(await use.locator('[name=mail_confirm]').isChecked()),'human adoption confirmation starts empty '+width);
  check(await use.locator('[name=reply_text]').inputValue()===fixture.human,'editable text comes only from actual received email '+width);
  await use.locator('[name=mail_confirm]').check();await use.getByRole('button',{name:'Übernehmen',exact:true}).tap();
  await admin.page.waitForFunction(v=>Number(document.querySelector('[name=operation][value=social-reply-save]')?.form.querySelector('[name=reply_version]')?.value)===v+1,version);
  check(await admin.page.locator('.social-reply-preview .social-caption').innerText()===fixture.human,'actual controller saves exact human mail as draft '+width);
  check((await admin.page.locator('.social-reply-preview .badge').innerText()).includes('nicht versendet'),'adopted mail has no fake social send '+width);
  await admin.page.goto(origin+'/vereinsverwaltung/?view=social&section=review&attention='+fixture.sources.forward.id);await admin.page.locator('.chat-mail>summary').tap();
  const prepare=form(admin.page,'social-mail-prepare'),mailVersion=Number(await prepare.locator('[name=mail_version]').inputValue());
  if(mailVersion===0)check(await prepare.locator('[name=mail_note]').inputValue()==='','new forwarding note has no canned text');
  await prepare.locator('[name=mail_note]').fill('Eigene menschliche Notiz '+width);await prepare.getByRole('button',{name:'Mail vorbereiten',exact:true}).tap();
  await admin.page.waitForURL('**/vereinsverwaltung/**');await admin.page.waitForFunction(v=>Number(document.querySelector('[name=operation][value=social-mail-prepare]')?.form.querySelector('[name=mail_version]')?.value)===v+1,mailVersion);
  await admin.page.locator('.chat-mail>summary').tap();await audit(admin.page,'prepared '+width);
  check(await form(admin.page,'social-mail-prepare').locator('[name=mail_note]').inputValue()==='Eigene menschliche Notiz '+width,'actual private forwarding note stored '+width);
  const send=form(admin.page,'social-mail-send');check(!(await send.locator('[name=mail_confirm]').isChecked()),'forwarding needs deliberate empty confirmation '+width);
  const unconfirmed=await send.evaluate(n=>Object.fromEntries(new FormData(n)));check((await admin.context.request.post(origin+'/vereinsverwaltung/?view=social',{headers:{Accept:'application/json'},form:unconfirmed})).status()===422,'unconfirmed actual mail controller sends nothing '+width);
  await admin.page.screenshot({path:root+'.local/browser-tests/conversation-mail-'+width+'.png'});
 }
 await admin.page.evaluate(()=>document.documentElement.style.zoom='2');await audit(admin.page,'email 200 percent zoom');
 const zoom=form(admin.page,'social-mail-prepare'),zoomVersion=Number(await zoom.locator('[name=mail_version]').inputValue());await zoom.locator('[name=mail_note]').fill('Eigene Notiz bei 200 % Zoom');await zoom.locator('[name=mail_note]').focus();await admin.page.keyboard.press('Tab');check(await zoom.getByRole('button',{name:'Mail vorbereiten',exact:true}).evaluate(n=>document.activeElement===n),'keyboard reaches actual zoomed mail action');await zoom.getByRole('button',{name:'Mail vorbereiten',exact:true}).tap();await admin.page.waitForFunction(v=>Number(document.querySelector('[name=operation][value=social-mail-prepare]')?.form.querySelector('[name=mail_version]')?.value)===v+1,zoomVersion);await admin.page.locator('.chat-mail>summary').tap();check(await form(admin.page,'social-mail-prepare').locator('[name=mail_note]').inputValue()==='Eigene Notiz bei 200 % Zoom','zoomed actual touch action saves exact human note');
 const noJs=await client('admin',false,await admin.context.storageState());await noJs.page.setViewportSize({width:390,height:844});
 await noJs.page.goto(origin+'/vereinsverwaltung/?view=social&section=review&attention='+fixture.sources.forward.id);await noJs.page.locator('.chat-mail>summary').tap();const prepare=form(noJs.page,'social-mail-prepare');await prepare.locator('[name=mail_note]').fill('Eigene Notiz ohne JavaScript');await Promise.all([noJs.page.waitForNavigation(),prepare.getByRole('button',{name:'Mail vorbereiten',exact:true}).tap()]);await noJs.page.locator('.chat-mail>summary').tap();check(await form(noJs.page,'social-mail-prepare').locator('[name=mail_note]').inputValue()==='Eigene Notiz ohne JavaScript','actual no-JavaScript forward form saves private human note');
 await noJs.page.goto(origin+'/vereinsverwaltung/?view=social&section=review&attention='+fixture.sources.return.id);await noJs.page.locator('.chat-mail>summary').tap();await currentAnswer(noJs.page).locator(':scope>summary').tap();const use=form(noJs.page,'social-mail-use'),old=await use.evaluate(n=>Object.fromEntries(new FormData(n)));old.mail_confirm='1';await use.locator('[name=mail_confirm]').check();await Promise.all([noJs.page.waitForNavigation(),use.getByRole('button',{name:'Übernehmen',exact:true}).tap()]);check(await noJs.page.locator('.social-reply-preview .social-caption').innerText()===fixture.human,'no-JavaScript adoption keeps exact human email');check((await noJs.context.request.post(origin+'/vereinsverwaltung/?view=social',{headers:{Accept:'application/json'},form:old})).status()===422,'stale mail adoption cannot replace current draft');
 old.attention_id=fixture.sources.forward.id;check((await noJs.context.request.post(origin+'/vereinsverwaltung/?view=social',{headers:{Accept:'application/json'},form:old})).status()===422,'real controller refuses mail answer on another original');
 const member=await client('member');check((await member.page.goto(origin+'/vereinsverwaltung/?view=social&section=mail')).status()===403,'ordinary member cannot activate optional module or read addresses');
 await noJs.page.goto(origin+'/vereinsverwaltung/?view=social&section=mail');await noJs.page.locator('[name="mail[target]"]').fill('new-helper@example.invalid');await Promise.all([noJs.page.waitForNavigation(),form(noJs.page,'social-mail-settings').getByRole('button',{name:'Einstellungen speichern',exact:true}).tap()]);await noJs.page.goto(origin+'/vereinsverwaltung/?view=social&section=review&attention='+fixture.sources.forward.id);await noJs.page.locator('.chat-mail>summary').tap();check(await form(noJs.page,'social-mail-prepare').count()===1&&await form(noJs.page,'social-mail-prepare').locator('[name=mail_note]').inputValue()==='','new target has a genuinely new blank forwarding draft');check(await form(noJs.page,'social-mail-send').count()===0,'old prepared email cannot be offered for sending after target change');check((await form(noJs.page,'social-mail-prepare').innerText()).includes('new-helper@example.invalid'),'actual preparation uses the current visible target');await noJs.page.goto(origin+'/vereinsverwaltung/?view=social&section=mail');await noJs.page.locator('[name="mail[target]"]').fill('mail-helper@example.invalid');await Promise.all([noJs.page.waitForNavigation(),form(noJs.page,'social-mail-settings').getByRole('button',{name:'Einstellungen speichern',exact:true}).tap()]);
 await noJs.page.goto(origin+'/vereinsverwaltung/?view=social&section=mail');await noJs.page.locator('[name="mail[enabled]"]').uncheck();await Promise.all([noJs.page.waitForNavigation(),form(noJs.page,'social-mail-settings').getByRole('button',{name:'Einstellungen speichern',exact:true}).tap()]);check(!(await noJs.page.locator('[name="mail[enabled]"]').isChecked()),'actual optional module can be switched off without losing secrets');await noJs.page.goto(origin+'/vereinsverwaltung/?view=social&section=review&attention='+fixture.sources.return.id);check(await noJs.page.locator('.chat-mail').count()===0,'disabled module removes forwarding controls');
 check(!errors.length&&!external.length,'mail UI has no script errors or external actions');check(writes.length===15&&writes.every(op=>['social-mail-settings','social-mail-prepare','social-mail-use'].includes(op)),'browser makes exactly fifteen actual private saves and no mail send');
 const proof=spawnSync(process.env.PHP_BINARY||'php',[root+'vereinsverwaltung/tests/support/conversation-mail-browser-fixture.php','check'],{cwd:root,env:{...process.env,KH_NATIVE_TEST_CONFIG:root+'.local/native-cms-integration/integrated-manager.json'},encoding:'utf8'});check(proof.status===0,'actual original email bytes, other original data, approvals and jobs unchanged');
 console.log(JSON.stringify({checks,private_saves:writes.length,live_mail:0}));await writeFile(root+'.local/browser-tests/conversation-mail-result.json',JSON.stringify({checks,private_saves:writes.length,live_mail:0})+'\n',{mode:0o600});
}finally{await browser.close();}
