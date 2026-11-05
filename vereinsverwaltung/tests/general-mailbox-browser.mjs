import {readFile} from 'node:fs/promises';
import {createHash} from 'node:crypto';
import assert from 'node:assert/strict';
let input='';for await(const chunk of process.stdin)input+=chunk;
const fixture=JSON.parse(input),origin=fixture.origin;
assert.match(origin,/^http:\/\/127\.0\.0\.1:[0-9]+$/);
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE||'/usr/bin/chromium',args:['--no-sandbox']});
let checks=0,states=0,external=0;const errors=[];
const check=(condition,label)=>{assert.ok(condition,label);checks++;};
const url=(query={})=>origin+'/vereinsverwaltung/?'+new URLSearchParams({view:'messages',tab:'inbox',...query});
const form=(page,action)=>page.locator('form:has(input[name="action"][value="'+action+'"])');
const values=async node=>node.evaluate(element=>Object.fromEntries(new FormData(element)));
const context=async(js,role='admin',width=390)=>{
 const c=await browser.newContext({javaScriptEnabled:js,viewport:{width,height:900},acceptDownloads:true});
 c.setDefaultTimeout(30000);c.setDefaultNavigationTimeout(60000);
 await c.route('**/*',route=>{if(new URL(route.request().url()).origin!==origin){external++;return route.abort();}return route.continue();});
 c.on('page',page=>{page.on('pageerror',error=>errors.push(error.message));page.on('dialog',dialog=>dialog.type()==='beforeunload'?dialog.accept():dialog.dismiss());});
 await c.request.get(origin+'/own-test-enter?role='+role);return c;
};
const submit=async(page,action,button)=>{
 const response=page.waitForResponse(r=>r.request().method()==='POST');await form(page,action).getByRole('button',{name:button,exact:true}).click({noWaitAfter:true});return response;
};
try{
 for(const js of [true,false])for(const width of [320,390,820,1440])for(const size of [16,32]){
  const c=await context(js,'admin',width),page=await c.newPage();const response=await page.goto(url({mail:fixture.data.messages[js?'js':'basic']}));
  check(response.status()===200,'real shared inbox opens for current manager');await page.locator('[data-mailbox-message]').waitFor();await page.evaluate(n=>document.documentElement.style.fontSize=n+'px',size);
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'mailbox source and lists fit '+[width,size,js]);
  check(await page.locator('[data-mailbox-list]>article').count()===6,'actual source list stays at six bounded messages');
  await page.locator('details').filter({has:page.locator('summary').getByText('Zuständigkeit und Ablage',{exact:true})}).locator('summary').click();
  const targets=await form(page,'mailbox-change').locator('button,select').evaluateAll(nodes=>nodes.map(node=>({w:node.getBoundingClientRect().width,h:node.getBoundingClientRect().height})));
  check(targets.every(t=>t.w>=43&&t.h>=43),'real visible mailbox controls retain touch targets');
  check(await page.locator('[data-mailbox-message] script').count()===0,'human script-shaped mail never becomes active HTML');states++;await c.close();
 }
 for(const js of [true,false]){
  const mode=js?'js':'basic',id=fixture.data.messages[mode],c=await context(js),page=await c.newPage();
  await page.goto(url());const search=page.locator('form[role="search"]');await search.locator('[name="mailbox_q"]').fill('human text 14');await Promise.all([page.waitForURL(u=>u.searchParams.get('mailbox_q')==='human text 14'),search.getByRole('button',{name:'Suchen',exact:true}).click()]);
  check(await page.locator('[data-mailbox-list]>article').count()===1,'visible subject/sender/body search returns the actual matching message with JavaScript on or off');await page.locator('[data-mailbox-list]>article h3 a').click();await page.locator('.mailbox-composer').waitFor();check(new URL(page.url()).searchParams.get('mailbox_q')==='human text 14'&&await form(page,'mailbox-reply-save').locator('[name="mailbox_q"]').inputValue()==='human text 14','message selection and reply keep the exact current search context');
  await page.goto(url({mail:fixture.data.thread.root}));check(await page.locator('.mailbox-conversation-log>article').first().getAttribute('data-mailbox-reply')!==null,'latest manager reply appears above all older incoming originals');check(await page.locator('[data-mailbox-message]').count()===2&&await page.locator('[data-mailbox-reply]').count()===1,'root conversation displays both incoming originals and the existing manager answer');
  await page.goto(url({mail:fixture.data.thread.child}));check(await page.locator('[data-mailbox-message]').count()===2&&await page.locator('[data-mailbox-reply]').count()===1,'opening the received response displays that same complete conversation');
  await page.goto(origin+'/vereinsverwaltung/?view=messages');await page.getByRole('link',{name:'Postfach · Vorstand',exact:true}).click();await page.locator('[data-mailbox]').waitFor();
  check(new URL(page.url()).searchParams.get('tab')==='inbox','mailbox is reachable inside the existing Sendungen manager');
  const picker=page.locator('form:has(input[type="number"][name="mailbox_page"])');await picker.locator('input[name="mailbox_page"]').fill('3');
  await Promise.all([page.waitForURL(u=>u.searchParams.get('mailbox_page')==='3'),picker.getByRole('button',{name:'Seite öffnen',exact:true}).click()]);
  check(await page.locator('[data-mailbox-list]>article').count()===1,'direct last-page selection reaches original messages beyond the first page');
  await page.goto(url({mail:id}));check((await page.locator('[data-mailbox-message] iframe').contentFrame().locator('body').innerText()).includes('Original browser human text'),'actual manager displays original decoded human mail text in its isolated HTML5 document');check(await page.locator('[data-mailbox-message] iframe').contentFrame().locator('table strong').count()===1&&await page.evaluate(()=>window.ownMailInjected)!==true,'HTML original keeps its actual table and strong formatting without executing original script');const htmlResponse=await c.request.get(url({mail:id,mailbox_html:id}));check(htmlResponse.status()===200&&htmlResponse.headers()['cache-control'].includes('no-store')&&htmlResponse.headers()['content-security-policy'].includes('sandbox')&&!htmlResponse.headers()['content-security-policy'].includes('allow-scripts'),'actual private HTML endpoint keeps its independent passive policy and cannot cache original mail');const refusedHtmlPost=await c.request.post(url({mail:id,mailbox_html:id}),{form:{action:'mailbox-fetch'}});check(refusedHtmlPost.status()===405,'read-only HTML endpoint rejects a method before mailbox action handling');
  if(js){
   const mailFrame=page.locator('[data-mailbox-html]');await page.waitForFunction(()=>Number.parseFloat(document.querySelector('[data-mailbox-html]').style.height)>0);
   const shortHeight=await mailFrame.evaluate(node=>node.getBoundingClientRect().height);check(shortHeight<230,'short original mail uses its actual compact height');
   await mailFrame.contentFrame().locator('body').evaluate(body=>{const extra=document.createElement('div');extra.id='own-height-test';for(let i=0;i<35;i++){const p=document.createElement('p');p.textContent='Synthetic longer mail line '+i;extra.append(p);}body.append(extra);});
   await page.waitForFunction(h=>document.querySelector('[data-mailbox-html]').getBoundingClientRect().height>h+500,shortHeight);check(await mailFrame.evaluate(node=>node.getBoundingClientRect().height)>shortHeight+500,'long original mail grows without the former fixed-height internal scroll box');
   await mailFrame.contentFrame().locator('#own-height-test').evaluate(node=>node.remove());await page.waitForFunction(h=>Math.abs(document.querySelector('[data-mailbox-html]').getBoundingClientRect().height-h)<3,shortHeight);check(true,'mail shrinks again when its rendered content becomes short');
   const surface=page.locator('[data-mailbox-rich] [data-richtext-surface]');await surface.fill('Line one\nLine two\nLine three');const smallReply=await surface.evaluate(node=>node.getBoundingClientRect().height);await surface.fill(Array.from({length:30},(_,i)=>'Synthetic answer line '+i).join('\n'));const longReply=await surface.evaluate(node=>node.getBoundingClientRect().height);check(smallReply<180&&longReply>smallReply+300,'reply editor follows short and long text content');await surface.fill('');
  }
  check(await page.locator('.mailbox-attachment-download').count()===3,'incoming file list includes binary image and PDF attachments');
  for(const[name,sha]of Object.entries(fixture.data.attachment_hashes)){const link=page.locator('.mailbox-attachment-download').filter({hasText:name}),response=await c.request.get(new URL(await link.getAttribute('href'),page.url()).href);check(response.status()===200&&createHash('sha256').update(await response.body()).digest('hex')===sha&&response.headers()['content-disposition'].startsWith('attachment')&&response.headers()['cache-control'].includes('no-store'),'actual '+name+' download preserves its source bytes and private disposition');}
  const image=page.locator('.mailbox-attachment-preview');await image.scrollIntoViewIfNeeded();await image.evaluate(node=>node.decode());check(await image.evaluate(node=>node.naturalWidth>0),'attached raster image displays directly inside the conversation');
  const binary=await c.request.get(new URL(await page.locator('.mailbox-attachment-download').filter({hasText:'original.bin'}).getAttribute('href'),page.url()).href);check((await binary.body()).toString('hex')==='00010203ff','non-image attachment remains an exact downloadable file');
  const deniedFilePost=await c.request.post(new URL(await page.locator('.mailbox-attachment-download').first().getAttribute('href'),page.url()).href,{form:{action:'mailbox-fetch'}});check(deniedFilePost.status()===405,'attachment download refuses writes before any mailbox action');
  const stoppedFetch=await submit(page,'mailbox-fetch','Nachrichten abrufen');check(stoppedFetch.status()===422,'actual local IMAP policy blocks a provider fetch without moving the saved cursor; HTTP '+stoppedFetch.status());
  await page.getByText('Postfachabruf ist in dieser Umgebung gesperrt.',{exact:true}).waitFor();
  await page.goto(url({mail:id}));await page.getByText('Postfach und Filter',{exact:true}).click();await page.getByText('Postfach einrichten',{exact:true}).click();const settings=form(page,'mailbox-settings');const settingsBefore=await values(settings);
  check((await submit(page,'mailbox-settings','Postfach speichern')).status()===303,'actual settings save retains an existing private password when left empty');
  await form(page,'mailbox-settings').locator('input[name="mailbox_version"][value="'+String(Number(settingsBefore.mailbox_version)+1)+'"]').waitFor({state:'attached'});
  await form(page,'mailbox-settings').locator('input[name="mailbox[imap_user]"]').fill('Own unsaved changed mailbox user');
  check((await submit(page,'mailbox-settings','Postfach speichern')).status()===422,'actual account change requires renewed credentials before replacing the saved mailbox');
  await page.getByText('Bei neuem Server oder Benutzer das Passwort erneut eingeben.',{exact:true}).waitFor();
  check(await form(page,'mailbox-settings').locator('input[name="mailbox[imap_user]"]').inputValue()==='Own unsaved changed mailbox user'&&await form(page,'mailbox-settings').locator('input[type="password"]').inputValue()==='','settings rejection retains the own username without redisplaying the password');
  await page.getByRole('link',{name:'Gespeicherten Postfachzugang öffnen',exact:true}).click();await form(page,'mailbox-settings').locator('input[name="mailbox[imap_user]"][value="own-user"]').waitFor({state:'attached'});
  await page.goto(url({mail:id}));
  const download=page.waitForEvent('download');await page.getByRole('link',{name:'Original mit Anhängen herunterladen (.eml)',exact:true}).click();
  const bytes=await readFile(await(await download).path());check(createHash('sha256').update(bytes).digest('hex')===fixture.data.hashes[mode],'actual browser downloads every original MIME and attachment byte unchanged');
  const head=await c.request.head(url({mailbox_original:id}));check(head.status()===200&&(await head.body()).length===0&&Number(head.headers()['content-length'])===bytes.length,'HEAD checks original size without returning a body or changing state');
  await page.getByText('Zuständigkeit und Ablage',{exact:true}).click();const change=form(page,'mailbox-change');const stale=await values(change);
  await change.locator('select[name="assigned_to"]').selectOption(fixture.data.board);check((await submit(page,'mailbox-change','Zuständigkeit und Ablage speichern')).status()===303,'actual assignment form accepts the current board person');
  await form(page,'mailbox-change').locator('input[name="mail_version"][value="2"]').waitFor({state:'attached'});
  const rejected=await c.request.post(url({mail:id}),{form:{...stale,assigned_to:'',message_state:'archived'},maxRedirects:0});
  check(rejected.status()===409,'old assignment form cannot archive or overwrite a new decision');
  const rejectedText=await rejected.text();check(/name="mail_version" value="1"/.test(rejectedText)&&/value="archived" selected/.test(rejectedText),'real conflict page preserves the submitted original version and own archive selection');
  const wrong=await c.request.post(url({mail:id}),{form:{...stale,csrf:'wrong'},maxRedirects:0});check(wrong.status()===403,'real mailbox endpoint rejects wrong original CSRF');
  const delegated=await context(js,'reader');const mine=await delegated.request.get(url({mailbox_assigned:'mine',mail:id}));const mineText=await mine.text();check(mine.status()===200&&mineText.includes('Original browser human text')&&!mineText.includes('Keine Berechtigung für diese Vereinsdaten'),'currently assigned board office reads its own inbox without unrelated member-data rights');await delegated.close();
  const denied=await context(js,'member');const deniedPage=await denied.request.get(url({mail:id})),deniedFile=await denied.request.get(url({mailbox_original:id})),deniedHtml=await denied.request.get(url({mail:id,mailbox_html:id}));check(deniedPage.status()===403&&deniedFile.status()===403&&deniedHtml.status()===403,'ordinary authenticated member cannot read or download known private mail');await denied.close();
  await page.goto(url({mail:id}));await page.locator('.mailbox-composer').waitFor();const draft=form(page,'mailbox-reply-save');
  const body='Meine tatsächliche eigene Antwort '+mode+' ä🙂\nZweite unverlorene Zeile.';if(js&&await draft.locator('[data-mailbox-rich]:not([hidden]) [data-richtext-surface]').count())await draft.locator('[data-mailbox-rich] [data-richtext-surface]').fill(body);else await draft.locator('textarea[name="reply_text"]').fill(body);const originalDraft=await values(draft);
  const saved=await submit(page,'mailbox-reply-save','Entwurf speichern');
  if(saved.status()!==303){const html=await saved.text();const message=html.match(/<[^>]*class="[^"]*alert-danger[^" ]*[^"]*"[^>]*>([\s\S]*?)<\/[^>]+>/);console.error('Reply save status',saved.status(),message?.[1]?.replace(/<[^>]*>/g,'').slice(0,600));}
  check(saved.status()===303,'human reply is saved before any send confirmation');
  await page.locator('[data-mailbox-reply]').waitFor();const reply=new URL(page.url()).searchParams.get('reply');check(/^[a-f0-9]{32}$/.test(reply||''),'saved reply keeps a stable original intention identifier');
  const replay=await c.request.post(url({mail:id}),{form:originalDraft,maxRedirects:0});check(replay.status()===303&&new URL(replay.headers().location,origin).searchParams.get('reply')===reply,'actual lost-save HTTP replay recovers the same reply once');
  const edited=await c.request.post(url({mail:id}),{form:{...originalDraft,reply_text:'Eigene unverlorene Konfliktantwort '+mode,...(originalDraft.reply_html!==undefined?{reply_html:'<p>Eigene unverlorene Konfliktantwort '+mode+'</p>'}:{})},maxRedirects:0});check(edited.status()===409&&(await edited.text()).includes('Eigene unverlorene Konfliktantwort '+mode),'stale reply replay preserves the human correction without adopting another version');
  const sendValues=await values(form(page,'mailbox-reply-send'));const unconfirmed=await c.request.post(url({mail:id,reply}),{form:Object.fromEntries(Object.entries(sendValues).filter(([key])=>key!=='confirm_send')),maxRedirects:0});check(unconfirmed.status()===422,'missing explicit send intent cannot dispatch a stored reply');
  const locallyBlocked=await submit(page,'mailbox-reply-send','Gespeicherte Antwort senden');
  check(locallyBlocked.status()===422,'real local SMTP policy stops before provider handoff');await page.getByText('Versand deaktiviert · Die Antwort bleibt gespeichert.',{exact:true}).waitFor();
  check((await page.locator('[data-mailbox-reply]').innerText()).includes('Entwurf')&&(await page.locator('[data-mailbox-reply]').innerText()).includes('Meine tatsächliche eigene Antwort'),'known local mail failure retains the exact saved reply for explicit continuation');
  await page.getByText('Zuständigkeit und Ablage',{exact:true}).click();await form(page,'mailbox-change').locator('select[name="message_state"]').selectOption('archived');
  check((await submit(page,'mailbox-change','Zuständigkeit und Ablage speichern')).status()===303,'human local archive uses a current original form');await form(page,'mailbox-change').locator('input[name="mail_version"][value="3"]').waitFor({state:'attached'});
  check(await page.locator('.mailbox-composer').count()===1&&await page.locator('[data-mailbox-reply-jump]').count()===1,'archived messages offer the actual reply editor directly');
  await page.getByText('Zuständigkeit und Ablage',{exact:true}).click();await form(page,'mailbox-change').locator('select[name="message_state"]').selectOption('active');
  check((await submit(page,'mailbox-change','Zuständigkeit und Ablage speichern')).status()===303,'explicit restore keeps the same original message and reply');await form(page,'mailbox-change').locator('input[name="mail_version"][value="4"]').waitFor({state:'attached'});
  await page.locator('[data-mailbox-reply-jump]').click();check(new URL(page.url()).hash==='#mailbox-reply'&&await page.getByText('Du kannst auch ohne zugewiesene Person antworten.',{exact:true}).count()===1,'visible Reply jumps straight to the editor without making responsibility mandatory');
  check((await submit(page,'mailbox-trash','Mail löschen')).status()===303,'one visible delete click moves the selected message to trash');await form(page,'mailbox-restore').waitFor();check(await page.locator('.mailbox-composer').count()===0&&await page.getByText('Die Nachricht liegt im Papierkorb. Stelle sie wieder her, um zu antworten.',{exact:true}).count()===1,'trash shows restore instead of an active reply editor');
  const folderPicker=page.locator('.mailbox-folders').locator('xpath=ancestor::details[contains(@class,"module-navigation")]');if(await folderPicker.count()&&!await folderPicker.evaluate(node=>node.open))await folderPicker.locator(':scope > summary').click();
  await page.getByRole('link',{name:'Papierkorb',exact:true}).click();await page.locator('[data-mailbox-list] a[href*="mail='+id+'"]').click();const retainedDownload=await c.request.get(url({mail:id,mailbox_state:'trashed',mailbox_original:id}));check(createHash('sha256').update(await retainedDownload.body()).digest('hex')===fixture.data.hashes[mode],'trashed mail still has every original attachment byte');
  check((await submit(page,'mailbox-restore','Wiederherstellen')).status()===303,'restore returns the exact original to the inbox');await page.locator('.mailbox-composer').waitFor();check(await page.locator('[data-mailbox-reply-jump]').count()===1,'restored message can be answered directly again');

  check(await page.getByText('Verlauf der Zuständigkeit und Ablage · 5',{exact:true}).count()===1,'assignment archive restore and both trash decisions remain in the visible history');await c.close();
 }
 check(external===0,'mailbox browser makes no external transport or content requests');check(errors.length===0,'actual inbox has no JavaScript failures');
 console.log(JSON.stringify({suite:'general-mailbox-browser',states,checks,external_requests:external,javascript_errors:errors,live_transport:false}));
}finally{await browser.close();}
