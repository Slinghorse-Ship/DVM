import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {execFileSync} from 'node:child_process';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE);
const root='/workspace/Kulturhus/',origin='http://127.0.0.1:8111';
const fixture=JSON.parse(await readFile(root+'.local/browser-tests/mail-timeline.json','utf8'));
const browser=await chromium.launch({executablePath:'/usr/bin/chromium',args:['--no-sandbox']});
let checks=0,writes=0,external=0;const errors=[];
const check=(ok,label)=>{assert(ok,label);++checks;console.log('PASS '+label);};
const scenarios=[['message',fixture],['comment',fixture.comment]];
const audit=async(page,label)=>{const result=await page.evaluate(()=>({fits:document.documentElement.scrollWidth<=innerWidth+1,small:[...document.querySelectorAll('.chat-messages a,.chat-composer button')].filter(n=>n.checkVisibility()).some(n=>{const r=n.getBoundingClientRect();return r.height<44||r.width<44;}),bounded:document.querySelector('.chat-messages').getBoundingClientRect().height<innerHeight}));check(result.fits&&!result.small&&result.bounded,label+' fits viewport with bounded history and actual touch targets');};
try{
 for(const [kind,scenario]of scenarios){
 const fixture=scenario;const url=origin+'/vereinsverwaltung/?view=social&section='+(kind==='message'?'inbox&thread='+fixture.thread:'review&attention='+fixture.source.id);
 for(const javaScriptEnabled of [true,false]){
  const context=await browser.newContext({storageState:root+'.local/browser-tests/controls-sessions/admin.json',javaScriptEnabled,hasTouch:true});await context.route('**/*',r=>{const request=r.request();if(new URL(request.url()).origin!==origin){++external;return r.abort();}if(request.method()!=='GET'&&request.method()!=='HEAD')++writes;return r.continue();});
  const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
  for(const width of [1440,820,390,320]){
   await page.setViewportSize({width,height:844});await page.goto(url);
   check(JSON.stringify(await page.locator('.chat-messages .social-caption').allTextContents())===JSON.stringify(fixture.texts.slice(30)),'exact latest 40 original human mail answers '+width+' JS='+javaScriptEnabled);await audit(page,'latest mail page '+width+' JS='+javaScriptEnabled);
   check(await page.locator('[name=reply_text]').inputValue()==='','independent human composer stays empty '+width+' JS='+javaScriptEnabled);
   await page.getByRole('link',{name:'Ältere Nachrichten',exact:true}).tap();await page.waitForURL(u=>u.searchParams.get('message_page')==='2');
   check(JSON.stringify(await page.locator('.chat-messages .social-caption').allTextContents())===JSON.stringify([fixture.question.text,...fixture.texts.slice(0,30)]),'exact earlier question and all 30 earlier human mail answers '+width+' JS='+javaScriptEnabled);await audit(page,'earlier mail page '+width+' JS='+javaScriptEnabled);
   check(await page.locator('.chat-messages .chat-bubble.outgoing').count()===30&&await page.locator('.chat-person .chat-kind').innerText()===(kind==='message'?'Nachricht':'Kommentar')&&await page.locator('.chat-person .chat-platform').innerText()==='Facebook','own mail replies retain direction, content kind and actual platform '+width+' JS='+javaScriptEnabled);
   check(await page.getByRole('link',{name:'Ältere Nachrichten',exact:true}).count()===0,'no fake third original history page '+width+' JS='+javaScriptEnabled);
   await page.getByRole('link',{name:'Neuere Nachrichten',exact:true}).focus();await Promise.all([page.waitForURL(u=>u.searchParams.get('message_page')==='1'),page.getByRole('link',{name:'Neuere Nachrichten',exact:true}).press('Enter')]);check(await page.locator('.chat-messages .social-caption').count()===40,'keyboard restores exact newest history page '+width+' JS='+javaScriptEnabled);
  }
  if(javaScriptEnabled){await page.setViewportSize({width:390,height:844});await page.goto(url);await page.evaluate(()=>document.documentElement.style.zoom='2');await audit(page,'actual shared mail history at 200 percent zoom');}
  await context.close();
 }
 }
 check(writes===0&&external===0&&errors.length===0,'complete original mail browsing has no writes, provider actions or script errors');
 const proof=execFileSync(process.env.PHP_CLI_BINARY||'php',[root+'vereinsverwaltung/tests/support/mail-timeline-browser-fixture.php','check'],{encoding:'utf8',env:{...process.env,KH_NATIVE_TEST_CONFIG:root+'.local/native-cms-integration/integrated-manager.json'}});check(proof.includes('All exact original database bytes retained'),'all exact original mail and business database bytes retained');
 console.log(JSON.stringify({checks,business_writes:writes,external_actions:external}));
}finally{await browser.close();}
