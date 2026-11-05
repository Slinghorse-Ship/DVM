import assert from 'node:assert/strict';
import {readFile,mkdir,writeFile} from 'node:fs/promises';
import {execFileSync} from 'node:child_process';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const f=JSON.parse(await readFile(process.env.DVM_EDITORIAL_HUB_FIXTURE,'utf8'));
const out=process.env.DVM_EDITORIAL_HUB_OUTPUT||'.local/browser-tests/editorial-hub';await mkdir(out,{recursive:true});
const browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE||undefined});
let checks=0,views=0,posts=0;const errors=[],external=[];
const check=(ok,label)=>{assert(ok,label);checks++;};
const language=locale=>execFileSync(f.php[0],[...f.php.slice(1),'vereinsverwaltung/tests/support/editorial-hub-browser-state.php',locale],{stdio:'pipe'});
try{
 for(const locale of ['de','en'])for(const mode of ['native','integrated'])for(const js of [true,false]){
  language(locale);const ctx=await browser.newContext({viewport:{width:1440,height:1050},hasTouch:true,javaScriptEnabled:js,serviceWorkers:'block'});
  await ctx.route('**/*',r=>{const req=r.request();if(new URL(req.url()).origin!==f.origin){external.push(req.url());return r.abort();}if(req.method()==='POST'){posts++;return r.abort();}return r.continue();});
  const p=await ctx.newPage();p.on('pageerror',e=>errors.push(e.message));
  await p.goto(f.origin+'/own-test-enter?mode='+mode,{waitUntil:'load'});
  const base=new URL(p.url());base.searchParams.set('planning_view','month');base.searchParams.set('plan_month','2026-11');await p.goto(base.href,{waitUntil:'load'});
  check(await p.locator('[data-editorial-calendar]').count()===1&&await p.locator('.social-plan-day').count()===42,'same real shared month has six complete Monday-first weeks');
  check(await p.locator('[data-plan-job]').count()===f.month_jobs,'all actual combined publication and preparation identities remain visible '+JSON.stringify({expected:f.month_jobs,actual:await p.locator('[data-plan-job]').count(),cards:await p.locator('[data-plan-job]').allTextContents(),url:p.url()}));
  for(const kind of ['post','story','reel','newsletter'])check(await p.locator('[data-plan-kind="'+kind+'"] .kind-'+kind).count()>0,'distinct visible '+kind+' format');
  check(await p.locator('[data-plan-module="newsletter"][data-plan-step="publication"]').count()===1,'one newsletter distribution card represents all three recipients');
  check((await p.locator('[data-editorial-hub]').innerText()).includes(locale==='en'?'Content':'Inhalte'),'shared navigation is translated');
  const path=(module,section,extra={})=>{const u=new URL(base);u.search='';u.searchParams.set('view',module);u.searchParams.set('section',section);for(const[k,v]of Object.entries(extra))u.searchParams.set(k,v);return u.href;};
  // All three new views, including their open controls, must fit the actual shell.
  for(const view of ['month','contents','automation']){
   await p.goto(view==='month'?base.href:path('newsletter',view==='contents'?'contents':'planning',view==='automation'?{planning_view:'automation'}:{}),{waitUntil:'load'});
   const scope=view==='month'?'.social-planner':view==='contents'?'.editorial-library':'.newsletter-automation';
   if(view==='month')await p.locator('.social-plan-filter-options>summary').click();
   if(view==='automation')await p.locator('.newsletter-reminder-settings>summary').click();
   for(const width of [320,820,1440])for(const zoom of [1,2]){
    await p.setViewportSize({width,height:1100});await p.evaluate(z=>document.documentElement.style.fontSize=z===2?'200%':'',zoom);
    const fit=await p.locator(scope).evaluate(e=>({overflow:document.documentElement.scrollWidth>innerWidth+2,right:e.getBoundingClientRect().right,width:innerWidth}));
    const small=await p.locator(scope).evaluate(e=>[...e.querySelectorAll('a,input:not([type=hidden]):not([type=checkbox]),select,button,summary')].filter(x=>x.getClientRects().length&&getComputedStyle(x).visibility!=='hidden').filter(x=>parseFloat(getComputedStyle(x).fontSize)<16||x.getBoundingClientRect().height<44).map(x=>({text:x.textContent.slice(0,45),font:getComputedStyle(x).fontSize,height:x.getBoundingClientRect().height})));
    check(!fit.overflow&&fit.right<=fit.width+2,'actual '+view+' view fits '+width+' '+zoom+' '+JSON.stringify(fit));check(!small.length,'actual '+view+' controls support touch and zoom '+JSON.stringify(small));views++;
   }
   await p.setViewportSize({width:1440,height:1050});await p.evaluate(()=>document.documentElement.style.fontSize='');
   if(locale==='de'&&mode==='integrated'&&js){if(view==='month')await p.locator('.social-plan-filter-options>summary').click();await p.screenshot({path:out+'/social-newsletter-'+view+'.png',fullPage:false,animations:'disabled'});await p.setViewportSize({width:390,height:1050});await p.screenshot({path:out+'/social-newsletter-'+view+'-mobil.png',fullPage:false,animations:'disabled'});await p.setViewportSize({width:1440,height:1050});}
  }
  await p.goto(base.href,{waitUntil:'load'});
  for(const[module,item]of [['social',f.prepared_story],['newsletter',f.prepared_newsletter]]){
   const link=p.locator('[data-plan-module="'+module+'"][data-plan-step="automation"].state-prepared').first();await link.focus();await p.keyboard.press('Enter');if(module==='social'){await p.locator('#social-image-editor').waitFor({state:'visible'});if(js)await p.waitForFunction(()=>!!document.querySelector('[data-canvas-editor]')?.studio);check(await p.locator('[data-story-editor-details]').evaluate(node=>!node.open)&&(await p.locator('#social-compose input[name=title]').inputValue())==='Story vor dem Kulturabend','prepared Story opens its actual portrait artwork with exact metadata secondary');}else await p.locator('input[name=title]').first().waitFor();check(new URL(p.url()).searchParams.get(mode==='integrated'?'native_item':'item')===item,'prepared '+module+' card opens its actual new unapproved draft');await p.goto(base.href,{waitUntil:'load'});
  }
  await p.locator('[data-plan-module="newsletter"][data-plan-step="publication"]').tap();await p.locator('input[name=title]').first().waitFor();check(new URL(p.url()).searchParams.get(mode==='integrated'?'native_item':'item')===f.newsletter_item&&(await p.locator('input[name=title]').first().inputValue())===f.newsletter_title,'distribution card opens its exact newsletter editor');
  await p.goto(base.href,{waitUntil:'load'});await p.locator('.social-plan-filter-options>summary').click();await p.locator('[name=plan_channel]').selectOption('newsletter');await p.locator('.social-plan-filters button').click();await p.waitForURL(u=>u.searchParams.get('plan_channel')==='newsletter');check(await p.locator('[data-plan-job]').count()>0&&await p.locator('[data-plan-module="social"]').count()===0,'actual GET filters only the selected newsletter channel');await p.locator('a[rel=next]').tap();await p.waitForURL(u=>u.searchParams.get('plan_month')==='2026-12');check(await p.locator('[name=plan_channel]').inputValue()==='newsletter','shared month movement retains newsletter filter');
  await p.goto(path('social','contents'),{waitUntil:'load'});let identities=[];
  while(true){identities.push(...await p.locator('[data-editorial-item]').evaluateAll(es=>es.map(e=>e.dataset.editorialModule+':'+e.dataset.editorialItem)));const next=p.locator('.editorial-library-pages a').filter({hasText:locale==='en'?'Next':'Nächste'});if(!await next.count())break;const target=new URL(await next.getAttribute('href'),p.url());await next.tap();await p.waitForURL(u=>u.searchParams.get('section')==='contents'&&u.searchParams.get('hub_page')===target.searchParams.get('hub_page'));await p.waitForLoadState('load');}
  check(identities.length===f.content_total&&new Set(identities).size===f.content_total,'all exact authored social and newsletter contents are reachable once through real short pages');
  await p.locator('[name=hub_type]').selectOption('newsletter');await p.locator('.editorial-library-search button').click();await p.waitForURL(u=>u.searchParams.get('hub_type')==='newsletter');check(await p.locator('[data-editorial-module="newsletter"]').count()>0&&await p.locator('[data-editorial-module="social"]').count()===0,'combined content filter retains newsletter identities');
  const create=p.locator('.editorial-create a');check(await create.count()>=4,'visible individual creation links offer real editors');await create.last().focus();await p.keyboard.press('Enter');await p.locator('form [name=operation][value=communication-save]').waitFor({state:'attached'});check(await p.locator('form [name=operation][value=communication-save]').count()>0,'newsletter create choice opens its actual composer');
  const invalid=new URL(base);invalid.searchParams.set('plan_month','2026-13');const bad=await p.goto(invalid.href,{waitUntil:'load'});check(bad.status()===422&&await p.locator('.social-plan-grid').count()===0&&(await p.locator('.social-planner').innerText()).includes('2026-13'),'invalid month retains original input with a real 422 and explicit recovery');
  await ctx.unrouteAll({behavior:'wait'});await ctx.close();console.log(JSON.stringify({profile:locale+' '+mode+' '+js,checks,views}));
 }
 check(posts===0&&external.length===0&&errors.length===0,'no business POST no external request and no script error '+errors.join(';'));
 const result={suite:'editorial-hub-browser',checks,views,profiles:8,business_posts:posts,external_requests:external.length,script_errors:errors.length,source:'Actual native and integrated manager with synthetic saved originals only'};await writeFile(out+'/results.json',JSON.stringify(result,null,2));console.log(JSON.stringify(result));
}finally{language('de');await browser.close();}
