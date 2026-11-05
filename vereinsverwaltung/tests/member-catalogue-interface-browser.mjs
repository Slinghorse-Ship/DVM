import fs from 'node:fs';
const f=JSON.parse(fs.readFileSync(0,'utf8')),{chromium}=await import(process.env.PLAYWRIGHT_MODULE),browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE,headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
let checks=0,views=0,external=0,posts=0,stage='start';const errors=[],results=[];
const check=(ok,label)=>{if(!ok)throw Error(stage+': '+label);checks++;if(checks%20===0)console.log('Catalogue checks '+checks+' '+stage);};
const go=(p,url)=>p.goto(url,{waitUntil:'load'});
const settled=async p=>{await p.waitForFunction(()=>[...document.querySelectorAll('link[rel=stylesheet]')].every(n=>n.sheet));await p.evaluate(()=>document.fonts.ready);};
async function login(p,origin,role,locale){
 await go(p,origin+'/login');await p.locator('[name=email]').fill(f.accounts[role].email);await p.locator('[name=password]').fill(f.password);
 await Promise.all([p.waitForResponse(r=>r.request().method()==='POST'&&new URLSearchParams(r.request().postData()||'').get('operation')==='login'),p.getByRole('button',{name:/^(Anmelden und Vereinsmanager öffnen|Sign in and open club manager)$/}).click()]);
 await p.waitForFunction(()=>document.querySelector('main')&&!document.querySelector('form [name=operation][value=login]')&&!/^\/login\/?$/.test(location.pathname));
 await go(p,origin+'/account?section=language');const form=p.locator('form:has([name=operation][value=select-language])');await form.locator('[name=interface_locale]').selectOption(locale);await Promise.all([p.waitForNavigation({waitUntil:'load'}),form.getByRole('button').click()]);
}
try{
 let index=0;for(const app of['front','direct'])for(const js of[false,true])for(const locale of['de','en']){
  const tag=app+'-'+(js?'js':'nojs')+'-'+locale;if(process.env.DVM_MEMBER_CATALOGUE_CASE&&process.env.DVM_MEMBER_CATALOGUE_CASE!==tag)continue;
  stage=tag;index++;console.log('Member catalogue '+tag);const origin=f.origins[app],base=origin+'/vereinsverwaltung/',t=(de,en)=>locale==='en'?en:de;
  const context=await browser.newContext({javaScriptEnabled:js,viewport:{width:390,height:1000},acceptDownloads:true,serviceWorkers:'block',extraHTTPHeaders:{'X-Owned-Finance-Help-Client':String(index)}});
  await context.route('**/*',r=>new URL(r.request().url()).origin===origin?r.continue():(external++,r.abort()));context.on('request',r=>{if(r.method()==='POST')posts++;});const p=await context.newPage();p.on('pageerror',e=>errors.push(e.message));await login(p,origin,'admin',locale);
  const malformed=await context.request.get(base+'?view=members&as_of[]=2021-06-01');check(malformed.status()===422&&(await malformed.text()).includes(t('Ungültige Listenfilter.','Invalid list filters.')),'whole array date refused rather than silently becoming a current list');
  await go(p,base+'?view=members&q=own-zero%40example.invalid');await settled(p);
  check((await p.locator('tbody td[data-col=phone]').textContent())==='0'&&(await p.locator('tbody td[data-col=address]').textContent())==='0','actual zero source preserved');
  check(await p.getByLabel(t('Mitglied oder Kontakt suchen','Search member or contact'),{exact:true}).count()===1,'personal fixed search label');
  for(const width of[320,390,820,1280])for(const size of[16,32]){
   await p.setViewportSize({width,height:1000});await p.evaluate(size=>document.documentElement.style.fontSize=size+'px',size);views++;
   check(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'bounded catalogue '+width+'/'+size);
   check(await p.locator('.search-form input[name=q]').evaluate(n=>n.getBoundingClientRect().height>=43.5&&parseFloat(getComputedStyle(n).fontSize)>=parseFloat(getComputedStyle(document.documentElement).fontSize)),'touch and enlarged text search '+width+'/'+size);
   await p.locator('#columns').evaluate(n=>n.open=true);check(await p.locator('#columns summary').evaluate(n=>n.getBoundingClientRect().height>=43.5),'touch disclosure '+width+'/'+size);check(await p.locator('.member-catalogue-nav a').first().evaluate(n=>parseFloat(getComputedStyle(n).fontSize)>=parseFloat(getComputedStyle(document.documentElement).fontSize)),'navigation follows enlarged text '+width+'/'+size);check(await p.locator('.contact-table th').nth(1).evaluate(n=>parseFloat(getComputedStyle(n).fontSize)>=parseFloat(getComputedStyle(document.documentElement).fontSize)),'table labels follow enlarged text '+width+'/'+size);
  }
  await p.evaluate(()=>document.documentElement.style.fontSize='16px');await p.setViewportSize({width:390,height:1000});
  const summary=p.locator('#columns summary');await summary.focus();const opened=await p.locator('#columns').evaluate(n=>n.open);await p.keyboard.press('Enter');check(await p.locator('#columns').evaluate(n=>n.open)!==opened,'real keyboard toggles column disclosure');if(!await p.locator('#columns').evaluate(n=>n.open))await summary.press('Enter');
  const table=p.locator('.table-scroll');await table.focus();await p.keyboard.press('ArrowRight');await p.waitForTimeout(150);check(await table.evaluate(n=>n.scrollWidth<=n.clientWidth||n.scrollLeft>0),'keyboard can scroll the actual wide table');
  check(await p.locator('tbody .member-select').first().evaluate(n=>n.getBoundingClientRect().width>=43.5&&n.getBoundingClientRect().height>=43.5),'whole labelled selection target is touch sized');
  await p.locator('input[name="selected[]"]').focus();await p.keyboard.press('Space');check(await p.locator('input[name="selected[]"]').isChecked(),'real keyboard chooses original profile');for(const key of['email','phone','birthday'])await p.locator('[data-column='+key+']').uncheck();
  check(JSON.stringify(await p.locator('#export-form').evaluate(n=>new FormData(n).getAll('columns[]')))===JSON.stringify(['name','address']),'real form excludes all unchecked contact columns with JS '+js);
  check(await p.locator('[data-select-all]').isDisabled()===!js,'select-all only enabled when functional');
  check(await p.locator('[data-selection-count]').isVisible()===js,'selection count only visible when functional');
  const csvEvent=p.waitForEvent('download');await p.getByRole('button',{name:t('Als CSV','As CSV'),exact:true}).click();const csv=await csvEvent,bytes=fs.readFileSync(await csv.path(),'utf8');
  check(bytes.replace(/^\uFEFF/,'')==='Name;Anschrift\r\n0;0\r\n','actual CSV contains only deliberate name/address and literal zero');
  // Re-enable from the same controls and download the other genuine format.
  await p.locator('[data-column=phone]').check();check(JSON.stringify(await p.locator('#export-form').evaluate(n=>new FormData(n).getAll('columns[]')))===JSON.stringify(['name','phone','address']),'optional column can be re-enabled without duplicate hidden values');
  const xlsxEvent=p.waitForEvent('download');await p.getByRole('button',{name:t('Auswahl als Excel','Selection as Excel'),exact:true}).click();const xlsx=await xlsxEvent;fs.copyFileSync(await xlsx.path(),f.root+'/'+tag+'.xlsx');
  check(fs.readFileSync(await xlsx.path()).subarray(0,2).toString()==='PK','actual XLSX download');
  await go(p,base+'?view=members&q=own-shared-family%40example.invalid');check(await p.locator('tbody tr').count()===4,'shared email never merges four original people');
  const literal=await p.locator('a.profile-link').allTextContents();check(['Zurück','Weiter','Keine passenden Profile','Original <&> ä🙂'].every(n=>literal.includes(n)),'UI words and escaped Unicode names remain literal');
  if(js){await p.locator('[data-select-all]').check();check(await p.locator('[name="selected[]"]:checked').count()===4,'functional select-all');check((await p.locator('[data-selection-count]').innerText())==='4 '+t('ausgewählt','selected'),'localized actual selection count');await p.locator('[data-select-all]').uncheck();}
  await go(p,base+'?view=members&list=current');await p.locator('#filters summary').click();await p.locator('#filters [name=as_of]').fill('2021-06-01');await p.locator('#filters button').click();await p.waitForURL(u=>u.searchParams.get('as_of')==='2021-06-01');check(await p.locator('tbody tr').count()===2,'actual historical membership at fixed date');
  check((await p.locator('[data-member-reference-date]').innerText()).includes(t('Mitgliedschaft am','Membership on')),'localized actual reference-date explanation');
  await p.locator('#save-list summary').click();const listName='Own '+tag+' Zurück 0 ä🙂';await p.locator('#save-list [name=list_name]').fill(listName);await Promise.all([p.waitForNavigation({waitUntil:'load'}),p.locator('#save-list button').click()]);
  const savedUrl=p.url();let badList=await context.request.post(savedUrl,{form:{csrf:await p.locator('#export-form [name=csrf]').inputValue(),action:'save-list',list_name:'Unsupported '+tag,'as_of[]':'2021-06-01'}});check(badList.status()===422,'whole array saved-list filter cannot be silently discarded');check(new URL(savedUrl).searchParams.has('saved')&&(await p.locator('h1').innerText())===listName,'actual saved list preserves literal name and source');check(await p.locator('[name=as_of][type=date]').inputValue()==='2021-06-01','saved list retains fixed date');
  await p.locator('#save-list summary').click();await p.locator('#save-list [name=list_name]').fill(listName);await Promise.all([p.waitForNavigation({waitUntil:'load'}),p.locator('#save-list button').click()]);
  check(await p.locator('#save-list [name=list_name]').inputValue()===listName&&await p.getByRole('alert').filter({hasText:t('Eine Liste mit diesem Namen existiert bereits.','A list with this name already exists.')}).count()>0,'duplicate save retains literal correction input');
  const csrf=await p.locator('#export-form [name=csrf]').inputValue();let response=await context.request.post(savedUrl,{form:{csrf:'invalid-owned-original',action:'delete-list',id:f.data.saved}});check(response.status()===403,'actual CSRF refuses list mutation');
  await go(p,savedUrl);const deleteForm=p.locator('.saved-lists>div').filter({has:p.getByRole('link',{name:listName,exact:true})}).locator('form');
  // Confirmation itself is exercised through the existing central dialog.
  if(js){await deleteForm.getByRole('button').click();const dialog=p.locator('dialog[open]');await dialog.waitFor();await Promise.all([p.waitForNavigation({waitUntil:'load'}),dialog.getByRole('button',{name:t('Entfernen','Remove'),exact:true}).click()]);}
  else await Promise.all([p.waitForNavigation({waitUntil:'load'}),deleteForm.getByRole('button').click()]);
  await go(p,base+'?view=members&show_lists=1');check(await p.getByRole('link',{name:listName,exact:true}).count()===0,'explicit delete removes saved filters while retaining profiles');
  await go(p,base+'?view=members&as_of=2021-02-29');check(await p.getByRole('alert').filter({hasText:t('gültiges Kalenderdatum','valid calendar date')}).count()>0,'invalid direct calendar date remains visible localized correction');
  await go(p,base+'?view=members&q=missing-own-catalogue');check(await p.getByRole('heading',{name:t('Keine passenden Profile','No matching profiles'),exact:true}).count()===1,'localized no-results recovery');await p.getByRole('link',{name:t('Suche und Filter zurücksetzen','Reset search and filters'),exact:true}).click();await p.waitForURL(u=>!u.searchParams.has('q'));check(await p.locator('tbody tr').count()>0,'real empty-list reset');
  const next=p.getByRole('link',{name:t('Nächste Seite','Next page'),exact:true});await next.click();await p.waitForURL(u=>u.searchParams.get('page')==='2');check((await p.locator('.pagination').textContent()).includes(t('Seite 2','page 2')),'real pagination in personal locale');
  await p.locator('.search-form [name=q]').fill('own-shared-family@example.invalid');await p.locator('.search-form button').click();await p.waitForURL(u=>u.searchParams.get('q')==='own-shared-family@example.invalid');check(await p.locator('tbody tr').count()===4,'real search resets page and returns exact complete shared-family rows');
  await p.locator('a.profile-link').first().click();await p.locator('.member-profile-main-form').waitFor();await p.locator('.member-profile .page-heading>a').click();await p.waitForURL(u=>u.searchParams.get('q')==='own-shared-family@example.invalid');await p.locator('.contact-table').waitFor();check(await p.locator('.contact-table tbody tr').count()===4,'real undated profile return retains search and list filters');
  if(tag==='front-nojs-en')await p.screenshot({path:f.root+'/member-catalogue-mobile.png',fullPage:true});
  await context.close();results.push(tag);
 }
 // Current read-only and foreign sessions may neither export nor alter saved lists.
 for(const role of['reader','foreign']){
  stage=role;const origin=f.origins.front,base=origin+'/vereinsverwaltung/',context=await browser.newContext({javaScriptEnabled:false,viewport:{width:390,height:1000},serviceWorkers:'block',extraHTTPHeaders:{'X-Owned-Finance-Help-Client':String(role==='reader'?33:34)}});
  await context.route('**/*',r=>new URL(r.request().url()).origin===origin?r.continue():(external++,r.abort()));const p=await context.newPage();await login(p,origin,role,'en');await go(p,base+'?view=members');check(!(await p.locator('main').innerText()).includes(role==='foreign'?'Original <&>':'Foreign private catalogue'),'current club boundaries');
  const csrf=await p.locator('#export-form [name=csrf]').inputValue();if(role==='reader')check(await p.locator('[name=export_format],#save-list').count()===0,'reader receives no writer controls');
  for(const action of['export','delete-list']){const response=await context.request.post(base+'?view=members',{form:{csrf,action,id:f.data.saved,'selected[]':f.data.zero,'columns[]':'name',export_format:'csv'}});check(response.status()>=400,'current '+role+' '+action+' rejected');}
  await context.close();
 }
 check(external===0&&errors.length===0,'no external requests or browser errors');fs.writeFileSync(f.root+'/member-catalogue-results.json',JSON.stringify({suite:'member-catalogue-interface-browser',checks,views,profiles:results,posts,external_actions:external,javascript_errors:errors}));console.log(JSON.stringify({suite:'member-catalogue-interface-browser',checks,views,profiles:results.length,posts,external_actions:external,javascript_errors:errors.length}));
}finally{await browser.close();}
