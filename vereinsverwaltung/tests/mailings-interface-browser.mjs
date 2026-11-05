import {readFile,writeFile} from 'node:fs/promises';
import assert from 'node:assert/strict';
const f=JSON.parse(await readFile(process.argv[2],'utf8'));
assert.match(f.origin,/^http:\/\/127\.0\.0\.1:[0-9]+$/);
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE||'/usr/bin/chromium',args:['--no-sandbox']});
const root=f.origin+'/vereinsverwaltung/',url=root+'?view=messages';
let checks=0,states=0,posts=0,external=0;const errors=[],creations=[],prints=[];
const check=(ok,label)=>{assert.ok(ok,label);checks++;};
const values=async form=>form.evaluate(n=>[...new FormData(n).entries()]);
const request=async(context,target,entries)=>{posts++;return context.request.post(target,{data:new URLSearchParams(entries).toString(),headers:{'Content-Type':'application/x-www-form-urlencoded','Origin':f.origin},maxRedirects:0});};
try{
 for(const js of [true,false])for(const locale of ['de','en']){
  const c=await browser.newContext({javaScriptEnabled:js,viewport:{width:390,height:844}});c.setDefaultTimeout(15000);c.setDefaultNavigationTimeout(60000);
  await c.route('**/*',route=>{if(new URL(route.request().url()).origin!==f.origin){external++;return route.abort();}return route.continue();});
  c.on('page',p=>p.on('pageerror',e=>errors.push(e.message)));
  const enter=await c.request.get(f.origin+'/own-test-enter?role=admin&locale='+locale,{maxRedirects:0});check(enter.status()===303,'owned mailing account established');
  const page=await c.newPage();let response=await page.goto(url,{waitUntil:'networkidle'});check(response.status()===200,'complete mailing list opens');
  check(await page.locator('main h1').innerText()===(locale==='en'?'Group emails & letters':'Rundmails & Briefe'),'fixed heading follows operator language');
  check(await page.locator('[data-mailing-page] tbody tr').count()===6,'first actual catalogue is bounded to six rows');
  await page.goto(url+'&mailing_page=5',{waitUntil:'networkidle'});check(await page.locator('a[href*="mailing='+f.oldest+'"]').count()===1,'fifth actual page reaches earliest original beyond twenty-five');
  await page.goto(url+'&mailing_q='+encodeURIComponent('%_! ä🙂'),{waitUntil:'networkidle'});check(await page.locator('[name="mailing_q"]').inputValue()==='%_! ä🙂','literal catalogue search retains all original special characters');
  for(const bad of ['mailing_page=0','mailing_page=01','mailing_page%5B%5D=1','mailing_state%5B%5D=draft','mailing=','new_mailing=0']){
   response=await page.goto(url+'&'+bad,{waitUntil:'networkidle'});check(response.status()===422&&await page.locator('[data-mailing-edit]').count()===0,'invalid actual query never opens a substitute editable draft '+bad);
  }
  await page.goto(url+'&new_mailing=1',{waitUntil:'networkidle'});const form=page.locator('[data-mailing-edit]');
  const originalKey=await form.locator('[name="request_key"]').inputValue();
  check(originalKey.length===32,'fresh intended draft receives one create key');
  await form.locator('[name="subject"]').fill('Übermittelte Nachricht <&> 🙂 '.repeat(12));await form.locator('[name="body"]').fill('Own complete body {{ANREDE}}\nLiteral Nachricht <&> 🙂');
  if(js){const search=form.locator('[data-recipient-search]');await search.fill('Family');await search.press('ArrowDown');await search.press('Enter');check(await form.locator('[data-recipient-selected] button').count()===1,'keyboard chooser selects exact original name');}
  else await form.locator('input[name="people[]"][value="'+f.person+'"]').check();
  if(js){const search=form.locator('[data-recipient-search]');await search.fill('Original ä');await search.press('ArrowDown');await search.press('Enter');}else await form.locator('input[name="people[]"][value="'+f.second+'"]').check();
  await form.locator('input[name="mail_attachments[]"][value="'+f.attachment+'"]').check();
  const originalSubject=await form.locator('[name="subject"]').inputValue();posts++;await Promise.all([page.waitForResponse(r=>r.request().method()==='POST'),form.locator('button[type="submit"],button:not([type])').last().click()]);await page.waitForLoadState('networkidle');
  check(await form.locator('[name="request_key"]').inputValue()===originalKey&&await form.locator('[name="subject"]').inputValue()===originalSubject,'actual refused overlong subject retains its entire text and original create key');
  check(await form.locator('input[name="people[]"]:checked').count()===2&&await form.locator('input[name="mail_attachments[]"]:checked').count()===1,'actual correction keeps both people and exact attachment selected');
  await form.locator('[name="subject"]').fill('Own UI subject '+locale+' '+js+' <&> 🙂');
  const createEntries=await values(form),createTarget=new URL(await form.getAttribute('action'),root).href;
  const accepted=await request(c,createTarget,createEntries);check(accepted.status()===303,'valid complete original is accepted');const location=accepted.headers().location;check(location?.includes('mailing='),'accepted original returns explicit mailing target');const id=new URL(location,root).searchParams.get('mailing');creations.push({id,key:originalKey});
  // An explicit outcome check carries the same authored values and key; it performs no save.
  const peek=await request(c,createTarget,[...createEntries,['mailing_recheck','1']]);const peekHtml=await peek.text();check(peek.status()===200&&peekHtml.includes('data-mailing-saved')&&!peekHtml.includes('data-mailing-edit'),'read-only outcome check finds accepted create without another draft or editable replacement');
  await page.goto(url+'&mailing='+id,{waitUntil:'networkidle'});check(await page.locator('[data-mailing-saved] details').count()===1,'shared family email has one personalized preview');
  const recipient=page.locator('[data-mailing-saved] details');await recipient.locator('summary').click();check((await recipient.innerText()).includes('Own complete body')&&(await recipient.innerText()).includes('Betreff <&> Family 🙂'),'personalized human message and literal family name survive operator language');
  await page.goto(url+'&mailing='+id+'&edit_mailing=1',{waitUntil:'networkidle'});const edit=page.locator('[data-mailing-edit]');await edit.locator('[name="body"]').fill('Own newer accepted body '+locale+' '+js);const first=await values(edit),editTarget=new URL(await edit.getAttribute('action'),root).href;
  check((await request(c,editTarget,first)).status()===303,'first current revision is accepted');
  const stale=first.map(([k,v])=>[k,k==='body'?'Own retained stale body ä🙂':v]);const refused=await request(c,editTarget,stale);const refusedHtml=await refused.text();check(refused.status()===409&&refusedHtml.includes('Own retained stale body ä🙂')&&refusedHtml.includes('data-mailing-recheck')&&!refusedHtml.includes('data-mailing-edit'),'actual stale revision retains human original without replacing the old hash');
  const compared=await request(c,editTarget,[...stale,['mailing_recheck','1']]);check(compared.status()===200&&!(await compared.text()).includes('data-mailing-edit'),'conscious stale comparison cannot silently unlock a newer hash');
  const malformed=first.filter(([k])=>k!=='request_key');malformed.push(['request_key[]','Own exact invalid shape']);const wrong=await request(c,editTarget,malformed);check(wrong.status()===422&&(await wrong.text()).includes('Own exact invalid shape'),'structured original metadata is preserved without substitute key');
  const wrongRoute=await request(c,root+'?view=settings&tab=account',stale);check(wrongRoute.status()===422&&(await wrongRoute.text()).includes('Own retained stale body ä🙂'),'wrong page displays original correction without executing a mailing command');
  await page.goto(url+'&mailing='+id,{waitUntil:'networkidle'});const printForm=page.locator('form:has(input[name="action"][value="mailing-print"])');const printEntries=await values(printForm),printTarget=new URL(await printForm.getAttribute('action'),root).href;
  const pdf1=await request(c,printTarget,printEntries),pdf2=await request(c,printTarget,printEntries);check(pdf1.status()===303&&pdf1.headers().location===pdf2.headers().location&&pdf2.status()===303,'same explicit print request returns the same original PDF document');prints.push({id,key:printEntries.find(([k])=>k==='print_key')[1],document:new URL(pdf1.headers().location,root).searchParams.get('doc')});
  for(const width of [320,390,820,1440])for(const zoom of [1,2]){
   await page.setViewportSize({width,height:900});await page.evaluate(size=>document.documentElement.style.setProperty('font-size',size+'px','important'),16*zoom);
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'mailing detail fits viewport '+width+' font '+zoom);states++;
  }
  await page.screenshot({path:f.root+'/mailings-ui-'+locale+'-'+(js?'js':'basic')+'.png',fullPage:true});await c.close();
 }
 for(const role of ['editor','reader','member']){
  const c=await browser.newContext();await c.request.get(f.origin+'/own-test-enter?role='+role+'&locale=en',{maxRedirects:0});const response=await c.request.get(url);const html=await response.text();check(response.status()===403&&!html.includes('Own earlier literal'),'current non-board role receives no saved mailing data '+role);await c.close();
 }
 check(external===0&&errors.length===0,'no external requests or browser script errors');
 const result={suite:'mailings-interface-browser',checks,profiles:4,states,posts,external_requests:external,script_errors:errors,creations,prints};await writeFile(f.root+'/mailings-ui-result.json',JSON.stringify(result,null,2));console.log(JSON.stringify(result));
}finally{await browser.close();}
