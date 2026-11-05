import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {spawnSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const root=fileURLToPath(new URL('../../',import.meta.url)),origin='http://127.0.0.1:8111';
const fixture=JSON.parse(await readFile(root+'.local/browser-tests/inbox-catalogue.json','utf8'));
const credentials=JSON.parse(await readFile(root+'.local/native-cms-integration/integrated-manager-runtime/browser-credentials.json','utf8'));
const browser=await chromium.launch({executablePath:process.env.CHROMIUM_EXECUTABLE||'/usr/bin/chromium',args:['--no-sandbox']});let checks=0;const errors=[],external=[],writes=[];
const check=(ok,label)=>{assert(ok,label);++checks;console.log('PASS '+label);};
async function client(javaScriptEnabled=true,state=null){
 if(!state&&process.env.INBOX_CATALOGUE_STORAGE_DIR)state=JSON.parse(await readFile(process.env.INBOX_CATALOGUE_STORAGE_DIR+'/admin.json','utf8'));
 const context=await browser.newContext({storageState:state||undefined,javaScriptEnabled,hasTouch:true});await context.route('**/*',route=>{const req=route.request();if(new URL(req.url()).origin!==origin){external.push(req.url());return route.abort();}if(req.method()==='POST'&&new URL(req.url()).pathname!=='/login/')writes.push(req.url());return route.continue();});
 const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));if(!state){await page.goto(origin+'/login/');await page.locator('[name=email]').fill(credentials.admin.email);await page.locator('[name=password]').fill(credentials.admin.password);await Promise.all([page.waitForURL('**/vereinsverwaltung/**'),page.getByRole('button',{name:'Anmelden und Vereinsmanager öffnen',exact:true}).click()]);}return {context,page};
}
const base=origin+'/vereinsverwaltung/?view=social&section=inbox&chat_list=1&q='+encodeURIComponent(fixture.prefix);
async function audit(page,label){const r=await page.evaluate(()=>({overflow:document.documentElement.scrollWidth>innerWidth+2,small:[...document.querySelectorAll('.social-chat-sidebar button,.social-chat-sidebar select,.social-chat-sidebar a')].filter(n=>n.checkVisibility()).some(n=>{const r=n.getBoundingClientRect();return r.height<43.5||r.width<43.5})}));check(!r.overflow&&!r.small,label+' has bounded viewport and real 44-pixel controls');}
try{
 const admin=await client();
 for(const js of [true,false]){
  const current=js?admin:await client(false,await admin.context.storageState()),page=current.page;
  for(const width of [1440,820,390,320]){
   await page.setViewportSize({width,height:width<=390?844:1050});await page.goto(base);check(await page.locator('.chat-choice').count()===6,'actual first SQL page contains only six conversations '+width+' JS='+js);await audit(page,'first page '+width+' JS='+js);
   await page.getByRole('link',{name:'Weitere Gespräche',exact:true}).tap();await page.waitForURL(url=>url.searchParams.get('conversation_page')==='2');check(await page.locator('.chat-choice').count()===6&&await page.locator('.chat-choice strong').first().innerText()===fixture.prefix+' 006','actual second page has next six exact originals '+width+' JS='+js);
   await page.locator('.chat-catalogue-page [name=conversation_page]').fill('10');await page.locator('.chat-catalogue-page').getByRole('button',{name:'Seite öffnen',exact:true}).tap();await page.waitForURL(u=>u.searchParams.get('conversation_page')==='10');check(await page.locator('.chat-choice').count()===1,'direct short page reaches exact older originals');
   await page.locator('.chat-choice[href*="thread='+fixture.threads['054']+'"]') .tap();await page.waitForURL(url=>url.searchParams.get('thread')===fixture.threads['054']);check(await page.locator('.chat-messages .social-caption').innerText()===fixture.messages['054'],'actual 54-day-old original opens without substitution '+width+' JS='+js);check(await page.locator('.chat-person .chat-kind').innerText()==='Nachricht'&&await page.locator('.chat-person .chat-platform').innerText()==='Facebook','old history retains exact kind and platform '+width+' JS='+js);
   if(width<=390){await page.getByRole('link',{name:'← Gespräche',exact:true}).tap();await page.waitForURL(url=>url.searchParams.get('chat_list')==='1');check(new URL(page.url()).searchParams.get('conversation_page')==='10'&&await page.locator('.chat-choice').count()===1,'mobile back retains actual list position '+width+' JS='+js);}
   await page.goto(base);await page.locator('.chat-search [name=q]').fill(fixture.prefix+' 054');await page.locator('.chat-search').getByRole('button',{name:'Suchen',exact:true}).tap();await page.waitForURL(url=>url.searchParams.get('q')===fixture.prefix+' 054');check(await page.locator('.chat-choice').count()===1,'complete search finds original beyond first page '+width+' JS='+js);
   await page.goto(base);await page.locator('[name=inbox_channel]:not([type=hidden])').selectOption('instagram');await page.getByRole('button',{name:'Anzeigen',exact:true}).tap();await page.waitForURL(url=>url.searchParams.get('inbox_channel')==='instagram');check(await page.locator('.chat-choice').count()===6&&await page.locator('.chat-choice .chat-platform').filter({hasText:'Facebook'}).count()===0,'actual platform filter precedes pagination '+width+' JS='+js);
  }
  if(!js)await current.context.close();
 }
 await admin.page.goto(base);await admin.page.evaluate(()=>document.documentElement.style.zoom='2');await audit(admin.page,'actual catalogue 200 percent zoom');await admin.page.locator('.chat-search [name=q]').focus();await admin.page.keyboard.press('Tab');check(await admin.page.locator('.chat-search button').evaluate(n=>document.activeElement===n),'keyboard reaches actual full-search action at zoom');
 for(const query of ['conversation_page=4','conversation_page=0','inbox_channel=stadtlandfunk','q[]=bad'])check((await admin.context.request.get(base+'&'+query)).status()===403,'real controller rejects malformed or unavailable list '+query);
 await admin.page.goto(origin+'/vereinsverwaltung/?view=social&section=inbox&thread='+fixture.threads['054']);check(await admin.page.locator('.chat-messages .social-caption').innerText()===fixture.messages['054'],'direct link loads selected old original independently of current first page');
 check(!errors.length&&!external.length&&!writes.length,'catalogue browser has no scripts errors, writes or external actions');
 const proof=spawnSync(process.env.PHP_BINARY||'php',[root+'vereinsverwaltung/tests/support/inbox-catalogue-browser-fixture.php','check'],{cwd:root,env:{...process.env,KH_NATIVE_TEST_CONFIG:root+'.local/native-cms-integration/integrated-manager.json'},encoding:'utf8'});check(proof.status===0,'all original message/mail/document/media/account/approval/job bytes unchanged');console.log(JSON.stringify({checks,business_writes:0,external_actions:0}));
}finally{await browser.close();}
