import fs from 'node:fs/promises';
import assert from 'node:assert/strict';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE);
const fixture=JSON.parse(await fs.readFile(process.env.DVM_PRESS_RECIPIENTS_FIXTURE,'utf8'));
const browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE,args:['--no-sandbox','--disable-dev-shm-usage']});
let checks=0,posts=0;const errors=[],outside=[];
const check=(ok,label)=>{assert.ok(ok,label);checks++;console.log('PASS '+label);};
const list=page=>page.locator('.contact-list > form').first();
const profile=page=>page.locator('.contact-profile form').filter({has:page.locator('[name=operation][value=newsletter-profile]')});
const track=page=>{page.on('pageerror',error=>errors.push(error.message));page.on('request',request=>{if(request.method()==='POST')posts++;if(!request.url().startsWith(fixture.origin+'/'))outside.push(request.url());});};
const start=async(page,mode)=>{const response=await page.goto(fixture.origin+'/own-test-enter?mode='+mode+'&module=newsletter');check(response.status()===200,'actual '+mode+' authenticated recipient manager renders');await page.locator('.newsletter-management').waitFor();};
const submit=async(page,button,status=200)=>{const before=posts;const response=page.waitForResponse(r=>r.request().method()==='POST');const navigation=page.waitForNavigation({waitUntil:'load'});await button.click();const actual=await response;check(actual.status()===status,'actual explicit recipient request returns '+status);await actual.finished();await navigation;await page.locator('.newsletter-management').waitFor();check(posts===before+1,'one explicit gesture makes exactly one POST');};
const browse=async(page,value)=>submit(page,list(page).locator('[name=contact_browse][value="'+value+'"]'));
const search=async(page,text)=>{await list(page).locator('[name=contact_query]').fill(text);await browse(page,'search');};
const fit=async(page,label)=>{
 const metrics=await page.locator('.newsletter-management').evaluate(root=>({right:root.getBoundingClientRect().right,width:document.documentElement.clientWidth,overflow:root.scrollWidth>root.clientWidth+2,wide:[...root.querySelectorAll('*')].filter(node=>node.getClientRects().length&&node.getBoundingClientRect().right>root.getBoundingClientRect().right+2).map(node=>({tag:node.tagName,name:node.name,right:node.getBoundingClientRect().right})).slice(0,8),small:[...root.querySelectorAll('button,input:not([type=hidden]):not([type=checkbox]),select,summary,.contact-select,.contact-confirm')].filter(node=>node.getClientRects().length&&node.getBoundingClientRect().height>0).filter(node=>node.getBoundingClientRect().height<43.5||parseFloat(getComputedStyle(node).fontSize)<16).map(node=>({name:node.name||node.textContent.slice(0,50),height:node.getBoundingClientRect().height,font:getComputedStyle(node).fontSize}))}));
 check(metrics.right<=metrics.width+2&&!metrics.overflow,'recipient content fits '+label+' '+JSON.stringify(metrics));
 check(!metrics.small.length,'recipient controls meet touch sizing '+label+' '+JSON.stringify(metrics.small));
};
try{
 for(const mode of ['native','integrated'])for(const js of [true,false])for(const width of [320,390,820])for(const zoom of [1,2]){
  const context=await browser.newContext({viewport:{width,height:1000},javaScriptEnabled:js,hasTouch:true});const page=await context.newPage();track(page);await start(page,mode);
  if(zoom===2)await page.locator('.newsletter-management').evaluate(root=>root.ownerDocument.documentElement.style.fontSize='200%');
  check(await page.locator('.newsletter-contact').count()===6,'only six populated originals shown '+mode+'/'+js+'/'+width+'/'+zoom);
  check(await page.locator('.contact-profile').count()===0,'no long profile or history starts open');
  await fit(page,mode+'/'+js+'/'+width+'/'+zoom);
  await submit(page,list(page).locator('button[name=contact_browse]').filter({hasText:'Bearbeiten & Verlauf'}).first());
  if(zoom===2)await page.locator('.newsletter-management').evaluate(root=>root.ownerDocument.documentElement.style.fontSize='200%');
  await profile(page).locator('[name=name]').waitFor({state:'visible'});
  check(await page.locator('.contact-profile').count()===1&&await profile(page).locator('[name=name]').isVisible(),'one original profile opens deliberately');
  check(await page.locator('.contact-history').getAttribute('open')===null,'complete original history begins collapsed');
  await page.locator('.contact-history > summary').focus();await page.keyboard.press('Enter');
  check(await page.locator('.contact-history').getAttribute('open')!==null,'keyboard opens original consent history');await fit(page,mode+'/profile/'+js+'/'+width+'/'+zoom);
  await context.close();
 }
 let flow=0;
 for(const mode of ['native','integrated'])for(const js of [true,false]){
  const context=await browser.newContext({viewport:{width:390,height:900},javaScriptEnabled:js,hasTouch:true});const page=await context.newPage();track(page);await start(page,mode);
  await search(page,'browser'+flow+'@example.invalid');await submit(page,list(page).getByRole('button',{name:'Bearbeiten & Verlauf'}));
  const initialVersion=await profile(page).locator('[name=version]').inputValue();const human='Menschlicher Name '+mode+' '+js;
  await profile(page).locator('[name=name]').fill(human);await profile(page).locator('[name="purposes[]"][value=news]').uncheck();
  await submit(page,profile(page).getByRole('button',{name:'Änderungen speichern'}));
  check(await profile(page).locator('[name=name]').inputValue()===human&&await profile(page).locator('[name=version]').inputValue()===String(Number(initialVersion)+1),'actual profile creates one new version with human name');
  check(await profile(page).locator('[name="purposes[]"][value=news]').count()===0,'removed consent topic cannot be added by profile edit');
  await profile(page).locator('[name=version]').evaluate((input,value)=>input.value=value,initialVersion);await profile(page).locator('[name=name]').fill('Ungespeicherter menschlicher Text');
  await submit(page,profile(page).getByRole('button',{name:'Änderungen speichern'}),422);
  check(await profile(page).locator('[name=name]').inputValue()==='Ungespeicherter menschlicher Text'&&await profile(page).locator('[name=version]').inputValue()===initialVersion,'actual stale error retains human text and its original version '+mode+'/'+js);
  await submit(page,page.locator('.contact-profile').getByRole('button',{name:'Bearbeitung schließen'}));
  await search(page,'browser'+(flow+4)+'@example.invalid');await list(page).locator('[name="choice[]"]').check();
  await search(page,'browser'+(flow+10)+'@example.invalid');
  check(await list(page).locator('[name^="selected["]').count()===1,'search preserves the earlier off-page selection');await list(page).locator('[name="choice[]"]').check();await browse(page,'selected');
  check(await page.locator('.newsletter-contact').count()===2&&await list(page).locator('[name="choice[]"]:checked').count()===2,'selected view shows both exact originals across pages');
  await page.locator('.contact-unsubscribe > summary').click();await list(page).locator('[name=reason]').fill('Menschliche Abmeldung '+mode+' '+js);
  await submit(page,list(page).getByRole('button',{name:'Auswahl abmelden'}),422);
  check(await list(page).locator('[name=reason]').inputValue()==='Menschliche Abmeldung '+mode+' '+js&&await list(page).locator('[name="choice[]"]:checked').count()===2,'rejected bulk request retains its exact selection and human reason');
  await page.locator('.contact-unsubscribe > summary').click();await list(page).locator('[name=confirm_unsubscribe]').check();await submit(page,list(page).getByRole('button',{name:'Auswahl abmelden'}));
  check(await list(page).locator('[name^="selected["]').count()===0,'successful group clears deliberate selection');
  await browse(page,'all');await list(page).locator('[name=contact_status]').selectOption('unsubscribed');await list(page).locator('[name=contact_query]').fill('browser');await browse(page,'search');
  check(await page.locator('.newsletter-contact .contact-status').count()>=2&&await page.locator('.newsletter-contact .contact-status').evaluateAll(nodes=>nodes.every(n=>n.textContent==='Abgemeldet')),'actual status filter shows preserved unsubscribed originals');
  await fit(page,mode+'/complete/'+js);flow++;await context.close();
 }
 check(!errors.length,'no actual browser exceptions '+errors.join(';'));check(!outside.length,'every browser request stays on own loopback fixture');
 console.log(JSON.stringify({suite:'newsletter-contacts-browser',checks,posts,live_transport:false,status:'passed'}));
}finally{await browser.close();}
