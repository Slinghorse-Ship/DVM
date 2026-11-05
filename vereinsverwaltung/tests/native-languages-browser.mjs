import {readFile,writeFile,mkdir} from 'node:fs/promises';
import assert from 'node:assert/strict';
const f=JSON.parse(await readFile(process.env.DVM_EDITORIAL_WORKFLOW_FIXTURE,'utf8'));
if(!/^http:\/\/127\.0\.0\.1:[0-9]+$/.test(f.origin))throw new Error('Own loopback fixture required.');
if(!/^http:\/\/127\.0\.0\.1:[0-9]+$/.test(f.data.native_origin))throw new Error('Own native loopback fixture required.');
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE||'/usr/bin/chromium',args:['--no-sandbox']});
let checks=0,states=0,external=0;const errors=[],staticAssets=new Map();const artifacts=process.env.DVM_LANGUAGE_ARTIFACTS||'/tmp/kulturhus-languages-browser-proof';await mkdir(artifacts,{recursive:true});
const check=(ok,label)=>{assert.ok(ok,label);checks++;};
const endpoint=f.origin+'/vereinsverwaltung/?view=settings&tab=languages';
const context=async(js=true,viewport={width:390,height:844},role='admin')=>{
 const c=await browser.newContext({javaScriptEnabled:js,viewport});
 c.setDefaultNavigationTimeout(60000);
 await c.route('**/*',async route=>{
  const url=new URL(route.request().url());if(![f.origin,f.data.native_origin].includes(url.origin)){external++;return route.abort();}
  // Routing disables Chromium's ordinary HTTP cache. Restore only immutable application assets;
  // every cached entry is the first real router response of this one frozen synthetic run.
  if(route.request().method()==='GET'&&/^\/(?:vereinsverwaltung\/[^/]+|assets\/[^/]+)\.(?:css|js|mjs|svg|woff2?)$/.test(url.pathname)){
   if(!staticAssets.has(url.href))staticAssets.set(url.href,(async()=>{const response=await route.fetch({timeout:60000});const headers=response.headers();if(response.status()!==200||!/(?:css|javascript|svg|font|woff)/i.test(headers['content-type']||''))throw new Error('Static application asset unavailable: '+url.pathname);delete headers['content-length'];delete headers['content-encoding'];delete headers['set-cookie'];return{status:200,headers,body:await response.body()};})());
   try{return await route.fulfill(await staticAssets.get(url.href));}catch(error){errors.push(error.message);return route.abort();}
  }return route.continue();
 });
 c.on('page',page=>page.on('pageerror',error=>errors.push(error.message)));
 await c.request.get(f.origin+'/own-test-enter?role='+role);return c;
};
try{
 if(process.env.DVM_LANGUAGE_ACTIONS_ONLY!=='1')for(const js of [true,false])for(const [width,height] of [[320,720],[390,844],[768,1024],[1024,768],[1280,720],[1536,960]])for(const font of [16,32]){
  const c=await context(js,{width,height}),page=await c.newPage();
  for(const mode of ['pages','edit','history','settings','rights','pack']){
   const response=await page.goto(endpoint+'&locale=en&mode='+mode+(['edit','history'].includes(mode)?'&page='+f.data.page_id:''),{waitUntil:'load'});
   check(response.status()===200,'protected language '+mode+' returns 200');await page.locator('.native-languages').waitFor();await page.evaluate(font=>document.documentElement.style.fontSize=font+'px',font);
   const layout=await page.evaluate(()=>({fits:document.documentElement.scrollWidth<=innerWidth+1,rows:document.querySelectorAll('.language-page-list li').length,fields:document.querySelectorAll('input[name^="message_pack["]').length,targets:[...document.querySelectorAll('.native-languages .btn,.native-languages summary')].filter(n=>n.getBoundingClientRect().width>0).map(n=>({h:n.getBoundingClientRect().height,w:n.getBoundingClientRect().width,label:n.textContent.trim().slice(0,30)}))}));
   if(!layout.fits){await page.screenshot({path:artifacts+'/overflow-'+mode+'-'+width+'-'+font+'.png',fullPage:true});await writeFile(artifacts+'/overflow.json',JSON.stringify(await page.evaluate(()=>[...document.querySelectorAll('body *')].map(n=>({tag:n.tagName,cls:n.className,text:n.textContent.slice(0,60),left:n.getBoundingClientRect().left,right:n.getBoundingClientRect().right,width:n.getBoundingClientRect().width,scroll:n.scrollWidth,client:n.clientWidth})).filter(n=>n.width>0&&(n.right>innerWidth+1||n.scroll>n.client+1)).slice(0,60)),null,2));}
   check(layout.fits,'native language '+mode+' fits '+[width,height,font,js]);check(layout.rows<=20&&layout.fields<=10,'bounded actual list and language pack editor');for(const t of layout.targets)check(t.h>=43&&t.w>=43,'language touch target '+t.label);states++;
  }console.log('PASS language layout views '+states+' at '+[width,height,font,js]);await c.close();
 }
 const c=await context(),page=await c.newPage();await page.goto(endpoint+'&locale=en&mode=edit&page='+f.data.page_id);await page.locator('.native-languages').waitFor();
 await page.locator('input[name="fields[title]"]').fill('Actual protected form translation');await page.locator('input[name="translated_fields[]"][value="title"]').check();await page.locator('input[name="slug"]').fill('translated-club-life');
 await page.locator('button[value="language-save"]').click();await page.locator('.native-languages').waitFor();check(await page.locator('input[name="fields[title]"]').inputValue()==='Actual protected form translation','real native POST persists selected translated title');
 const token=await page.locator('input[name="csrf"]').first().inputValue();const version=await page.locator('button[value="language-publish"]').locator('..').locator('..').locator('input[name="version"]').inputValue();
 const bad=await c.request.post(endpoint,{headers:{Accept:'application/json'},form:{action:'language-publish',csrf:'wrong',page:f.data.page_id,locale:'en',version}});check(bad.status()===403,'actual protected endpoint rejects wrong original CSRF');
 const publicBefore=await c.request.get(f.origin+'/site/en/club-life');check(publicBefore.status()===200&&(await publicBefore.text()).includes('Club life'),'private translated form save leaves previous public language revision unchanged');
 await page.locator('button[value="language-publish"]').click();await page.locator('.native-languages').waitFor();
 const publicAfter=await c.request.get(f.origin+'/site/en/translated-club-life');const publicHtml=await publicAfter.text();check(publicAfter.status()===200&&publicHtml.includes('Actual protected form translation'),'actual language publish serves exact translated website route');
 await writeFile(artifacts+'/published-translated-page.html',publicHtml);
 check(/<html[^>]*lang="en"/.test(publicHtml)&&publicHtml.includes('rel="canonical" href="'+f.origin+'/en/translated-club-life/"'),'translated public document carries native language and localized canonical path');
 await page.locator('input[name="fields[title]"]').fill('Never public language draft');await page.locator('button[value="language-save"]').click();await page.locator('.native-languages').waitFor();
 for(const origin of [f.origin,f.data.native_origin]){
  const search=await c.newPage();const query='Actual protected form translation';const response=await search.goto(origin+'/search?'+new URLSearchParams({lang:'en',q:query}));check(response.status()===200,'actual public search router accepts published native language');
  check(await search.locator('[data-search-result]').count()===1,'localized public search counts exactly current translated result');check(await search.locator('[data-search-result] h2').innerText()===query,'localized public search title uses published translation');
  check(/\/(?:site\/)?en\/translated-club-life\/?$/.test(await search.locator('[data-search-result] h2 a').getAttribute('href')),'localized public search result retains correct translated route');
  check(await search.locator('form[data-public-search], [data-public-search] form').locator('input[name="lang"]').inputValue()==='en','actual search form retains selected public language');check(await search.locator('html').getAttribute('lang')==='en','actual localized search has English document language');
  const canonical=new URL(await search.locator('link[rel="canonical"]').getAttribute('href'));check(canonical.origin===origin&&canonical.pathname==='/search'&&canonical.searchParams.get('lang')==='en'&&canonical.searchParams.get('q')===query,'localized search canonical retains exact explicit language and literal query');
  await search.goto(origin+'/search?'+new URLSearchParams({lang:'en',q:'Confirmed language search'}));check(await search.locator('[data-search-result]').count()===20,'actual localized search has bounded twenty-result first page');const next=search.locator('[data-search-pagination="top"] a[rel="next"]');const pager=new URL(await next.getAttribute('href'),origin);check(pager.searchParams.get('lang')==='en'&&pager.searchParams.get('q')==='Confirmed language search','search pager retains explicit language and literal query');await next.click();check(await search.locator('[data-search-result]').count()===1,'real localized search pager reaches remaining confirmed result');
  await search.goto(origin+'/search?'+new URLSearchParams({lang:'en',q:'Never public language draft'}));check(await search.locator('[data-search-result]').count()===0,'actual public search excludes independently saved private language draft');
  for(const invalid of ['lang=fr','lang%5B%5D=en']){const denied=await c.request.get(origin+'/search?'+invalid+'&q=Confirmed');check(denied.status()>=400&&denied.status()<500,'actual public search rejects disabled or nonscalar language');}
  await search.close();
 }
 console.log('PASS actual multilingual publication and both real localized search routers');
 await page.screenshot({path:artifacts+'/translation-phone.png',fullPage:true});
 const enPref=await c.request.post(endpoint,{headers:{Accept:'application/json'},form:{action:'language-preference',csrf:token,admin_locale:'en',locale:'en'}});check(enPref.status()===200,'actual personal admin-language preference POST succeeds');await page.reload();check(await page.getByRole('heading',{name:'Languages',exact:true}).count()===1,'current actor native interface uses selected English catalogue');
 check(await page.locator('html').getAttribute('lang')==='en','actual manager shell carries the selected native interface language');check((await page.locator('.nav-group summary').allTextContents()).some(text=>text.includes('Finance')),'actual permitted manager navigation uses explicit English catalogue labels');

 let failureProfiles=0;
 for(const locale of ['de','en'])for(const js of [true,false])for(const width of [320,820]){
  const own=await context(js,{width,height:1024}),p=await own.newPage();
  const edit=endpoint+'&mode=edit&locale=en&page='+f.data.page_id;
  await p.goto(edit);let csrf=await p.locator('input[name="csrf"]').first().inputValue();
  const preference=await own.request.post(endpoint,{headers:{Accept:'application/json'},form:{action:'language-preference',csrf,admin_locale:locale,locale:'en'}});
  check(preference.status()===200&&(await preference.json()).state.admin_locale===locale,'own language preference for complete rejection profile '+[locale,js,width]);
  const before=await(await own.request.get(f.origin+'/own-language-originals')).json();
  const t=(de,en)=>locale==='en'?en:de;
  await p.goto(endpoint+'&mode=edit&locale=en&page='+f.data.home_id);
  const home=p.locator('.native-languages');
  const heroChoice=home.locator('input[name="selected_home[]"][value="hero_title"]');
  const homeGroup=heroChoice.locator('xpath=ancestor::details[1]');if(await homeGroup.getAttribute('open')===null)await homeGroup.locator(':scope > summary').click();
  const actualHeroLabel=(await heroChoice.locator('..').innerText()).trim();
  if(actualHeroLabel!==t('Hero-Überschrift','Hero heading'))console.error('Original home label diagnostic',JSON.stringify({locale,js,width,actual:actualHeroLabel,html:await p.locator('html').getAttribute('lang'),label:await heroChoice.locator('..').evaluate(n=>n.outerHTML)}));
  check(actualHeroLabel===t('Hero-Überschrift','Hero heading'),'actual home control uses personal interface language');
  const hero=home.locator('textarea[name="home_text[hero_title]"]');
  check(await hero.inputValue()==='Hero-Überschrift','caption-valued original home title is never translated');
  const source=hero.locator('xpath=ancestor::fieldset[1]').locator('details');await source.locator('summary').click();
  check(await source.locator('pre').textContent()==='Hero-Überschrift','actual home source remains available byte for byte beside the translation');
  await p.goto(edit);
  for(const [field,de,en]of [['alt','Bildbeschreibung','Photo description'],['caption','Bildunterschrift','Caption'],['button_label','Schaltflächentext','Button text']]){
   const label=p.locator('input[name="selected_sections['+f.data.section_id+'][]"][value="'+field+'"]').locator('..');
   check((await label.textContent()).trim()===t(de,en),'actual section caption has a human personal-language label '+field);
  }
  const section=p.locator('[name="section_text['+f.data.section_id+'][heading]"]').locator('xpath=ancestor::details[1]');
  check((await section.locator(':scope > summary').textContent())==='Bildbeschreibung','human source section heading is never translated as a caption');
  for(const [kind,status,message]of [
   ['csrf',403,t('Formular abgelaufen. Bitte neu laden.','This form has expired. Please reload.')],
   ['path',422,t('Gültigen Sprachpfad angeben.','Enter a valid language path.')]
  ]){
   const save=p.locator('button[value="language-save"]'),form=save.locator('xpath=ancestor::form[1]');
   if(kind==='csrf')await form.locator('input[name="csrf"]').evaluate(n=>n.value='never-recover-csrf');
   else await form.locator('input[name="slug"]').fill('BAD ORIGINAL PATH <&> ä🙂');
   const [response]=await Promise.all([p.waitForResponse(r=>r.request().method()==='POST'&&r.url().startsWith(endpoint)),(async()=>{await save.focus();await p.keyboard.press('Enter');})()]);
   check(response.status()===status,'real keyboard language rejection preserves status '+kind);
   await p.getByRole('alert').filter({hasText:message}).waitFor();
   check(await p.locator('html').getAttribute('lang')===locale,'actual rejection document retains personal language');
   const failure=p.locator('[data-language-failure]');
   check((await failure.getByRole('heading',{level:1}).textContent())===t('Sprachen','Languages'),'actual rejection heading is localized');
   check((await failure.innerText()).includes(t('Prüfe den gespeicherten Stand, bevor du erneut speicherst.','Check the saved state before saving again.')),'failed submission asks for actual saved-state review');
   check(await p.locator('form').count()===0,'rejection does not submit or replay any earlier action');
   await p.evaluate(()=>document.documentElement.style.fontSize='32px');
   check(await failure.getByRole('heading',{level:1}).evaluate(n=>n.getBoundingClientRect().height<=parseFloat(getComputedStyle(n).lineHeight)*1.2),'short language heading remains a readable whole line at enlarged phone font');
   const reload=failure.getByRole('link',{name:t('Neu laden','Reload'),exact:true}),href=new URL(await reload.getAttribute('href'),f.origin);
   check(href.origin===f.origin&&href.searchParams.get('mode')==='edit'&&href.searchParams.get('page')===f.data.page_id&&href.searchParams.get('locale')==='en'&&[...href.searchParams.keys()].every(k=>['view','tab','mode','page','locale'].includes(k)),'recovery retains exact own read context without credentials or action');
   const box=await reload.boundingBox();check(box.height>=44&&box.width>=44&&await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'actual rejection fits phone/tablet at enlarged font with a real touch target');
   if(kind==='csrf')await p.screenshot({path:artifacts+'/error-'+locale+'-'+(js?'js':'nojs')+'-'+width+'.png',fullPage:true});
   await reload.focus();await p.keyboard.press('Enter');await p.locator('.native-languages input[name="slug"]').waitFor();
   check(await p.locator('input[name="fields[title]"]').inputValue()==='Never public language draft','explicit recovery reads the exact earlier saved translation');
  }
  const denied=await own.request.post(endpoint,{headers:{Accept:'application/json'},form:{action:'language-save',csrf:'never-recover-csrf',mode:'edit',locale:'en',page:f.data.page_id,password:'never-recover-password'}});
  const deniedText=await denied.text();check(denied.status()===403&&JSON.parse(deniedText).error===t('Formular abgelaufen. Bitte neu laden.','This form has expired. Please reload.')&&!deniedText.includes('never-recover'),'actual JSON CSRF rejection is localized and contains no posted secret');
  const method=await own.request.fetch(endpoint+'&language_json=1',{method:'DELETE',headers:{Accept:'application/json'}});
  check(method.status()===405&&method.headers().allow==='GET, HEAD, POST'&&(await method.json()).error===t('Diese Anfrage ist nicht erlaubt.','This request is not allowed.'),'unsupported real method retains Allow and localized rejection');
  const head=await own.request.head(endpoint+'&language_json=1&mode=unsupported');check(head.status()===422&&(await head.body()).length===0,'real rejected HEAD emits no response body');
  const badView=await p.goto(endpoint+'&mode=unsupported&password=never-recover-password');
  check(badView.status()===422&&(await p.getByRole('alert').textContent())===t('Sprachverwaltung nicht verfügbar. Bitte neu laden.','Language administration is unavailable. Please reload.')&&!(await p.locator('body').textContent()).includes('never-recover-password'),'actual shell read failure is localized without reflecting unrelated credentials');
  const after=await(await own.request.get(f.origin+'/own-language-originals')).json();
  check(after.original_sha256===before.original_sha256,'every rejected request preserves all whole database rows including complete language revisions');
  failureProfiles++;console.log('PASS actual language rejection profile '+[locale,js,width]);await own.close();
 }
 await page.goto(endpoint+'&mode=edit&locale=en&page='+f.data.page_id);const restoreToken=await page.locator('input[name="csrf"]').first().inputValue();
 const restoreEnglish=await c.request.post(endpoint,{headers:{Accept:'application/json'},form:{action:'language-preference',csrf:restoreToken,admin_locale:'en',locale:'en'}});check(restoreEnglish.status()===200,'restore actual English interface before own-account navigation');
 console.log('PASS complete language rejection and recovery profiles '+failureProfiles);
 await page.goto(f.origin+'/account?section=language');await page.locator('#account-language[open]').waitFor();check(await page.getByRole('heading',{name:'Your account',exact:true}).count()===1,'actual native account uses current own interface language');await page.getByLabel('Choose language',{exact:true}).selectOption('de');await page.getByRole('button',{name:'Save language',exact:true}).click();await page.getByRole('heading',{name:'Dein Konto',exact:true}).waitFor();check(await page.locator('html').getAttribute('lang')==='de','actual native account preference POST switches own language with current account CSRF');await page.getByLabel('Sprache auswählen',{exact:true}).selectOption('en');await page.getByRole('button',{name:'Sprache speichern',exact:true}).click();await page.getByRole('heading',{name:'Your account',exact:true}).waitFor();
 console.log('PASS actual native own-account and manager interface language changes');
 await page.goto(f.origin+'/vereinsverwaltung/?view=settings&tab=account');await page.locator('[data-account-panel="language"] summary').click();check(await page.getByRole('link',{name:'Change language',exact:true}).count()===1,'actual compact manager account exposes the own authenticated language control');
 const rc=await context(true,{width:390,height:844},'reader');const denied=await rc.request.get(endpoint+'&language_json=1&locale=en&mode=settings');check(denied.status()===403||denied.status()===422,'actual reader session cannot administer language settings');await rc.close();
 const ec=await context(true,{width:390,height:844},'editor');const editorRead=await ec.request.get(endpoint+'&language_json=1&locale=en&mode=edit&page='+f.data.page_id);check(editorRead.status()===200,'actual assigned language editor can open translation workspace');const editorState=await editorRead.json();check(editorState.can_manage===false&&editorState.can_publish===false,'language editor retains separate manage and publish restrictions');await ec.close();
 const anonymous=await browser.newContext();const anon=await anonymous.request.get(endpoint+'&language_json=1',{maxRedirects:0});check(anon.status()===303,'anonymous client cannot open protected language workspace');await anonymous.close();await c.close();
 const nj=await context(false),np=await nj.newPage();await np.goto(endpoint+'&locale=en&mode=edit&page='+f.data.page_id);await np.locator('input[name="fields[title]"]').fill('No JavaScript translation');await np.locator('button[value="language-save"]').click();await np.locator('.native-languages').waitFor();check(await np.locator('input[name="fields[title]"]').inputValue()==='No JavaScript translation','real language save works without JavaScript');await nj.close();
 check(errors.length===0,'no actual language browser JavaScript errors');check(external===0,'no external provider requests');const result={suite:'native-languages-browser',mode:process.env.DVM_LANGUAGE_ACTIONS_ONLY==='1'?'actions-only':'full',states,checks,failure_profiles:failureProfiles,static_router_assets:staticAssets.size,external_requests:external,javascript_errors:errors,live_transport:false};await writeFile(f.data.result_file,JSON.stringify(result,null,2)+'\n');console.log(JSON.stringify(result));
}finally{await browser.close();}
