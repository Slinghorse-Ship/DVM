import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {spawnSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const root=fileURLToPath(new URL('../../',import.meta.url)),origin='http://127.0.0.1:8111';
const fixture=JSON.parse(await readFile(root+'.local/browser-tests/direct-mail.json','utf8'));
const credentials=JSON.parse(await readFile(root+'.local/native-cms-integration/integrated-manager-runtime/browser-credentials.json','utf8'));
const browser=await chromium.launch({executablePath:process.env.CHROMIUM_EXECUTABLE||'/usr/bin/chromium',args:['--no-sandbox']});let checks=0;const errors=[],external=[],writes=[];
const check=(ok,label)=>{assert(ok,label);++checks;console.log('PASS '+label);};
async function client(role,javaScriptEnabled=true,state=null){
 if(!state&&process.env.DIRECT_MAIL_STORAGE_DIR)state=JSON.parse(await readFile(process.env.DIRECT_MAIL_STORAGE_DIR+'/'+role+'.json','utf8'));
 const context=await browser.newContext({storageState:state||undefined,javaScriptEnabled,hasTouch:true});
 await context.route('**/*',route=>{const req=route.request();if(new URL(req.url()).origin!==origin){external.push(req.url());return route.abort();}if(req.method()==='POST'&&new URL(req.url()).pathname!=='/login/')writes.push(new URLSearchParams(req.postData()||'').get('operation'));return route.continue();});
 const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
 if(!state){await page.goto(origin+'/login/');await page.locator('[name=email]').fill(credentials[role].email);await page.locator('[name=password]').fill(credentials[role].password);await Promise.all([page.waitForURL('**/vereinsverwaltung/**'),page.getByRole('button',{name:'Anmelden und Vereinsmanager öffnen',exact:true}).click()]);}return {context,page};
}
const route=source=>origin+'/vereinsverwaltung/?view=social&section='+(source.kind==='message'?'inbox&thread='+source.thread:'review&attention='+source.id);
async function audit(page,label){const r=await page.evaluate(()=>({overflow:document.documentElement.scrollWidth>innerWidth+2,small:[...document.querySelectorAll('.chat-composer button,.chat-mail summary')].filter(n=>n.checkVisibility()).some(n=>{const r=n.getBoundingClientRect();return r.height<43.5||r.width<43.5})}));check(!r.overflow&&!r.small,label+' fits actual viewport and has 44-pixel controls');}
async function conversation(page,key){
 const source=fixture.sources[key];await page.goto(route(source));
 const bubble=page.locator('.chat-messages .social-caption').filter({hasText:fixture.bodies[key]});check(await bubble.count()===1,'real mail appears exactly once in actual chat '+key);
 check(await bubble.innerText()===fixture.bodies[key],'real human text is escaped and retained '+key);
 check(await page.locator('.chat-person .chat-kind').innerText()===(source.kind==='message'?'Nachricht':'Kommentar')&&await page.locator('.chat-person .chat-platform').innerText()===(source.channel==='facebook'?'Facebook':'Instagram'),'actual kind and platform stay distinct '+key);
 const badge=bubble.locator('..').locator('.badge');check((await badge.innerText()).includes(source.blocked?'Nicht gesendet':'Gesendet')&&!(await badge.innerText()).includes('Entwurf'),'actual transport outcome without draft '+key);
 check(await page.locator('[name=operation][value=social-mail-use]').count()===0,'no manual draft-adoption step '+key);
 check(await page.locator('[name=reply_text]').inputValue()==='','automatic reply never fills independent human composer '+key);
 await audit(page,key);
}
try{
 const admin=await client('admin');
 for(const width of [1440,820,390,320]){
  await admin.page.setViewportSize({width,height:width<=390?844:1050});
  for(const key of ['facebook-message','instagram-message','instagram-message-blocked'])await conversation(admin.page,key);
 }
 for(const key of ['facebook-comment','instagram-comment'])await conversation(admin.page,key);
 await admin.page.evaluate(()=>document.documentElement.style.zoom='2');await audit(admin.page,'actual 200 percent zoom');
 await admin.page.locator('[name=reply_text]').focus();await admin.page.keyboard.press('Tab');check(await admin.page.getByRole('button',{name:'Entwurf speichern',exact:true}).evaluate(n=>document.activeElement===n),'keyboard reaches separate manual composer at zoom');
 const noJs=await client('admin',false,await admin.context.storageState());await noJs.page.setViewportSize({width:390,height:844});await conversation(noJs.page,'instagram-message');
 await noJs.page.goto(origin+'/vereinsverwaltung/?view=social&section=mail');check(await noJs.page.locator('[name="mail[auto_send]"]').isChecked(),'direct sending is explicit in real module settings');
 await noJs.page.locator('[name="mail[enabled]"]').uncheck();await Promise.all([noJs.page.waitForNavigation(),noJs.page.getByRole('button',{name:'Einstellungen speichern',exact:true}).tap()]);
 await noJs.page.goto(route(fixture.sources['facebook-message']));check(await noJs.page.locator('.chat-mail').count()===0&&await noJs.page.locator('.chat-messages .social-caption').filter({hasText:fixture.bodies['facebook-message']}).count()===1,'switching optional mail off retains sent original in chat');
 const member=await client('member');check((await member.page.goto(route(fixture.sources['facebook-message']))).status()===403,'ordinary member cannot read direct private social conversation');
 check(!errors.length&&!external.length&&writes.length===1&&writes[0]==='social-mail-settings','actual browser performs one private setting save, no provider or mailbox action');
 const proof=spawnSync(process.env.PHP_BINARY||'php',[root+'vereinsverwaltung/tests/support/direct-mail-browser-fixture.php','check'],{cwd:root,env:{...process.env,KH_NATIVE_TEST_CONFIG:root+'.local/native-cms-integration/integrated-manager.json'},encoding:'utf8'});check(proof.status===0,'all exact original MIME/social/document/media/account/approval/job bytes retained');
 console.log(JSON.stringify({checks,private_saves:writes.length,live_transport:0}));
}finally{await browser.close();}
