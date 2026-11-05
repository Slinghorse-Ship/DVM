import {pathToFileURL} from 'node:url';
const input=JSON.parse(await new Promise(resolve=>{let value='';process.stdin.setEncoding('utf8');process.stdin.on('data',chunk=>value+=chunk);process.stdin.on('end',()=>resolve(value));}));
const diagnostic=process.env.DVM_CATALOGUE_POLICY_DIAGNOSTIC||null;if(diagnostic!==null&&diagnostic!=='integrated-js')throw new Error('Unknown own catalogue diagnostic target');
const {chromium}=await import(pathToFileURL(process.env.DVM_PLAYWRIGHT_MODULE||process.env.PLAYWRIGHT_MODULE||'/workspace/.setup/browser/node_modules/playwright/index.mjs').href);
const browser=await chromium.launch({executablePath:process.env.DVM_CHROMIUM||process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE||'/usr/bin/chromium',headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
let checks=0,views=0,posts=0,external=0,activePage=null,activeFlow=null;const errors=[],failedAssets=[];
function check(ok,label){if(!ok)throw new Error(label);checks++;console.log('PASS '+label);}
try{
 for(const [mode,f] of Object.entries(input))for(const js of [true,false]){
  if(diagnostic&&!(mode==='integrated'&&js))continue;
  const context=await browser.newContext({viewport:{width:390,height:1000},hasTouch:true,javaScriptEnabled:js});
  await context.route('**/*',route=>{if(new URL(route.request().url()).origin!==f.origin){external++;return route.abort('blockedbyclient');}if(route.request().method()==='POST')posts++;return route.continue();});
  const page=await context.newPage();activePage=page;activeFlow={mode,js};page.on('pageerror',error=>{errors.push(error.message);console.log(JSON.stringify({event:'pageerror',mode,js,message:error.message}));});page.on('console',message=>{if(message.type()==='error'){errors.push(message.text());console.log(JSON.stringify({event:'consoleerror',mode,js,message:message.text()}));}});
  page.on('dialog',dialog=>{const message='Unexpected '+dialog.type()+': '+dialog.message();errors.push(message);console.log(JSON.stringify({event:'actual-dialog',mode,js,type:dialog.type(),message:dialog.message()}));});
  page.on('response',response=>{if(response.status()>=400&&response.request().method()==='GET'&&['script','stylesheet','font','image'].includes(response.request().resourceType()))failedAssets.push({mode,js,status:response.status(),url:response.url()});});
  const entry=await page.goto(f.origin+'/own-test-enter?role=publisher');check(entry.status()===200&&page.url()===f.origin+f.gallery,`${mode}/${js} actual own entry redirects directly to its complete gallery`);
  if(mode==='integrated'){
   for(const name of ['manager-lifecycle.js','manager-navigation.js','app.js','native-manager.js']){const url=await page.locator('script[src]').evaluateAll((nodes,name)=>nodes.map(n=>n.src).find(value=>new URL(value).pathname.endsWith('/'+name)),name);check(Boolean(url),`${mode}/${js} actual ${name} is embedded`);const response=await context.request.get(url);check(response.status()===200&&/javascript/.test(response.headers()['content-type']||''),`${mode}/${js} actual ${name} returns executable local source`);}
   if(js)await page.waitForFunction(()=>Boolean(window.KulturhusPage&&window.KulturhusNavigation&&document.querySelector('.native-manager-embedded [data-native-status]')));
  }
  const selected=f.data.albums[15].id;
  const movement=page.locator('form').filter({has:page.locator('input[name=operation][value=album-order]')}).filter({has:page.locator(`input[name=id][value="${selected}"]`)}).filter({has:page.locator('input[name=direction][value=up]')});
  check(await movement.count()===1,`${mode}/${js} actual permitted neighboring move is available`);
  const before=await movement.locator('input[name=order_version]').inputValue();
  const postDocumentIdentity=mode+'-'+js+'-before-order';await page.evaluate(value=>window.__cataloguePostDocumentIdentity=value,postDocumentIdentity);
  const response=page.waitForResponse(r=>r.request().method()==='POST'&&r.url().startsWith(f.origin));await movement.locator('button').click();const posted=await response;
  const request=posted.request();const requestHeaders=await request.allHeaders();const submitted=new URLSearchParams(request.postData()||'');submitted.delete('csrf');
  console.log(JSON.stringify({event:'actual-order-post',mode,js,status:posted.status(),navigation:request.isNavigationRequest(),accept:requestHeaders.accept,origin:requestHeaders.origin,fetch_site:requestHeaders['sec-fetch-site'],content_type:requestHeaders['content-type'],submitted:requestHeaders['content-type']?.startsWith('application/x-www-form-urlencoded')?Object.fromEntries(submitted):{multipart:true},rendered_order_version:before}));
  if(![200,303].includes(posted.status()))console.log(JSON.stringify({event:'unexpected-order-response',status:posted.status(),response_headers:posted.headers(),body_head:(await posted.text()).slice(0,2500)}));
  const jsonSubmission=mode==='native'&&js;
  check(posted.status()===(jsonSubmission?200:303),`${mode}/${js} real ${jsonSubmission?'JSON':'document'} movement succeeds`);
  check(jsonSubmission?posted.request().headers().accept?.includes('application/json')&&!posted.request().isNavigationRequest():posted.request().isNavigationRequest(),`${mode}/${js} genuine ${jsonSubmission?'JSON SPA':'full document'} submission path`);
  await page.waitForFunction(before=>{const value=document.querySelector('input[name=operation][value=album-order]')?.form?.querySelector('input[name=order_version]')?.value;return value&&value!==before;},before);
  if(!jsonSubmission){await page.waitForFunction(value=>window.__cataloguePostDocumentIdentity!==value,postDocumentIdentity);await page.waitForLoadState('load');}
  check(await page.evaluate(()=>window.__cataloguePostDocumentIdentity)===(jsonSubmission?postDocumentIdentity:undefined),`${mode}/${js} confirmed save ${jsonSubmission?'retains':'replaces'} its original document`);
  if(mode==='integrated'&&js){
   await page.waitForFunction(()=>Boolean(document.readyState==='complete'&&window.KulturhusNavigation&&document.querySelector('.native-manager-embedded [data-native-status]')));
   const state=await page.evaluate(()=>({url:location.href,ready:document.readyState,dirty:window.KulturhusNavigation.isDirty(),guarded:window.KulturhusPage.guarded()}));console.log(JSON.stringify({event:'actual-post-document-ready',mode,js,state}));check(state.dirty===false&&state.guarded===false,`${mode}/${js} completed actual save and deferred mount leave no unsaved form or active guard`);
  }
  for(const role of ['publisher','reader']){
   if(role==='reader'){console.log(JSON.stringify({event:'actual-role-enter-start',mode,js,role}));await page.goto(f.origin+'/own-test-enter?role='+role);console.log(JSON.stringify({event:'actual-role-enter-complete',mode,js,role}));}
   for(const [kind,path,marker,count] of [['gallery',f.gallery,'data-album-row',25],['news',f.news+'&status=all','data-news-row',6]]){
    const actual=await page.goto(f.origin+path);check(actual.status()===200,`${mode}/${js}/${role} actual ${kind} controller opens`);
    check(await page.locator(`[${marker}]`).count()===count,`${mode}/${js}/${role} ${kind} window remains full`);
    const html=await page.content();for(const i of kind==='gallery'?[0,6,26]:[0,6,14])check(!html.includes(f.data[kind==='gallery'?'albums':'news'][i].title),`${mode}/${js}/${role} ${kind} restricted title stays private ${i}`);
    for(const width of [320,390,820])for(const font of [16,32]){
     await page.setViewportSize({width,height:1000});await page.evaluate(font=>document.documentElement.style.fontSize=font+'px',font);const actualFont=await page.locator('html').evaluate(e=>parseFloat(getComputedStyle(e).fontSize));check(actualFont===font,`${mode}/${js}/${role}/${kind}/${width}/${font} effective text size ${actualFont}px`);views++;
     const size=await page.locator('html').evaluate(e=>({scroll:e.scrollWidth,width:innerWidth}));check(size.scroll<=size.width+1,`${mode}/${js}/${role}/${kind}/${width}/${font} bounded viewport ${JSON.stringify(size)}`);
     const smallMoves=await page.locator('.content-row-actions button').evaluateAll(buttons=>buttons.flatMap(button=>{const r=button.getBoundingClientRect();return r.width&&r.height&&(r.width<44||r.height<44)?[{width:r.width,height:r.height}]:[];}));check(smallMoves.length===0,`${mode}/${js}/${role}/${kind}/${width}/${font} actual order controls reach44px ${JSON.stringify(smallMoves)}`);
     for(const field of await page.locator('.content-list-filters input:not([type=hidden]),.content-list-filters select,.content-list-filters button').all()){if(!await field.isVisible())continue;const rect=await field.boundingBox();check(rect.height>=44,`${mode}/${js}/${role}/${kind}/${width}/${font} actual filter touch target`);}
    }
   }
   await page.goto(f.origin+f.gallery);if(mode==='integrated'&&js)await page.waitForFunction(()=>Boolean(window.KulturhusNavigation&&document.querySelector('.native-manager-embedded [data-native-status]')));
   const identity=mode+'-'+js+'-'+role;await page.evaluate(value=>window.__catalogueDocumentIdentity=value,identity);
   const next=page.locator('.album-list-pager').first().locator('a[href]').filter({hasText:'Weiter'});check(await next.count()===1,`${mode}/${js}/${role} first actual pager offers exactly one last-page link`);
   const pending=page.waitForResponse(r=>r.request().method()==='GET'&&r.url().startsWith(f.origin)&&new URL(r.url()).searchParams.get('album_list_page')==='2');await next.click();const reached=await pending,headers=await reached.request().allHeaders();
   check(reached.status()===200,`${mode}/${js}/${role} offered last-page GET succeeds`);
   check(js?!reached.request().isNavigationRequest()&&(mode==='native'?headers.accept?.includes('application/json'):headers['x-kulturhus-navigation']==='1'):reached.request().isNavigationRequest(),`${mode}/${js}/${role} offered GET uses actual ${js?'SPA':'document'} navigation`);
   await page.waitForFunction(()=>document.querySelectorAll('[data-album-row]').length===1);check(await page.evaluate(()=>window.__catalogueDocumentIdentity)===(js?identity:undefined),`${mode}/${js}/${role} ${js?'retains':'replaces'} the original document while reaching its complete last page`);
  }
  await context.close();
 }
 check(external===0,'no external requests');check(failedAssets.length===0,'all actual assets load: '+JSON.stringify(failedAssets));check(errors.length===0,'no JavaScript errors: '+JSON.stringify(errors));
 console.log(JSON.stringify({suite:'catalogue-policies-browser',diagnostic,feature_acceptance:diagnostic===null,checks,views,posts,external_requests:external,errors}));
}catch(error){let state=null;try{state=await Promise.race([activePage?.evaluate(()=>({url:location.href,ready:document.readyState,dirty:window.KulturhusNavigation?.isDirty(),native_status:document.querySelector('.native-manager-embedded [data-native-status]')?.textContent})),new Promise(resolve=>setTimeout(()=>resolve({unavailable:true}),2000))]);}catch{}console.log(JSON.stringify({event:'catalogue-browser-failure',checks,views,posts,external,errors,failedAssets,flow:activeFlow,state,message:error.message}));throw error;}finally{await browser.close();}
