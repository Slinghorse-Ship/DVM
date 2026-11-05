import assert from 'node:assert/strict';
import {readFile,writeFile} from 'node:fs/promises';
import {spawnSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
const{chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const root=fileURLToPath(new URL('../../',import.meta.url)),origin='http://127.0.0.1:8111';
const fixture=JSON.parse(await readFile(root+'.local/browser-tests/message-replies.json','utf8'));
const credentials=JSON.parse(await readFile(root+'.local/native-cms-integration/integrated-manager-runtime/browser-credentials.json','utf8'));
const browser=await chromium.launch({executablePath:process.env.CHROMIUM_EXECUTABLE||'/usr/bin/chromium',args:['--no-sandbox']});
let checks=0;const errors=[],external=[],writes=[],drafts={};
const check=(ok,label)=>{assert(ok,label);++checks;console.log('PASS '+label);};
async function client(role,javaScriptEnabled=true,state=null){
 if(!state&&process.env.MESSAGE_REPLIES_STORAGE_DIR)state=JSON.parse(await readFile(process.env.MESSAGE_REPLIES_STORAGE_DIR+'/'+role+'.json','utf8'));
 const context=await browser.newContext({storageState:state||undefined,javaScriptEnabled,hasTouch:true});
 await context.route('**/*',route=>{const req=route.request();if(new URL(req.url()).origin!==origin){external.push(req.url());return route.abort();}if(req.method()==='POST'&&new URL(req.url()).pathname!=='/login/')writes.push(new URLSearchParams(req.postData()||'').get('operation'));return route.continue();});
 const page=await context.newPage();page.on('pageerror',error=>errors.push(error.message));
 if(!state){await page.goto(origin+'/login/');await page.locator('[name=email]').fill(credentials[role].email);await page.locator('[name=password]').fill(credentials[role].password);await Promise.all([page.waitForURL('**/vereinsverwaltung/**'),page.getByRole('button',{name:'Anmelden und Vereinsmanager öffnen',exact:true}).click()]);}
 return{context,page};
}
async function audit(page,label,frames=true){
 if(frames)await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
 const layout=await page.evaluate(()=>{
  const chat=document.querySelector('.social-chat'),composer=chat.querySelector('.chat-composer'),thread=chat.querySelector('.social-chat-thread');
  return{overflow:document.documentElement.scrollWidth>innerWidth+2,height:chat.getBoundingClientRect().height,bounded:chat.getBoundingClientRect().height<=innerHeight+2,clip:composer.getBoundingClientRect().bottom>chat.getBoundingClientRect().bottom+2&&getComputedStyle(thread).overflowY!=='auto',small:[...chat.querySelectorAll('button,a.btn,summary')].filter(n=>n.checkVisibility()).filter(n=>{const r=n.getBoundingClientRect();return r.width<43.5||r.height<43.5}).map(n=>n.textContent.trim())};
 });
 check(!layout.overflow&&layout.bounded,label+' fits viewport with bounded height '+layout.height);check(!layout.clip,label+' keeps actual composer reachable inside conversation');check(!layout.small.length,label+' has 44-pixel real actions '+layout.small.join(','));
}
try{
 const admin=await client('admin');
 for(const width of[1440,820,390]){
  await admin.page.setViewportSize({width,height:width===390?844:1050});
  for(const channel of['facebook','instagram']){
   const source=fixture.conversations[channel];await admin.page.goto(origin+'/vereinsverwaltung/?view=social&section=inbox&thread='+source.thread);
   await admin.page.locator('[name=reply_text]').waitFor();await audit(admin.page,channel+' '+width);
   check(await admin.page.locator('.chat-person .chat-kind').innerText()==='Nachricht'&&await admin.page.locator('.chat-person .chat-platform').innerText()===(channel==='facebook'?'Facebook':'Instagram'),'real message type and platform remain explicit '+channel+' '+width);
   check(await admin.page.locator('.chat-bubble:not(.outgoing) .social-caption').innerText()===source.source.text,'actual multiline original retains precise whitespace '+channel+' '+width);
   const form=admin.page.locator('form').filter({has:admin.page.locator('[name=operation][value=social-reply-save]')});
   const version=Number(await form.locator('[name=reply_version]').inputValue());
   if(width===1440&&version===0)check(await form.locator('[name=reply_text]').inputValue()==='','new message answer is genuinely empty '+channel);
   const text=(width===1440?'  ':'')+'Eigene manuelle Nachricht <script>kein Code</script> · '+channel+' '+width+(width===1440?'\n ':'');
   await form.locator('[name=reply_text]').fill(text);await form.locator('[name=reply_text]').focus();await admin.page.keyboard.press('Tab');
   check(await form.getByRole('button',{name:'Entwurf speichern',exact:true}).evaluate(n=>document.activeElement===n),'keyboard reaches actual save '+channel+' '+width);
   await form.getByRole('button',{name:'Entwurf speichern',exact:true}).tap();
   await admin.page.waitForFunction(expected=>Number(document.querySelector('[name=operation][value=social-reply-save]')?.form.querySelector('[name=reply_version]')?.value)===expected,version+1);
   drafts[channel]=new URL(admin.page.url()).searchParams.get('reply');
   check(new URL(admin.page.url()).searchParams.get('section')==='inbox'&&new URL(admin.page.url()).searchParams.get('thread')===source.thread,'real save preserves exact original conversation '+channel+' '+width);
   check(await admin.page.locator('.social-reply-preview .social-caption').innerText()===text,'exact human text saved and displayed '+channel+' '+width);
   check((await admin.page.locator('.social-reply-preview .badge').innerText()).includes('nicht versendet'),'draft never claims live delivery '+channel+' '+width);
   check(await admin.page.locator('.social-chat script').count()===0,'original and human answer remain escaped '+channel+' '+width);
   check(await form.locator('[data-human-send]').getAttribute('name')==='human_send'&&/^[a-f0-9]{32}$/.test(await form.locator('[name=human_key]').inputValue()),'actual direct human send is a separate explicit button with an original request key '+channel+' '+width);
   await audit(admin.page,'saved '+channel+' '+width);
   await admin.page.screenshot({path:root+'.local/browser-tests/message-reply-'+channel+'-'+width+'.png'});
  }
 }
 await admin.page.evaluate(()=>document.documentElement.style.zoom='2');await audit(admin.page,'message composer 200 percent zoom');
 const zoomForm=admin.page.locator('form').filter({has:admin.page.locator('[name=operation][value=social-reply-save]')});
 const zoomVersion=Number(await zoomForm.locator('[name=reply_version]').inputValue());
 await zoomForm.locator('[name=reply_text]').fill('Eigene manuelle Nachricht bei 200 % Zoom');
 await zoomForm.getByRole('button',{name:'Entwurf speichern',exact:true}).tap();
 await admin.page.waitForFunction(expected=>Number(document.querySelector('[name=operation][value=social-reply-save]')?.form.querySelector('[name=reply_version]')?.value)===expected,zoomVersion+1);
 check(await admin.page.locator('.social-reply-preview .social-caption').innerText()==='Eigene manuelle Nachricht bei 200 % Zoom','actual zoomed touch action saves human text without clipping');
 const noJs=await client('admin',false,await admin.context.storageState());await noJs.page.setViewportSize({width:390,height:844});
 for(const channel of['facebook','instagram']){
  await noJs.page.goto(origin+'/vereinsverwaltung/?view=social&section=inbox&thread='+fixture.conversations[channel].thread+'&reply='+drafts[channel]);
  const form=noJs.page.locator('form').filter({has:noJs.page.locator('[name=operation][value=social-reply-save]')});const old=await form.evaluate(n=>Object.fromEntries(new FormData(n)));
  await form.locator('[name=reply_text]').fill('Eigene manuelle Nachricht ohne JavaScript · '+channel);
  await Promise.all([noJs.page.waitForNavigation(),form.getByRole('button',{name:'Entwurf speichern',exact:true}).tap()]);
  check(await noJs.page.locator('.social-reply-preview .social-caption').innerText()==='Eigene manuelle Nachricht ohne JavaScript · '+channel,'actual no-JavaScript save stores exact human message '+channel);
  check((await noJs.context.request.post(origin+'/vereinsverwaltung/?view=social',{headers:{Accept:'application/json'},form:old})).status()===422,'real stale message form rejected '+channel);
  const send=noJs.page.locator('form').filter({has:noJs.page.locator('[name=operation][value=social-reply-send]')});const unconfirmed=await send.evaluate(n=>Object.fromEntries(new FormData(n)));
  check((await noJs.context.request.post(origin+'/vereinsverwaltung/?view=social',{headers:{Accept:'application/json'},form:unconfirmed})).status()===422,'unconfirmed actual message send stops before API '+channel);
  await audit(noJs.page,'message draft without JavaScript '+channel,false);
 }
 const wrong=await noJs.context.request.get(origin+'/vereinsverwaltung/?view=social&section=inbox&thread='+fixture.conversations.facebook.thread+'&reply='+drafts.instagram);check(wrong.status()===403,'message draft cannot be attached to another conversation');
 await noJs.page.goto(origin+'/vereinsverwaltung/?view=social&section=inbox&thread='+fixture.conversations.expired.thread);
 const expired=noJs.page.locator('form').filter({has:noJs.page.locator('[name=operation][value=social-reply-save]')});
 await expired.locator('[name=reply_text]').fill('Eigener Entwurf im geschlossenen Fenster');await Promise.all([noJs.page.waitForNavigation(),expired.getByRole('button',{name:'Entwurf speichern',exact:true}).tap()]);
 check((await noJs.page.locator('.chat-status').innerText()).includes('Antwortfenster geschlossen')&&await noJs.page.locator('.chat-send').count()===0,'closed reply window is visible and offers no send action');
 const member=await client('member');check((await member.page.goto(origin+'/vereinsverwaltung/?view=social&section=inbox&thread='+fixture.conversations.facebook.thread)).status()===403,'ordinary member cannot inspect or answer a private conversation');
 check(writes.length===10&&writes.every(op=>op==='social-reply-save'),'browser performs only ten actual private draft saves');
 check(!errors.length&&!external.length,'message UI has no script errors or external actions');
 const proof=spawnSync(process.env.PHP_BINARY||'php',[root+'vereinsverwaltung/tests/support/message-replies-browser-fixture.php','check'],{cwd:root,env:{...process.env,KH_NATIVE_TEST_CONFIG:root+'.local/native-cms-integration/integrated-manager.json'},encoding:'utf8'});check(proof.status===0,'all other originals, messages, approvals, bindings and jobs remain unchanged');
 await writeFile(root+'.local/browser-tests/message-replies-result.json',JSON.stringify({checks,private_saves:10,provider_writes:0,rejected_stale:2,rejected_unconfirmed:2})+'\n',{mode:0o600});console.log(JSON.stringify({checks,private_saves:10,provider_writes:0}));
}finally{await browser.close();}
