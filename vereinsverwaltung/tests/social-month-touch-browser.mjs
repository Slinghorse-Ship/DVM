import assert from 'node:assert/strict';
import {readFile,mkdir,writeFile} from 'node:fs/promises';
const f=JSON.parse(await readFile(process.env.DVM_SOCIAL_MONTH_FIXTURE,'utf8'));
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE||'/usr/bin/chromium',args:['--no-sandbox']});
const out=process.env.DVM_SOCIAL_MONTH_OUTPUT||'/tmp/kulturhus-social-month-touch';await mkdir(out,{recursive:true});
let checks=0,posts=0;const errors=[],external=[],profiles=[],taps=[];
const check=(ok,label)=>{assert(ok,label);checks++;console.log('PASS '+label);};
try{
 for(const js of [true,false])for(const width of [320,390]){
  const context=await browser.newContext({viewport:{width,height:1000},isMobile:true,hasTouch:true,javaScriptEnabled:js,serviceWorkers:'block'});context.setDefaultTimeout(45000);context.setDefaultNavigationTimeout(120000);
  await context.route('**/*',route=>{const req=route.request();if(new URL(req.url()).origin!==f.origin){external.push(req.url());return route.abort();}if(req.method()==='POST'){posts++;return route.abort();}return route.continue();});
  const page=await context.newPage();page.on('pageerror',error=>errors.push(error.message));
  await page.goto(f.origin+'/own-test-enter?mode=integrated',{waitUntil:'load'});
  const calendar=new URL(page.url());calendar.searchParams.set('planning_view','month');calendar.searchParams.set('plan_month','2026-11');
  const cases=js?[['post','[data-plan-job="'+f.original_job+'"]',f.original_item],['story','[data-plan-step="automation"].state-prepared',f.prepared_story],['reel','[data-plan-kind="reel"]',null]]:[['story','[data-plan-step="automation"].state-prepared',f.prepared_story]];
  for(const[kind,selector,knownId]of cases){
   await page.goto(calendar.href,{waitUntil:'load'});await page.locator('.social-planner').waitFor();
   const card=page.locator(selector).first(),href=new URL(await card.getAttribute('href'),page.url()),id=knownId||href.searchParams.get('native_item');
   check(href.searchParams.get('section')==='compose'&&href.searchParams.get('native_item')===id&&/^[a-f0-9]{32}$/.test(id),width+'/'+js+'/'+kind+' card binds its exact integrated editor');
   if(js){
    await page.evaluate(()=>document.fonts.ready);await card.scrollIntoViewIfNeeded();
    const before=await card.evaluate(node=>{const b=node.getBoundingClientRect(),x=b.x+b.width/2,y=b.y+Math.min(b.height/2,40),hit=document.elementFromPoint(x,y);return{x,y,width:b.width,height:b.height,hit:hit?.closest('[data-plan-job]')===node,inert:!!node.closest('[inert]'),job:node.dataset.planJob};});
    check(before.hit&&!before.inert,width+'/'+kind+' original tap coordinate hits the card without overlay or inert ancestor');
    await card.evaluate(node=>node.focus({preventScroll:true}));await page.mouse.move(before.x,before.y);
    const focused=await card.boundingBox();check(Math.abs(focused.width-before.width)<1&&Math.abs(focused.height-before.height)<1,width+'/'+kind+' focus and hover preserve card geometry');
    await page.evaluate(()=>{window.monthTouchEvents=[];for(const type of ['pointerdown','pointerup','click'])document.addEventListener(type,event=>{const n=event.target.closest?.('[data-plan-job]');if(n)window.monthTouchEvents.push({type,job:n.dataset.planJob,pointerType:event.pointerType||'',trusted:event.isTrusted,defaultPrevented:event.defaultPrevented});},{capture:true});});
    await page.touchscreen.tap(before.x,before.y);
   }else await card.tap();
   await page.waitForURL(url=>url.searchParams.get('native_item')===id&&url.searchParams.get('section')==='compose',{timeout:45000});
   if(kind==='story'){
    await page.locator('#social-image-editor').waitFor({state:'visible'});
    check(new URL(page.url()).hash==='#social-image-editor',width+'/'+js+' Story calendar opens the existing image-studio anchor');
    check(await page.locator('[data-story-editor-details]').evaluate(node=>!node.open)&&await page.locator('[data-compose-live]').count()===0,width+'/'+js+' Story metadata stays secondary and article preview is absent');
    check(await page.locator('.compose-main').evaluate(node=>node.querySelector('[data-story-artwork]').compareDocumentPosition(node.querySelector('[data-story-editor-details]'))&Node.DOCUMENT_POSITION_FOLLOWING),width+'/'+js+' Story artwork precedes text metadata in the actual DOM');
    if(js){await page.waitForFunction(()=>!!document.querySelector('[data-canvas-editor]')?.studio);check(await page.locator('#social-image-editor canvas').isVisible()&&await page.locator('#social-image-editor canvas').evaluate(node=>node.width===1080&&node.height===1920),width+' Story opens the usable portrait image editor');}
    else {check(await page.locator('[data-canvas-fallback]').isVisible(),width+' HTML-only Story keeps its actionable image-editor fallback');await page.getByRole('link',{name:'Zu den Story-Angaben',exact:true}).tap();await page.waitForFunction(()=>document.querySelector('[data-story-editor-details]')?.open);check(await page.locator('#social-compose [name=title]').isVisible(),width+' HTML-only Story fallback actually reveals its original metadata');}
    check(await page.locator('#social-compose [name=id]').inputValue()===id&&await page.locator('#social-compose [name=kind]').inputValue()==='story'&&await page.locator('#social-compose [name=title]').inputValue()==='Story vor dem Kulturabend',width+'/'+js+' exact Story metadata remains intact behind the image editor');
   }else{
    await page.locator(kind==='reel'?'.reel-preset':'#social-compose [name=title]').waitFor({state:'visible'});
    check(await page.locator('#social-compose [name=id]').inputValue()===id&&await page.locator('#social-compose [name=kind]').inputValue()===kind,width+'/'+js+'/'+kind+' actual first tap opens that editor');
    if(kind==='reel')check(new URL(page.url()).hash==='#social-media-workspace',width+' Reel lands at its video workplace');
   }
   if(js){const events=await page.evaluate(()=>window.monthTouchEvents);check(events.some(e=>e.type==='pointerdown'&&e.pointerType==='touch'&&e.trusted)&&events.some(e=>e.type==='click'&&e.trusted),width+'/'+kind+' trusted touch and click reached the original card');taps.push({width,kind,events});}
   await page.screenshot({path:out+'/'+width+'-'+(js?'touch':'nojs')+'-'+kind+'.png',fullPage:false});
  }
  const creation=new URL(calendar);creation.search='';creation.searchParams.set('view','social');creation.searchParams.set('section','compose');creation.searchParams.set('new_kind','story');await page.goto(creation.href,{waitUntil:'load'});await page.locator('#social-compose [name=title]').fill('Eigene Bildstory');check(await page.locator('.story-new-details').evaluate(node=>!node.open)&&await page.locator('#social-compose form').evaluate(form=>form.checkValidity()),width+'/'+js+' new visual Story can start from its visible title without hidden required controls');check(await page.locator('#social-compose [name=id]').inputValue()===''&&await page.locator('#social-compose [name=version]').inputValue()==='0'&&await page.locator('[data-canvas-editor]').count()===0,width+'/'+js+' opening new Story creates no persisted item or premature canvas');
  profiles.push({width,js});await context.close();
 }
 check(posts===0&&external.length===0&&errors.length===0,'all mobile calendar opens are read-only, same-origin and free of script errors');
 const result={checks,profiles,taps,business_posts:posts,external_requests:external,script_errors:errors};await writeFile(out+'/results.json',JSON.stringify(result,null,2));console.log(JSON.stringify({mobile_social_month:result}));
}finally{await browser.close();}
