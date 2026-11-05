import assert from 'node:assert/strict';
import {readFile,writeFile} from 'node:fs/promises';
import {spawnSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const root=fileURLToPath(new URL('../../',import.meta.url)),origin='http://127.0.0.1:8111';
const fixture=JSON.parse(await readFile(root+'.local/browser-tests/social-chat.json','utf8'));
const credentials=JSON.parse(await readFile(root+'.local/native-cms-integration/integrated-manager-runtime/browser-credentials.json','utf8'));
const browser=await chromium.launch({executablePath:process.env.CHROMIUM_EXECUTABLE||'/usr/bin/chromium',args:['--no-sandbox']});
let checks=0;const errors=[],external=[],writes=[];
const check=(ok,label)=>{assert(ok,label);++checks;console.log('PASS '+label);};
async function client(javaScriptEnabled,state=null){
 if(!state&&process.env.SOCIAL_CHAT_STORAGE_DIR)state=JSON.parse(await readFile(process.env.SOCIAL_CHAT_STORAGE_DIR+'/admin.json','utf8'));
 const context=await browser.newContext({storageState:state||undefined,javaScriptEnabled,hasTouch:true});
 await context.route('**/*',route=>{const req=route.request();if(new URL(req.url()).origin!==origin){external.push(req.url());return route.abort();}if(req.method()==='POST'&&new URL(req.url()).pathname!=='/login/')writes.push(req.url());return route.continue();});
 const page=await context.newPage();page.on('pageerror',error=>errors.push(error.message));
 if(!state){await page.goto(origin+'/login/');await page.locator('[name=email]').fill(credentials.admin.email);await page.locator('[name=password]').fill(credentials.admin.password);await Promise.all([page.waitForURL('**/vereinsverwaltung/**'),page.getByRole('button',{name:'Anmelden und Vereinsmanager öffnen',exact:true}).click()]);}
 return{context,page};
}
async function bounds(page,label){
 const layout=await page.evaluate(()=>{
  const chat=document.querySelector('.social-chat'),body=chat.querySelector('.chat-messages'),frame=chat.getBoundingClientRect();
  const actions=[...chat.querySelectorAll('button,a.btn,summary')].filter(n=>n.checkVisibility()).map(n=>({text:n.textContent.trim(),height:n.getBoundingClientRect().height,width:n.getBoundingClientRect().width}));
  return{height:frame.height,page:document.documentElement.scrollHeight,overflow:document.documentElement.scrollWidth>innerWidth+2,bounded:body.scrollHeight>=body.clientHeight&&getComputedStyle(body).overflowY==='auto',small:actions.filter(a=>a.height<43.5||a.width<43.5),hints:!!chat.querySelector('.hint,.social-library-heading'),buttons:actions.length};
 });
 check(!layout.overflow,label+' fits viewport');
 check(layout.height<=innerHeightOf(page)+2&&layout.page<=innerHeightOf(page)+400,label+' has bounded conversation height');
 check(layout.bounded,label+' scrolls messages inside their own conversation');
 check(!layout.small.length&&layout.buttons>0,label+' has usable real touch actions '+JSON.stringify(layout.small));
 check(!layout.hints,label+' has no explanatory panels');
}
function innerHeightOf(page){return page.viewportSize().height;}
try{
 const admin=await client(true);
 for(const js of[true,false]){
  const{context,page}=js?admin:await client(false,await admin.context.storageState());
  for(const width of[1440,820,430,390,360,320]){
   await page.setViewportSize({width,height:width<=430?844:1050});
   for(const channel of['facebook','instagram']){
    await page.goto(origin+'/vereinsverwaltung/?view=social&section=inbox&thread='+fixture.threads[channel]);
    await page.locator('.chat-bubble').first().waitFor();
    await bounds(page,channel+' '+width+' JS='+js);
    check(await page.locator('.chat-bubble').count()===40,'only latest forty actual messages rendered '+channel+' '+width+' JS='+js);
    check(await page.locator('.chat-person .chat-kind').innerText()==='Nachricht'&&await page.locator('.chat-person .chat-platform').innerText()===(channel==='facebook'?'Facebook':'Instagram'),'message type and actual platform stay explicit '+channel+' '+width+' JS='+js);
    check(await page.locator('.chat-bubble .social-caption').last().innerText()===fixture.latest[channel],'latest exact original text is preserved '+channel+' '+width+' JS='+js);
    check(await page.locator('.chat-bubble.outgoing').count()>0&&await page.locator('.chat-bubble:not(.outgoing)').count()>0,'actual own and received messages use distinct bubbles '+channel+' '+width+' JS='+js);
    check(await page.locator('.social-chat script, .social-chat textarea').count()===0,'messages never contain executable content or a fake reply composer '+channel+' '+width+' JS='+js);
    const older=page.getByRole('link',{name:'Ältere Nachrichten',exact:true});
    await older.tap();await page.waitForURL('**/*message_page=2*');
    check(await page.locator('.chat-bubble').count()===40&&!(await page.locator('.chat-messages').innerText()).includes(fixture.latest[channel]),'older forty original messages open without expanding page '+channel+' '+width+' JS='+js);
    await page.getByRole('link',{name:'Neuere Nachrichten',exact:true}).tap();await page.waitForURL(url=>!url.searchParams.has('message_page')||url.searchParams.get('message_page')==='1');
    await page.locator('.chat-bubble').last().waitFor();
    if(width<=430){await page.getByRole('link',{name:'← Gespräche',exact:true}).tap();await page.locator('.social-chat-sidebar').waitFor({state:'visible'});check(await page.locator('.social-chat-sidebar').isVisible(),'real mobile conversation picker opens '+channel+' JS='+js);}
    const target=page.locator('.chat-choice[href*="thread='+fixture.threads[channel==='facebook'?'instagram':'facebook']+'"]');
    await target.tap();await page.waitForURL(url=>url.searchParams.get('thread')===fixture.threads[channel==='facebook'?'instagram':'facebook']);
    await page.waitForFunction(expected=>document.querySelector('.chat-person .chat-platform')?.textContent===expected,channel==='facebook'?'Instagram':'Facebook');
    check(await page.locator('.chat-person .chat-platform').innerText()===(channel==='facebook'?'Instagram':'Facebook'),'actual conversation selection changes selected channel '+width+' JS='+js);
    if(js&&width===1440){
     await page.locator('[data-chat-search]').fill('Eigener Chatgast F');
     check(await page.locator('.chat-choice:visible').count()===1,'real conversation search filters instead of lengthening page');
     await page.locator('[data-chat-search]').fill('');
    }
    await page.screenshot({path:root+'.local/browser-tests/social-chat-'+channel+'-'+width+'-'+js+'.png'});
   }
   await page.goto(origin+'/vereinsverwaltung/?view=social&section=review&attention='+fixture.attention);
   await page.locator('[name=reply_text]').waitFor();
   check(await page.locator('[name=reply_text]').inputValue()==='','new actual conversation has no prefabricated answer '+width+' JS='+js);
   check(await page.locator('.chat-person .chat-kind').innerText()==='Kommentar'&&await page.locator('.chat-person .chat-platform').innerText()==='StadtLand.Funk','personal comment type and platform are separate visible labels '+width+' JS='+js);
   await page.locator('[name=reply_text]').fill('Nur mein selbst eingegebener Text');
   check(await page.locator('[name=reply_text]').inputValue()==='Nur mein selbst eingegebener Text','composer contains only human input '+width+' JS='+js);
   await page.locator('[name=reply_text]').fill('');
   await bounds(page,'empty human composer '+width+' JS='+js);
  }
  if(!js)await context.close();
 }
 await admin.page.evaluate(()=>document.documentElement.style.zoom='2');
 await bounds(admin.page,'actual chat 200 percent zoom');
 const fallback=await client(false,await admin.context.storageState());
 await fallback.page.setViewportSize({width:390,height:844});
 await fallback.page.goto(origin+'/vereinsverwaltung/?view=social&section=inbox&chat_list=1');
 check(await fallback.page.locator('.social-chat-sidebar').isVisible()&&!(await fallback.page.locator('.social-chat-thread').isVisible()),'mobile starts with a real conversation list without JavaScript');
 await fallback.page.locator('[data-chat-search]').fill('Eigener Chatgast I');
 await Promise.all([fallback.page.waitForNavigation(),fallback.page.getByRole('button',{name:'Suchen',exact:true}).tap()]);
 check(await fallback.page.locator('.chat-choice').count()===1&&await fallback.page.locator('.chat-choice .chat-platform').innerText()==='Instagram','actual server-side search works without JavaScript');
 await fallback.context.close();
 const invalidPage=await admin.context.request.get(origin+'/vereinsverwaltung/?view=social&section=inbox&thread='+fixture.threads.facebook+'&message_page=125');
 check(invalidPage.status()===403,'out-of-range message page is rejected instead of showing a fake empty conversation');
 const inaccessible=await admin.context.request.get(origin+'/vereinsverwaltung/?view=social&section=inbox&thread='+('0'.repeat(32)));
 check(inaccessible.status()===403,'unavailable conversation never opens another club thread');
 check(!errors.length&&!external.length&&!writes.length,'chat browsing performs no writes, canned replies or external actions');
 const proof=spawnSync(process.env.PHP_BINARY||'php',[root+'vereinsverwaltung/tests/support/social-chat-browser-fixture.php','check'],{cwd:root,env:{...process.env,KH_NATIVE_TEST_CONFIG:root+'.local/native-cms-integration/integrated-manager.json'},encoding:'utf8'});
 check(proof.status===0,'actual original messages, comments, approvals, documents, account and jobs unchanged');
 await writeFile(root+'.local/browser-tests/social-chat-result.json',JSON.stringify({checks,stored_messages:242,rendered_per_page:40,widths:[1440,820,430,390,360,320],javascript:[true,false],business_writes:0,external_actions:0})+'\n',{mode:0o600});
 console.log(JSON.stringify({checks,business_writes:0,external_actions:0}));
}finally{await browser.close();}
