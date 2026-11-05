import fs from 'node:fs';
const fixture=JSON.parse(fs.readFileSync(0,'utf8'));
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE);
const browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE,headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
let checks=0,views=0;
const check=(ok,label)=>{if(!ok)throw Error(label);checks++;console.log('PASS '+label);};
const base=fixture.origin+'/vereinsverwaltung/';
try{
 for(const js of [true,false]){
  const context=await browser.newContext({viewport:{width:390,height:1000},hasTouch:true,javaScriptEnabled:js,acceptDownloads:true,serviceWorkers:'block'});
  await context.route('**/*',r=>new URL(r.request().url()).origin===fixture.origin?r.continue():r.abort());
  const page=await context.newPage();await page.goto(fixture.origin+'/own-test-enter?role=admin');await page.goto(base+'?view=calendar&new_event=1');
  const form=()=>page.locator('#event-form');const start=js?'2029-01-01':'2029-04-02';const until=js?'2029-03-05':'2029-06-04';const skip=js?'2029-01-15':'2029-04-16';
  await form().locator('[name=title]').fill('Own browser series '+js);await form().locator('[name=start]').fill(start+'T18:00');await form().locator('[name=end]').fill(start+'T20:00');await form().locator('[name=description]').fill('Own full recurring agreement ä🙂');
  await form().locator('[name="attendees[]"][value="'+fixture.data.person+'"]').check();await form().locator('[data-series-rule] summary').click();await form().locator('[name=series_frequency]').selectOption('weekly');await form().locator('[name=series_until]').fill(until);await form().locator('[name=series_skip]').fill(skip);
  const key=await form().locator('[name=series_key]').inputValue();
  await Promise.all([page.waitForNavigation(),form().getByRole('button',{name:'Serientermine prüfen',exact:true}).click()]);await form().locator('[data-series-preview]').waitFor();
  check((await form().locator('[data-series-preview]').innerText()).includes('9 Termine'),'real read-only preview includes the exact chosen recurrence exception '+js);
  check(await form().locator('[name=series_key]').inputValue()===key&&await form().locator('[name=description]').inputValue()==='Own full recurring agreement ä🙂','preview preserves the original full description and durable human key '+js);
  for(const width of [320,390,820])for(const scale of [100,200]){
   await page.setViewportSize({width,height:1000});await page.evaluate(value=>document.documentElement.style.fontSize=value+'%',scale);views++;
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'recurrence form fits touch and large text '+js+' '+width+' '+scale);
   check(await form().locator('[name=series_until]').evaluate(el=>el.getBoundingClientRect().height>=43.9),'recurrence date has a touch-sized target '+js+' '+width+' '+scale);
  }
  await page.setViewportSize({width:390,height:1000});await page.evaluate(()=>document.documentElement.style.fontSize='100%');
  await form().locator('[name=series_skip]').fill('2029-02-02');await Promise.all([page.waitForNavigation(),form().getByRole('button',{name:'Termin speichern',exact:true}).click()]);await page.getByRole('alert').filter({hasText:'Ausnahmedatum'}).waitFor();
  check(await form().locator('[name=series_skip]').inputValue()==='2029-02-02'&&await form().locator('[name=series_key]').inputValue()===key,'real invalid exception preserves the correction and original key '+js);
  await form().locator('[name=series_skip]').fill(skip);let accepted='';page.on('request',request=>{if(request.method()==='POST'&&new URLSearchParams(request.postData()).getAll('action').at(-1)==='save-event')accepted=request.postData();});
  await Promise.all([page.waitForNavigation(),form().getByRole('button',{name:'Termin speichern',exact:true}).click()]);await page.waitForURL(url=>url.searchParams.has('series'));
  const id=new URL(page.url()).searchParams.get('series');await page.locator('[data-appointment-series="'+id+'"]').waitFor();
  check(await page.locator('[data-series-occurrence]').count()===6,'real confirmed series opens a complete six-row page '+js);
  const replay=await context.request.post(base+'?view=calendar&new_event=1',{data:accepted,headers:{'Content-Type':'application/x-www-form-urlencoded',Origin:fixture.origin},maxRedirects:0});
  check(replay.status()===303&&new URL(replay.headers().location,fixture.origin).searchParams.get('series')===id,'actual lost creation acknowledgement returns the same original series '+js);
  await page.locator('[data-series-occurrence]').first().getByRole('link',{name:'Einzeltermin öffnen',exact:true}).click();await form().locator('[name=title]').waitFor();await form().locator('[name=title]').fill('Own browser individual exception '+js);
  await Promise.all([page.waitForNavigation(),form().getByRole('button',{name:'Termin speichern',exact:true}).click()]);await page.getByRole('link',{name:'Serie und Ausnahmen öffnen',exact:true}).click();await page.locator('[data-appointment-series="'+id+'"]').waitFor();
  check((await page.locator('[data-series-occurrence]').first().innerText()).includes('Einzeln bearbeitet'),'actual individual edit becomes a protected original exception '+js);
  const second=()=>page.locator('[data-series-occurrence]').nth(1);await Promise.all([page.waitForNavigation(),second().getByRole('button',{name:'Als Ausnahme schützen',exact:true}).click()]);await page.waitForFunction(()=>document.querySelectorAll('[data-series-occurrence]')[1]?.textContent.includes('Bewusst geschützte Ausnahme'));
  await page.locator('[data-series-edit]>summary').click();await form().locator('[name=title]').fill('Own browser standard '+js);await form().locator('[name=start]').fill(start+'T19:00');await form().locator('[name=end]').fill(start+'T21:00');await form().locator('[name=series_from]').fill(start);
  await Promise.all([page.waitForNavigation(),form().getByRole('button',{name:'Künftige Standardtermine aktualisieren',exact:true}).click()]);await page.waitForFunction(()=>document.querySelectorAll('[data-series-occurrence]')[2]?.textContent.includes('Own browser standard'));
  check((await page.locator('[data-series-occurrence]').first().innerText()).includes('Own browser individual exception '+js)&&(await second().innerText()).includes('Own browser series '+js),'actual future update preserves both original individual and consciously pinned exceptions '+js);
  await page.getByRole('link',{name:'Weitere Termine',exact:true}).click();await page.waitForURL(url=>url.searchParams.get('series_page')==='2');await page.waitForFunction(()=>document.querySelectorAll('[data-series-occurrence]').length===3);
  check(await page.locator('[data-series-occurrence]').count()===3,'real series pagination reaches all remaining originals '+js);
  const bad=await context.request.post(base+'?view=calendar&series='+id,{form:{action:'series-cancel',csrf:'own-invalid-csrf',series_id:id,version:'3',series_key:'b'.repeat(32),series_from:start,reason:'Must not cancel'},maxRedirects:0});check(bad.status()===403,'actual original CSRF protects a whole-series decision '+js);
  await page.locator('[data-series-cancel]>summary').click();const cancel=()=>page.locator('[data-series-cancel] form');await cancel().locator('[name=series_from]').fill(start);await cancel().locator('[name=reason]').fill('Own conscious browser cancellation');
  if(js){await cancel().getByRole('button',{name:'Standardtermine absagen',exact:true}).click();await Promise.all([page.waitForNavigation(),page.getByRole('dialog').getByRole('button',{name:'Standardtermine absagen',exact:true}).click()]);}
  else await Promise.all([page.waitForNavigation(),cancel().getByRole('button',{name:'Standardtermine absagen',exact:true}).click()]);
  await page.waitForFunction(()=>document.querySelectorAll('[data-series-occurrence]')[2]?.textContent.includes('Durch Serienregel abgesagt'));
  check((await page.locator('[data-series-occurrence]').first().innerText()).includes('Einzeln bearbeitet')&&(await second().innerText()).includes('Bewusst geschützte Ausnahme'),'real confirmed bulk cancellation preserves the exact exceptions '+js);
  await page.locator('[data-series-edit]>summary').click();await form().locator('[name=series_from]').fill(start);await form().locator('[name=series_revive]').check();
  await Promise.all([page.waitForNavigation(),form().getByRole('button',{name:'Künftige Standardtermine aktualisieren',exact:true}).click()]);await page.waitForFunction(()=>document.querySelectorAll('[data-series-occurrence]')[2]?.textContent.includes('Standardtermin')&&!document.querySelectorAll('[data-series-occurrence]')[2]?.textContent.includes('abgesagt'));
  check((await page.locator('[data-series-occurrence]').first().innerText()).includes('Einzeln bearbeitet'),'actual explicit revival keeps individual changes protected '+js);
  await page.goto(base+'?view=calendar&series='+fixture.data.series);await page.getByRole('link',{name:'Ältere Vorgänge',exact:true}).click();await page.waitForURL(url=>url.searchParams.get('series_history_page')==='2');
  const history=page.locator('a[download="dvm-serienoriginal.json"]').last();const downloadPromise=page.waitForEvent('download');await history.click();const download=await downloadPromise;const original=JSON.parse(fs.readFileSync(await download.path(),'utf8'));
  check(original.operation==='create'&&JSON.parse(original.payload_json).request.data.description==='Whole unchanged private original','real older history download reaches the complete protected initial intention '+js);
  await context.close();
 }
 const context=await browser.newContext({viewport:{width:390,height:1000},javaScriptEnabled:false});await context.route('**/*',r=>new URL(r.request().url()).origin===fixture.origin?r.continue():r.abort());const page=await context.newPage();await page.goto(fixture.origin+'/own-test-enter?role=reader');const forbidden=await page.goto(base+'?view=calendar&series='+fixture.data.series);
 check(forbidden.status()===403&&await page.locator('[data-appointment-series]').count()===0,'current read-only profile role cannot enumerate manager recurrence originals');await context.close();
 console.log(JSON.stringify({suite:'appointment-series-browser',checks,views,external_actions:0}));
}finally{await browser.close();}
