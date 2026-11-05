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
  const page=await context.newPage();await page.goto(fixture.origin+'/own-test-enter?role=admin');await page.goto(base+'?view=inventory&tab=loans');
  await page.getByRole('link',{name:'+ Zeitfenster planen',exact:true}).click();await page.locator('[data-iv-loan-form] [name="data[window_start]"]').waitFor();
  const creationUrl=page.url();const form=()=>page.locator('[data-iv-loan-form]');const key=await form().locator('[name=request_key]').inputValue();const date=js?'2028-09-01':'2028-09-02';
  const contact=form().locator('[name="data[contact]"]');
  if(await contact.isVisible())await contact.selectOption(fixture.data.person);else{await form().getByRole('combobox',{name:'Mitglied / Kontakt *',exact:true}).fill('Own timed borrower');await page.getByRole('option',{name:'Own timed borrower · Kontakt',exact:true}).tap();}
  await form().locator('[name="data[purpose]"]').fill('Own browser timed use '+js);await form().locator('[name="data[notes]"]').fill('Own full human timed agreement ä🙂');
  await form().locator('[name="data[window_start]"]').fill(date+'T09:00');await form().locator('[name="data[window_end]"]').fill(date+'T10:00');
  const stock=await form().locator('[data-iv-line] select option').evaluateAll((options,id)=>options.find(o=>o.value.startsWith(id+':')).value,fixture.data.item);
  await form().locator('[data-iv-line] select').selectOption(stock);await form().locator('[data-iv-line] [type=number]').fill('2');
  await Promise.all([page.waitForNavigation(),form().getByRole('button',{name:'Verfügbarkeit prüfen',exact:true}).click()]);
  check(await form().locator('[data-loan-window-preview]').innerText().then(s=>s.includes('2 im Zeitraum verfügbar')&&s.includes('Menge möglich')),'actual read-only availability POST uses exact chosen window '+js);
  check(await form().locator('[name=request_key]').inputValue()===key&&await form().locator('[name="data[notes]"]').inputValue()==='Own full human timed agreement ä🙂','preview retains original agreement and durable request key '+js);
  for(const width of [320,390,820])for(const scale of [100,200]){
   await page.setViewportSize({width,height:1000});await page.evaluate(value=>document.documentElement.style.fontSize=value+'%',scale);views++;
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'timed form fits '+js+' '+width+' '+scale);
   check(await form().locator('[name="data[window_start]"]').evaluate(el=>el.getBoundingClientRect().height>=43.9),'timed input has a touch-sized target '+js+' '+width+' '+scale);
  }
  await page.setViewportSize({width:390,height:1000});await page.evaluate(()=>document.documentElement.style.fontSize='100%');
  await form().locator('[name="data[window_end]"]').fill(date+'T08:00');await Promise.all([page.waitForNavigation(),form().getByRole('button',{name:'Zeitfenster bestätigen',exact:true}).click()]);
  await page.getByRole('alert').filter({hasText:'muss nach dem Beginn'}).waitFor();
  check(await page.getByRole('alert').filter({hasText:'muss nach dem Beginn'}).count()>0&&await form().locator('[name=request_key]').inputValue()===key,'real invalid window is rejected without losing the original request '+js);
  check(await form().locator('[name="data[window_start]"]').inputValue()===date+'T09:00'&&await form().locator('[data-iv-line] select').inputValue()===stock,'invalid end preserves original start and chosen stock version '+js);
  await form().locator('[name="data[window_end]"]').fill(date+'T10:00');
  let submitted='';page.on('request',request=>{if(request.method()==='POST'&&new URLSearchParams(request.postData()).get('action')==='inventory-reserve')submitted=request.postData();});
  await Promise.all([page.waitForNavigation(),form().getByRole('button',{name:'Zeitfenster bestätigen',exact:true}).click()]);await page.waitForURL(url=>url.searchParams.has('loan'));
  const loan=new URL(page.url()).searchParams.get('loan');check((await page.locator('.iv-loan-facts').innerText()).includes('Geplant')&&(await page.locator('.iv-loan-facts').innerText()).includes('09:00'),'actual confirmed plan displays timed status and exact local interval '+js);
  const replay=await context.request.post(creationUrl,{data:submitted,headers:{'Content-Type':'application/x-www-form-urlencoded',Origin:fixture.origin},maxRedirects:0});
  check(replay.status()===303&&new URL(replay.headers().location,fixture.origin).searchParams.get('loan')===loan,'real lost save acknowledgement returns the exact same planned loan '+js);
  const detail=page.locator('details').filter({has:page.locator('summary',{hasText:'Zeitfenster verschieben'})});await detail.locator('summary').click();
  await detail.locator('[name="data[window_start]"]').fill(date+'T10:00');await detail.locator('[name="data[window_end]"]').fill(date+'T11:00');await detail.locator('[name="data[reason]"]').fill('Own conscious later use');
  await Promise.all([page.waitForNavigation(),detail.getByRole('button',{name:'Zeitfenster speichern',exact:true}).click()]);
  await page.waitForFunction(()=>document.querySelector('.iv-loan-facts')?.textContent.includes('11:00'));
  check((await page.locator('.iv-loan-facts').innerText()).includes('10:00')&&(await page.locator('.iv-loan-facts').innerText()).includes('11:00'),'actual native reschedule persists the consciously confirmed new interval '+js);
  const rejected=await context.request.post(page.url(),{form:{action:'inventory-cancel',target:loan,version:'2',request_key:'a'.repeat(32),csrf:'own-invalid-csrf','data[date]':date,'data[reason]':'Must not cancel'},maxRedirects:0});check(rejected.status()===403,'current original CSRF protects timed decisions '+js);
  await page.goto(base+'?view=inventory&tab=loans&loan='+fixture.data.shortage);
  check(await page.locator('[data-loan-window-shortage]').innerText().then(text=>text.includes('1 verfügbar · 2 geplant')),'actual original plan exposes a changed physical shortage '+js);
  await context.close();
 }
 const context=await browser.newContext({viewport:{width:390,height:950},javaScriptEnabled:false});await context.route('**/*',r=>new URL(r.request().url()).origin===fixture.origin?r.continue():r.abort());const page=await context.newPage();await page.goto(fixture.origin+'/own-test-enter?role=reader');await page.goto(base+'?view=inventory&tab=loans&new_loan=1&timed=1');
 check(await page.locator('[data-iv-loan-form]').count()===0&&await page.getByRole('link',{name:'+ Zeitfenster planen',exact:true}).count()===0,'current read-only loan role sees plans without write controls');await context.close();
 console.log(JSON.stringify({suite:'inventory-loan-windows-browser',checks,views,external_actions:0}));
}finally{await browser.close();}
