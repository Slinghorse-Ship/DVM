import {readFile,writeFile} from 'node:fs/promises';
import assert from 'node:assert/strict';
const f=JSON.parse(await readFile(process.env.DVM_MANAGER_INTERFACE_FIXTURE,'utf8'));
assert.ok(Object.values(f.origins).every(origin=>/^http:\/\/127\.0\.0\.1:[0-9]+$/.test(origin)));
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE||'/usr/bin/chromium',args:['--no-sandbox']});
let checks=0,states=0,external=0,posts=0;const errors=[];
const check=(ok,label)=>{assert.ok(ok,label);checks++;};
async function language(page,origin,locale){
 await page.goto(origin+'/account?section=language#account-language',{waitUntil:'networkidle'});
 const form=page.locator('form').filter({has:page.locator('input[name="operation"][value="select-language"]')});
 await form.locator('[name="interface_locale"]').selectOption(locale);await form.getByRole('button').click();await page.waitForLoadState('networkidle');
 check(await page.locator('html').getAttribute('lang')===locale,'actual account form selects '+locale);
}
try{
 for(const app of ['native','integrated'])for(const js of [true,false]){
  const origin=f.origins[app],mode=js?'js':'basic',c=await browser.newContext({javaScriptEnabled:js,viewport:{width:390,height:844}});
  await c.route('**/*',route=>{if(!Object.values(f.origins).includes(new URL(route.request().url()).origin)){external++;return route.abort();}return route.continue();});
  c.on('page',page=>{page.on('pageerror',error=>errors.push(error.message));page.on('request',request=>{if(request.method()==='POST')posts++;});});
  const entry=await c.request.get(origin+'/own-test-enter?mode='+mode,{maxRedirects:0});check(entry.status()===303,'own authenticated '+app+'/'+mode+' session');
  const page=await c.newPage(),workspace=app==='native'?origin+'/?view=website':origin+'/vereinsverwaltung/?view=website';
  for(const locale of ['en','de']){
   await language(page,origin,locale);const response=await page.goto(workspace,{waitUntil:'networkidle'});check(response.status()===200,'actual '+app+' manager opens in '+locale);
   if(app==='native'){
    check(await page.locator('.app-header a[href="/account"]').innerText()===(locale==='en'?'My account & security':'Mein Konto & Sicherheit'),'native identity link has the chosen language');
    check(await page.locator('.sidebar a[href="/?view=news"]').innerText()===(locale==='en'?'Articles':'Beiträge'),'native route label has the chosen language');
    check(await page.locator('#discard-heading').innerText()===(locale==='en'?'Discard changes?':'Änderungen verwerfen?'),'native discard heading has the chosen language');
   }else{
    check(await page.locator('#navigation .club-label').innerText()===f.club_name,'original human club name is not translated as a frame label');
    check(await page.locator('.menu-search').innerText()===(locale==='en'?f.override:'Funktion finden'),'reviewed frame override is escaped and rendered as literal text');
    check(await page.locator('.menu-search input').getAttribute('placeholder')===(locale==='en'?'e.g. invoice, member, GEMA':'z. B. Rechnung, Mitglied, GEMA'),'search placeholder uses current pack');
    check(await page.locator('#workspace nav[aria-label="'+(locale==='en'?'Manage website':'Website verwalten')+'"] a').filter({hasText:locale==='en'?'Overview':'Übersicht'}).count()===1,'embedded website tabs use the same explicit account language');
    if(js){
     await page.locator('[data-menu]').click();
     const search=page.locator('[data-menu-search]');await search.fill('password');
     check(await page.locator('#menu-search-results a:visible').count()>0,'English password synonym finds a real authorized account function');
     check((await page.locator('[data-menu-search-status]').innerText()).includes(locale==='en'?'matching features':'passende Funktionen'),'menu results announce the current interface language');await search.fill('');await page.locator('[data-menu-close]').click();
    }
   }
   for(const [width,height]of [[320,720],[390,844],[820,1180],[1440,1000],[844,390],[1180,820]])for(const font of [16,32]){
    await page.setViewportSize({width,height});await page.evaluate(font=>document.documentElement.style.fontSize=font+'px',font);
    check(await page.locator('html').getAttribute('lang')===locale,'real document language '+[app,js,locale,width,font]);
    const layout=await page.evaluate(()=>({fits:document.documentElement.scrollWidth<=innerWidth+1,error:/Fatal error:|SQLSTATE\[|Stack trace:/.test(document.body.innerText),targets:[...document.querySelectorAll('.app-header a,.sidebar a,.sidebar button,.sidebar summary,.topbar button,.topbar a,.demo-banner a')].filter(node=>node.getClientRects().length&&getComputedStyle(node).visibility!=='hidden').map(node=>{const r=node.getBoundingClientRect();return {width:r.width,height:r.height,label:node.textContent.trim()||node.getAttribute('aria-label')};})}));
    if(!layout.fits)await page.screenshot({path:f.root+'/interface-overflow-'+app+'-'+locale+'-'+width+'-'+font+'.png',fullPage:true});
    check(layout.fits&&!layout.error,'frame fits touch/enlarged text '+[app,js,locale,width,height,font]);
    for(const target of layout.targets)check(target.width>=43&&target.height>=43,'visible translated frame target fits touch '+[app,locale,width,font,target.label]);
    states++;
   }
   await page.evaluate(()=>document.documentElement.style.fontSize='16px');await page.setViewportSize({width:390,height:844});
   if(app==='integrated'){
    if(js){await page.getByRole('button',{name:locale==='en'?'Open navigation':'Navigation öffnen',exact:true}).click();check(await page.locator('[data-menu-close]').innerText()===(locale==='en'?'Close menu':'Menü schließen')&&await page.locator('[data-menu-close]').isVisible(),'translated real mobile close control');await page.keyboard.press('Escape');check(await page.getByRole('button',{name:locale==='en'?'Open navigation':'Navigation öffnen',exact:true}).evaluate(node=>node===document.activeElement),'mobile Escape restores the translated opener');}
    else{await page.locator('.mobile-menu-no-script').click();check(await page.locator('#navigation').isVisible(),'translated no-JS mobile menu remains reachable');await page.locator('.mobile-nav-close-no-script').click();}
   }
  }
  if(app==='integrated'){
   const dashboardResponse=await page.goto(origin+'/vereinsverwaltung/?view=dashboard',{waitUntil:'networkidle'});
   check(dashboardResponse?.status()===200&&new URL(page.url()).searchParams.get('view')==='dashboard','actual current dashboard request succeeds');
   const dashboard=page.locator('[data-native-dashboard]');
   check(await dashboard.count()===1,'actual current dashboard is rendered');
   check(await page.locator('#navigation .club-label').innerText()===f.club_name,'actual dashboard frame retains the original configured club name');
   const personal=await dashboard.getAttribute('data-dashboard-personal')!==null;
   if(personal)check(await dashboard.locator('.dashboard-club').count()===0,'personal dashboard retains its restricted presentation');
   else check(await dashboard.locator('.dashboard-club h2').innerText()===f.club_name,'actual general dashboard uses the original configured club name instead of a hardcoded design club');
   console.log(JSON.stringify({event:'current-dashboard-frame',application:app,javascript:js,presentation:personal?'personal':'general',status:dashboardResponse.status(),original_club_name_exact:true}));
  }
  if(js){
   await language(page,origin,'en');
   if(app==='integrated'){
    await page.goto(origin+'/vereinsverwaltung/?view=settings',{waitUntil:'networkidle'});
    const input=page.locator('main form[method="post"] input.form-control:visible:not([type="hidden"]):not([type="checkbox"]):not([type="password"])').first();await input.fill('Own unsaved human name ä🙂');
    await page.evaluate(()=>window.ownInterfaceDocument='retained');
    const attempt=page.evaluate(()=>window.KulturhusNavigation.navigate(location.pathname+'?view=calendar'));
    await page.getByRole('dialog').getByRole('button',{name:'Cancel',exact:true}).click();check(await attempt===false&&await input.inputValue()==='Own unsaved human name ä🙂','English cancellation keeps exact unsaved human input');
    const target=origin+'/vereinsverwaltung/?view=calendar';await c.route(target,route=>route.abort());
    const refused=page.evaluate(target=>window.KulturhusNavigation.navigate(target),target);await page.getByRole('dialog').getByRole('button',{name:'Discard changes',exact:true}).click();check(await refused===false,'actual failed fetch remains a refused navigation');
    check((await page.locator('[data-manager-navigation-error]').innerText()).startsWith('This area could not be opened.')&&await input.inputValue()==='Own unsaved human name ä🙂','actual English error retains the complete unsaved original input');await c.unroute(target);
    const accepted=page.evaluate(target=>window.KulturhusNavigation.navigate(target),target);await page.getByRole('dialog').getByRole('button',{name:'Discard changes',exact:true}).click();check(await accepted===true&&await page.evaluate(()=>window.ownInterfaceDocument)==='retained','deliberate discard changes the area while retaining the actual document');
   }else{
    await page.goto(workspace+'&item='+encodeURIComponent(f.page_id),{waitUntil:'networkidle'});
    const input=page.locator('form').filter({has:page.locator('input[name="operation"][value="page-save"]')}).locator('input[name="title"]');await input.fill('Own exact unsaved native title ä🙂');
    await page.locator('.sidebar a[href="/?view=news"]').click();await page.locator('#discard[open]').waitFor();
    check(await page.locator('#discard-heading').innerText()==='Discard changes?','actual native English unsaved dialog');await page.keyboard.press('Escape');
    check(await input.inputValue()==='Own exact unsaved native title ä🙂','native English Escape preserves exact human input');
    await page.locator('.sidebar a[href="/?view=news"]').click();await page.locator('#discard[open] [data-choice="discard"]').click();await page.locator('#workspace[data-view="news"]').waitFor();
    check(await page.locator('.sidebar a[href="/?view=news"]').getAttribute('aria-current')==='page','native English deliberate discard opens the requested area');
   }
   // Change the same account in a genuine second tab, then use actual in-app navigation.
   const other=await c.newPage();await language(other,origin,'de');await other.close();
   if(app==='integrated'){
    check(await page.evaluate(target=>window.KulturhusNavigation.navigate(target),workspace),'actual navigation accepts the current account language after another tab changed it');
    check(await page.locator('html').getAttribute('lang')==='de'&&await page.locator('.menu-search').innerText()==='Funktion finden','retained document refreshes locale, frame and browser pack together');
    check(await page.evaluate(()=>window.KulturhusInterface.text('Abbrechen'))==='Abbrechen','cached client labels follow the newly committed German pack');
   }else{
    await page.locator('.sidebar a[href="/?view=news"]').click();await page.waitForLoadState('networkidle');
    await page.waitForFunction(()=>document.documentElement.lang==='de');check(await page.locator('.app-header a[href="/account"]').innerText()==='Mein Konto & Sicherheit','native navigation refreshes the complete frame after an external account locale change');
   }
  }
  await c.close();
 }
 check(errors.length===0,'no actual bilingual frame JavaScript error');check(external===0,'no external request from bilingual frame activity');
 const result={suite:'native-manager-interface-browser',states,checks,actual_posts:posts,external_requests:external,javascript_errors:errors};await writeFile(f.result_file,JSON.stringify(result,null,2)+'\n');console.log(JSON.stringify(result));
}finally{await browser.close();}
