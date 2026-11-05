import fs from 'node:fs/promises';
import assert from 'node:assert/strict';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE);
const f=JSON.parse(await fs.readFile(process.env.DVM_PRESS_RECIPIENTS_FIXTURE,'utf8'));
const browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE,args:['--no-sandbox','--disable-dev-shm-usage']});
let checks=0,posts=0;const errors=[],outside=[];
const check=(ok,label)=>{assert.ok(ok,label);checks++;console.log('PASS '+label);};
const picker=page=>page.locator('[data-press-picker]');
const form=page=>page.locator('form').filter({has:page.locator('[data-press-picker]')});
const open=async page=>{
 const settings=page.locator('.compose-settings');if(await settings.getAttribute('open')===null)await settings.locator(':scope > summary').click();
 if(await picker(page).getAttribute('open')===null)await picker(page).locator(':scope > summary').click();
};
const start=async(page,mode,module='press')=>{const response=await page.goto(f.origin+'/own-test-enter?mode='+mode+'&module='+module);check(response.status()===200,'actual '+mode+' controller renders authenticated own '+module+' composer');if(module==='press')await open(page);};
const browse=async(page,value)=>{
 const response=page.waitForResponse(r=>r.request().method()==='POST');
 await picker(page).locator('button[name=press_browse][value="'+value+'"]').click();check((await response).status()===200,'read-only '+value+' completes');
 await page.waitForFunction(()=>{const p=document.querySelector('[data-press-picker]');return p&&!p.hasAttribute('aria-busy');});
};
const fit=async(page,label)=>{
 const r=await picker(page).evaluate(root=>({right:root.getBoundingClientRect().right,width:document.documentElement.clientWidth,overflow:root.scrollWidth>root.clientWidth+2,height:root.getBoundingClientRect().height,small:[...root.querySelectorAll('button,input[type=search],.press-recipient-option,.press-recipient-name,.press-recipient-contact>summary')].filter(e=>e.getClientRects().length&&e.getBoundingClientRect().height>0).filter(e=>e.getBoundingClientRect().height<44||parseFloat(getComputedStyle(e).fontSize)<16).map(e=>({text:e.textContent,height:e.getBoundingClientRect().height,font:getComputedStyle(e).fontSize}))}));
 check(r.right<=r.width+2&&!r.overflow,'picker fits '+label+' '+JSON.stringify(r));check(!r.small.length,'touch targets fit '+label+' '+JSON.stringify(r.small));
 if(r.height>2300){await fs.mkdir('.local/browser-tests/press-recipients',{recursive:true});await picker(page).screenshot({path:'.local/browser-tests/press-recipients/overflow.png'});console.log(await picker(page).evaluate(e=>[...e.querySelectorAll('.press-recipient-option:first-of-type,.press-recipient-option:first-of-type>*')].map(n=>({tag:n.tagName,width:n.getBoundingClientRect().width,height:n.getBoundingClientRect().height,font:getComputedStyle(n).fontSize,minWidth:getComputedStyle(n).minWidth}))));}
 check(r.height<(label.endsWith('/2x')?2300:1400),'six contacts stay bounded '+label+' '+r.height);
};
const text=async(page,js)=>{
 await form(page).locator('[name=title]').fill('Unsaved human release');await form(page).locator('[name=summary]').fill('Unsaved human introduction');
 if(js)await form(page).locator('[contenteditable=true]').first().fill('Unsaved human body');
 else await form(page).locator('textarea[name=body_html]').fill('<p>Unsaved human body</p>');
};
const retained=async(page,js)=>{
 check(await form(page).locator('[name=title]').inputValue()==='Unsaved human release'&&await form(page).locator('[name=summary]').inputValue()==='Unsaved human introduction','search keeps exact human title and introduction');
 const body=js?await form(page).locator('[contenteditable=true]').first().textContent():await form(page).locator('textarea[name=body_html]').inputValue();
 check(body===(js?'Unsaved human body':'<p>Unsaved human body</p>'),'search keeps exact human body without a save');
 check(await form(page).locator('[name=version]').inputValue()==='0','search leaves the original new-draft concurrency version');
};
try{
 for(const mode of ['native','integrated'])for(const module of ['press','newsletter','social'])for(const js of [true,false])for(const width of [320,390,820])for(const zoom of [1,2]){
  const context=await browser.newContext({viewport:{width,height:1100},javaScriptEnabled:js,hasTouch:true});const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));context.on('request',r=>{if(!r.url().startsWith(f.origin+'/'))outside.push(r.url());});
  await start(page,mode,module);const settings=page.locator('.compose-settings');if(await settings.getAttribute('open')===null)await settings.locator(':scope > summary').click();if(zoom===2)await page.evaluate(()=>document.documentElement.style.fontSize='200%');
  const metrics=await page.locator('.compose-paper').evaluate(root=>({right:root.getBoundingClientRect().right,width:document.documentElement.clientWidth,header:root.querySelector('.compose-heading').scrollWidth>root.querySelector('.compose-heading').clientWidth+2,small:[...root.querySelectorAll('.compose-heading button,.compose-writing input:not([type=hidden]):not([type=checkbox]),.compose-writing select,.compose-writing textarea,.compose-writing button,.compose-writing label.check,.compose-settings>summary,.compose-blocks>summary')].filter(e=>e.getClientRects().length&&e.getBoundingClientRect().height>0).filter(e=>e.getBoundingClientRect().height<44||parseFloat(getComputedStyle(e).fontSize)<16).map(e=>({name:e.name||e.textContent,height:e.getBoundingClientRect().height,font:getComputedStyle(e).fontSize}))}));
  check(metrics.right<=metrics.width+2&&!metrics.header,'actual writing header fits '+mode+'/'+module+'/'+js+'/'+width+'/'+zoom+'x '+JSON.stringify(metrics));check(!metrics.small.length,'actual writing controls meet 44px/16px '+JSON.stringify(metrics.small));await context.close();
 }
 for(const mode of (process.env.DVM_PRESS_FLOW_ONLY==='1'?[]:['native','integrated']))for(const js of [true,false])for(const width of [320,390,820])for(const zoom of [1,2]){
  const context=await browser.newContext({viewport:{width,height:1100},javaScriptEnabled:js,hasTouch:true});const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));context.on('request',r=>{if(!r.url().startsWith(f.origin+'/'))outside.push(r.url());});
  await start(page,mode);if(zoom===2)await page.evaluate(()=>document.documentElement.style.fontSize='200%');await fit(page,mode+'/'+js+'/'+width+'/'+zoom+'x');
  check(await picker(page).locator('[name="press_choice[]"]').count()===6,'901 active contacts render exactly six native choices');
  check(await picker(page).locator('.press-recipient-contact[open]').count()===0,'individual contact addresses start compact');const first=picker(page).locator('.press-recipient-contact').nth(0),second=picker(page).locator('.press-recipient-contact').nth(1);
  await first.locator('summary').focus();await page.keyboard.press('Enter');check(await first.getAttribute('open')!==null&&await first.locator('small').isVisible(),'keyboard reveals the chosen original email');await second.locator('summary').focus();await page.keyboard.press('Enter');check(await first.getAttribute('open')===null&&await second.getAttribute('open')!==null,'only one contact detail opens at a time');await page.keyboard.press('Enter');check(await second.getAttribute('open')===null,'keyboard closes the same contact without a write');
  await picker(page).locator(':scope > summary').focus();await page.keyboard.press('Enter');check(await picker(page).getAttribute('open')===null,'keyboard collapses only the chooser');await page.keyboard.press('Enter');check(await picker(page).getAttribute('open')!==null,'keyboard returns to same unsaved chooser');
  await context.close();
 }
 for(const mode of ['native','integrated'])for(const js of [true,false]){
  const context=await browser.newContext({viewport:{width:390,height:900},hasTouch:true,javaScriptEnabled:js});const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));context.on('request',r=>{if(r.method()==='POST')posts++;if(!r.url().startsWith(f.origin+'/'))outside.push(r.url());});
  await start(page,mode);
  // Empty title is allowed for searching. Enter must search, never silently save.
  await picker(page).locator('[name=press_query]').fill('Redaktion 0900');const enter=page.waitForResponse(r=>r.request().method()==='POST');await picker(page).locator('[name=press_query]').press('Enter');const entered=await enter;if(entered.status()!==200)console.log('Own search error',entered.status(),(await entered.text()).match(/<p>([^<]*)<\/p>/)?.[1]);check(entered.status()===200,'Enter searches with an incomplete title');await page.waitForFunction(()=>document.querySelector('[data-press-picker] [name="press_choice[]"]')?.closest('article').textContent.includes('0900'));
  check(await form(page).locator('[name=version]').inputValue()==='0'&&await form(page).locator('[name=id]').inputValue()==='','Enter search creates no hidden draft');
  await picker(page).locator('[name=press_query]').fill('');await browse(page,'search');await text(page,js);
  await picker(page).locator('[name="press_choice[]"][value="'+f.first+'"]').check();await browse(page,'next');await retained(page,js);
  await picker(page).locator('[name="press_choice[]"][value="'+f.second+'"]').check();await picker(page).locator('[name=press_query]').fill('%_!');await browse(page,'search');await retained(page,js);
  check(await picker(page).locator('[name="press_choice[]"]').count()===1,'literal wildcard characters match one own outlet');await picker(page).locator('[name="press_choice[]"][value="'+f.literal+'"]').check();await browse(page,'selected');await retained(page,js);
  check(await picker(page).locator('[name="press_choice[]"]:checked').count()===3,'all three page-spanning originals remain chosen');
  await picker(page).locator('[name="press_choice[]"][value="'+f.first+'"]').uncheck();await browse(page,'active');await retained(page,js);check((await picker(page).locator('[data-press-count]').textContent()).includes('2 ausgewählt'),'explicit visible removal keeps exactly two hidden original selections');
  if(js){
   await form(page).evaluate(form=>{const input=document.createElement('input');input.type='file';input.name='private_search_fixture';input.hidden=true;const transfer=new DataTransfer();transfer.items.add(new File([new Uint8Array(512000)],'Private original.dat'));input.files=transfer.files;form.append(input);});
   // The editor may change while the read request is pending. It is never replaced.
   let release;const gate=new Promise(resolve=>release=resolve);let intercepted=false;
   const handler=async route=>{if(route.request().method()==='POST'&&!intercepted){intercepted=true;await gate;}await route.continue();};await page.route('**/*',handler);
   const response=page.waitForResponse(r=>r.request().method()==='POST');await picker(page).locator('[value=next]').click();await form(page).locator('[name=summary]').fill('Human text typed during search');release();const searched=await response;check(searched.status()===200,'slow read-only search completes once');check(!searched.request().postData().includes('Private original.dat'),'read-only search uploads no unrelated original file');await page.waitForFunction(()=>!document.querySelector('[data-press-picker]')?.hasAttribute('aria-busy'));
   check(await form(page).locator('[name=private_search_fixture]').evaluate(input=>input.files.length===1&&input.files[0].size===512000),'search leaves the unsaved original file selection intact');await form(page).locator('[name=private_search_fixture]').evaluate(input=>input.remove());
   check(await form(page).locator('[name=summary]').inputValue()==='Human text typed during search','late search never replaces text typed during its request');await form(page).locator('[name=summary]').fill('Unsaved human introduction');await page.unroute('**/*',handler);
   // An interrupted response preserves the actual chooser and dirty text; no retry.
   const failed=async route=>{if(route.request().method()==='POST')await route.abort('failed');else await route.continue();};await page.route('**/*',failed);const before=posts;await picker(page).locator('[value=next]').click();await picker(page).locator('[data-press-error]').waitFor({state:'visible'});await retained(page,js);check(posts===before+1,'lost search response makes no automatic second request');await page.unroute('**/*',failed);
  }
  await fit(page,mode+'/flow/390/1x');const saved=page.waitForResponse(r=>r.request().method()==='POST');await form(page).getByRole('button',{name:'Entwurf speichern',exact:true}).first().click();check((await saved).status()<400,'only explicit save reaches actual '+mode+' controller');
  await page.waitForFunction(mode=>new URL(location.href).searchParams.get(mode==='native'?'item':'native_item')?.length===32,mode);check(await form(page).locator('[name=version]').inputValue()==='1','explicit save creates exactly version one');
  // Submit an actual outdated form without changing the independently stored draft.
  await form(page).locator('[name=version]').evaluate(input=>input.value='0');await form(page).locator('[name=title]').fill('Human text after stale version');const conflict=page.waitForResponse(r=>r.request().method()==='POST'),navigation=js?Promise.resolve():page.waitForNavigation({waitUntil:'domcontentloaded'});await form(page).getByRole('button',{name:'Entwurf speichern',exact:true}).first().click();check((await conflict).status()>=400,'actual stale form is rejected by '+mode+'/'+js);await navigation;
  await page.waitForFunction(()=>document.querySelector('form [name=title][value="Human text after stale version"]')||[...document.querySelectorAll('[name=title]')].some(input=>input.value==='Human text after stale version'));
  check(await form(page).locator('[name=title]').inputValue()==='Human text after stale version'&&await form(page).locator('[name=version]').inputValue()==='0','rejected save retains typed text and the outdated version without overwriting');
  await context.close();
 }
 check(posts===36,'exactly thirty-six explicit search/save requests; no automatic retries or background saves');
 check(!outside.length,'all browser flows use own loopback controller without external services');check(!errors.length,'no browser errors '+errors.join(';'));
 await fs.mkdir('.local/browser-tests/press-recipients',{recursive:true});const context=await browser.newContext({viewport:{width:390,height:844}});const page=await context.newPage();await start(page,'integrated');await picker(page).screenshot({path:'.local/browser-tests/press-recipients/mobile.png'});await context.close();
 console.log(JSON.stringify({suite:'press-recipients-browser',checks,posts,status:'passed',live_transport:false}));
}finally{await browser.close();}
