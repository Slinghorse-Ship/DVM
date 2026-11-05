import assert from 'node:assert/strict';
import {readFile,writeFile} from 'node:fs/promises';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const f=JSON.parse(await readFile(process.env.DVM_SOCIAL_PLANNING_FIXTURE,'utf8'));
const browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE||undefined});
let checks=0,views=0,currentPage=null;const errors=[],external=[],saves=[];
const check=(ok,label)=>{assert(ok,label);checks++;};
const en={history:'Planning and history',delivery:'Delivery history',pause:'Pause',resume:'Resume',uncertain:'Planning change unconfirmed.',paused:'Preparation paused',enabled:'Preparation scheduled'};
const de={history:'Planung & Verlauf',delivery:'Zustellverlauf',pause:'Pausieren',resume:'Fortsetzen',uncertain:'Planung nicht bestätigt.',paused:'Vorbereitung pausiert',enabled:'Vorbereitung geplant'};
try{
 for(const mode of ['native','integrated'])for(const locale of ['de','en'])for(const js of [true,false])for(const module of ['social','newsletter']){
  if(process.env.DVM_SOCIAL_PLANNING_SMOKE==='1'&&(locale!=='en'||module!=='social'))continue;
  const t=locale==='en'?en:de,profile=[mode,locale,js?'js':'nojs',module].join('-');let nextFault=null,posts=0;
  const ctx=await browser.newContext({viewport:{width:1440,height:1050},hasTouch:true,javaScriptEnabled:js,serviceWorkers:'block'});
  await ctx.route('**/*',r=>{
   if(new URL(r.request().url()).origin!==f.origin){external.push(r.request().url());return r.abort();}
   if(r.request().method()==='POST'){
    posts++;const headers={...r.request().headers()};
    if(nextFault){headers[nextFault==='sql'?'x-own-planning-sql-failure':'x-own-planning-hide-ack']='once';nextFault=null;}
    return r.continue({headers});
   }
   return r.continue();
  });
  const page=await ctx.newPage();currentPage=page;page.on('pageerror',e=>errors.push(e.message));
  await page.goto(f.origin+'/own-test-enter?mode='+mode+'&locale='+locale,{waitUntil:'load'});
  const base=new URL(page.url());base.searchParams.set('view',module);base.searchParams.set('section','planning');base.searchParams.set('planning_view','history');base.searchParams.set('plan_month','2026-11');base.searchParams.set('plan_channel','all');base.searchParams.set('plan_step','automation');
  await page.goto(base.href,{waitUntil:'load'});views++;
  check((await page.locator(module==='social'?'.publication-history h2':'.newsletter-deliveries h2').first().innerText())===(module==='social'?t.history:t.delivery),profile+' actual history heading is localized');
  const rows=module==='social'?'.publication-row':'.newsletter-delivery-card';
  check(await page.locator(rows).count()===(module==='social'?10:6),profile+' complete short page has exact original rows');
  check((await page.locator(rows+' h3').allTextContents()).includes('Pausieren <literal> ä🙂')&&!((await page.locator(rows).allTextContents()).join(' ').includes('Own later unapproved title')),profile+' original human source stays literal after later source changes');
  if(module==='social'){
   check((await page.locator('.publication-history').innerText()).includes(locale==='en'?'Original revision unavailable':'Originalfassung nicht verfügbar'),profile+' unavailable original is a fixed localized label');
   if(mode==='integrated'&&locale==='en')check(await page.locator('a').filter({hasText:'<Own Month>'}).count()===1&&await page.locator('own').count()===0,profile+' personal HTML-like month label stays escaped literal text');
  }
  const next=page.locator(module==='social'?'.website-pagination a[href*="queue_page=2"]':'.newsletter-delivery-pages a[href*="newsletter_page=2"]').first();
  check((new URL(await next.getAttribute('href'),page.url())).searchParams.get('planning_view')==='history',profile+' pagination keeps explicit history mode');
  await next.tap();await page.waitForFunction(key=>new URL(location.href).searchParams.get(key)==='2',module==='social'?'queue_page':'newsletter_page');views++;
  check(new URL(page.url()).searchParams.get('plan_month')==='2026-11',profile+' actual paging keeps origin month');
  const form=page.locator(module==='social'?'.publication-filters':'.newsletter-delivery-filter').first();
  await form.locator('[name="'+(module==='social'?'queue_state':'newsletter_state')+'"]').selectOption('uncertain');await form.locator('button').tap();
  await page.waitForFunction(key=>new URL(location.href).searchParams.get(key)==='uncertain',module==='social'?'queue_state':'newsletter_state');views++;
  check(new URL(page.url()).searchParams.get('plan_month')==='2026-11'&&new URL(page.url()).searchParams.get('planning_view')==='history'&&posts===0,profile+' history filter is a pure GET and retains the original month');
  if(module==='newsletter'){
   await page.locator('.newsletter-delivery-card a').first().tap();await page.locator('.newsletter-delivery-focus').waitFor();views++;
   check((await page.locator('.newsletter-delivery-original').innerText())==='Pausieren <literal> ä🙂'&&(await page.locator('.newsletter-delivery-focus').innerText()).includes(locale==='en'?'Interrupted delivery · review result':'Unterbrochener Versand · Ergebnis prüfen'),profile+' selected original and fixed delivery reason remain separate');
  }
  await page.waitForLoadState('load');await page.evaluate(()=>document.fonts.ready);
  for(const [width,height,size]of[[390,844,'phone'],[820,1180,'tablet'],[1440,1050,'desktop']]){
   await page.setViewportSize({width,height});await page.waitForFunction(()=>document.documentElement.scrollWidth<=innerWidth+1);
   const layout=await page.evaluate(()=>({scroll:document.documentElement.scrollWidth,width:innerWidth}));check(layout.scroll<=layout.width+1,profile+' '+size+' has no page overflow '+JSON.stringify(layout));
   if(mode==='integrated'&&locale==='en'&&js)await page.screenshot({path:f.root+'/planning-'+profile+'-'+size+'.png',fullPage:true,animations:'disabled'});views++;
  }
  await page.setViewportSize({width:390,height:844});await page.evaluate(()=>document.documentElement.style.fontSize='32px');
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),profile+' enlarged text stays readable without page overflow');views++;
  await page.evaluate(()=>document.documentElement.style.removeProperty('font-size'));
  const original=new URL(base);original.searchParams.set('planning_view','automation');
  if(module==='social'){original.searchParams.set('queue_page','2');original.searchParams.set('queue_provider','meta');original.searchParams.set('queue_state','all');}
  else{original.searchParams.set('newsletter_page','2');original.searchParams.set('newsletter_kind','all');original.searchParams.set('newsletter_state','all');}
  await page.goto(original.href,{waitUntil:'load'});views++;
  const id=f.plans[mode][locale][module][js?'js':'nojs'];
  const planForm=()=>page.locator('form[data-planning-action]').filter({has:page.locator('input[name=automation_id][value="'+id+'"]')});
  const show=async()=>{const details=planForm().locator('xpath=ancestor::details[1]');if(await details.count()&&!await details.evaluate(e=>e.open))await details.locator(':scope>summary').tap();await planForm().locator('button').scrollIntoViewIfNeeded();};
  await show();check(await planForm().locator('[name=version]').inputValue()==='1',profile+' first action uses the real original version');
  const initial=await planForm().evaluate(e=>Object.fromEntries(new FormData(e)));
  check((await planForm().locator('button').boundingBox()).height>=44,profile+' action is a working touch target');
  await planForm().locator('[name=csrf]').evaluate(e=>e.value='own-expired-csrf');await planForm().locator('button').tap();
  const expired=js?planForm().locator('[data-planning-error]'):page.locator('.planning-recovery [role=alert]');await expired.waitFor({state:'visible'});views++;
  check((await expired.innerText()).includes(locale==='en'?'Form expired.':'Formular abgelaufen.'),profile+' actual expired form is localized without a mutation');
  await (js?planForm().locator('[data-planning-check]'):page.locator('.planning-recovery [data-planning-read]')).tap();
  await page.waitForFunction(id=>{const form=document.querySelector('input[name=automation_id][value="'+id+'"]')?.form;return form&&!form.querySelector('button').disabled;},id);await show();views++;
  nextFault='sql';await planForm().locator('button').tap();
  const issue=js?planForm().locator('[data-planning-error]'):page.locator('.planning-recovery [role=alert]');await issue.waitFor({state:'visible'});views++;
  check((await issue.innerText()).includes(t.uncertain)&&!((await issue.innerText()).includes('SQLSTATE')),profile+' actual SQL rollback shows localized uncertainty and hides database details');
  if(js)check(await planForm().locator('button').isDisabled(),profile+' ambiguous result cannot be resent before a fresh read');
  const read=js?planForm().locator('[data-planning-check]'):page.locator('.planning-recovery [data-planning-read]');
  check((new URL(await read.getAttribute('href'),page.url())).searchParams.get('planning_view')==='automation',profile+' recovery keeps automatic preparation mode');
  await read.focus();await page.keyboard.press('Enter');await page.waitForFunction(id=>{const form=document.querySelector('input[name=automation_id][value="'+id+'"]')?.form;return form&&!form.querySelector('button').disabled;},id);await show();views++;
  check(await planForm().locator('[name=version]').inputValue()==='1',profile+' pure GET confirms definite rollback without a second POST');
  if(js)nextFault='ack';await planForm().locator('button').tap();
  if(js){await planForm().locator('[data-planning-error]').waitFor({state:'visible'});check((await planForm().locator('[data-planning-error]').innerText()).includes(t.uncertain)&&await planForm().locator('button').isDisabled(),profile+' actual accepted COMMIT with withheld acknowledgement keeps a safe status check');await planForm().locator('[data-planning-check]').tap();}
  await page.waitForFunction(({id})=>document.querySelector('input[name=automation_id][value="'+id+'"]')?.form.querySelector('[name=version]')?.value==='2',{id});await show();views++;
  check((await planForm().locator('button').innerText())===t.resume&&await planForm().locator('[name=paused]').inputValue()==='0',profile+' fresh status shows exactly one accepted pause and a conscious resume');
  const replay=await ctx.request.post(original.href,{form:initial,headers:{Accept:'application/json'},maxRedirects:0});
  check(replay.status()===422&&(await replay.json()).error===(locale==='en'?'The plan has changed or is unavailable.':'Planung inzwischen geändert oder nicht verfügbar.'),profile+' real old POST replay is refused without a second version');
  await planForm().locator('button').tap();
  await page.waitForFunction(({id})=>document.querySelector('input[name=automation_id][value="'+id+'"]')?.form.querySelector('[name=version]')?.value==='3',{id});await show();views++;
  check((await planForm().locator('button').innerText())===t.pause&&new URL(page.url()).searchParams.get('planning_view')==='automation'&&new URL(page.url()).searchParams.get('plan_month')==='2026-11',profile+' conscious resume returns the same view month and current status');
  saves.push({mode,locale,js,module,id,version:3,status:'enabled'});await ctx.close();console.log(JSON.stringify({profile,checks,views}));
 }
 check(external.length===0&&errors.length===0,'no external request and no browser exception '+errors.join(';'));
 const result={suite:'social-planning-interface-browser',checks,views,profiles:saves.length,saves,external_actions:0,browser_errors:errors};await writeFile(f.root+'/planning-results.json',JSON.stringify(result,null,2));console.log(JSON.stringify({suite:result.suite,checks,views,profiles:saves.length,external_actions:0}));
}catch(error){if(currentPage&&!currentPage.isClosed()){await currentPage.screenshot({path:f.root+'/planning-failure.png',fullPage:true,animations:'disabled'});await writeFile(f.root+'/planning-failure.html',await currentPage.content());await writeFile(f.root+'/planning-failure-layout.json',JSON.stringify(await currentPage.evaluate(()=>({width:innerWidth,scroll:document.documentElement.scrollWidth,over:[...document.querySelectorAll('body *')].map(e=>({tag:e.tagName,c:e.className,x:e.getBoundingClientRect().x,w:e.getBoundingClientRect().width,right:e.getBoundingClientRect().right})).filter(e=>e.right>innerWidth+1).slice(0,20)}))));}throw error;}finally{await browser.close();}
