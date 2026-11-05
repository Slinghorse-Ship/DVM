import fs from 'node:fs';
const fixture=JSON.parse(fs.readFileSync(0,'utf8'));
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE);
const browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE,headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
let checks=0,views=0;
const check=(ok,label)=>{if(!ok)throw Error(label);checks++;console.log('PASS '+label);};const base=fixture.origin+'/vereinsverwaltung/';
const configure=async(page,js)=>{
 await page.goto(base+'?view=free&new_table=1');const form=page.locator('form').filter({has:page.locator('[name=action][value=free-create]')});
 await form.locator('[name=title]').fill('Own browser calculated sheet '+js);
 for(let i=0;i<6;i++){await form.locator('[name="column_names[]"]').nth(i).fill(['Quantity','Price','Total','Caption','External',''][i]);if(i<5)await form.locator('[name="column_types[]"]').nth(i).selectOption(['number','number','formula','link','formula'][i]);}
 await Promise.all([page.waitForNavigation(),form.getByRole('button',{name:'Liste speichern',exact:true}).click()]);await page.waitForURL(url=>url.searchParams.has('sheet'));return new URL(page.url()).searchParams.get('sheet');
};
try{
 for(const js of [true,false]){
  const context=await browser.newContext({viewport:{width:390,height:1000},hasTouch:true,javaScriptEnabled:js,acceptDownloads:true,serviceWorkers:'block'});await context.route('**/*',route=>new URL(route.request().url()).origin===fixture.origin?route.continue():route.abort());
  const page=await context.newPage();page.on('pageerror',error=>console.log('PAGE ERROR',error.message));await page.goto(fixture.origin+'/own-test-enter?role=admin');const id=await configure(page,js);
  const newRow=()=>page.locator('.free-add-row');const rows=()=>page.locator('tr[data-free-id]:not(.free-add-row)');const fill=async(row,values)=>{for(let i=0;i<values.length;i++)await row.locator('[name="cells[]"]').nth(i).fill(values[i]);};
  const firstValues=['3','0,10','=[A]*[B]','=@{'+fixture.data.source+':'+fixture.data.source_row+':A}','=@{'+fixture.data.source+':'+fixture.data.source_row+':B}*2'];
  await fill(newRow(),firstValues);await Promise.all([page.waitForNavigation(),newRow().getByRole('button',{name:'Neue Zeile hinzufügen',exact:true}).click()]);await rows().first().waitFor();
  const firstId=await rows().first().getAttribute('data-free-id');check(await rows().count()===1&&(await rows().first().locator('[data-free-calculation="2"]').innerText())==='Ergebnis: 0.3','real '+(js?'automatic':'explicit no-JavaScript')+' formula save produces one exact original row');
  check((await rows().first().locator('[data-free-calculation="3"]').innerText()).includes('Own exact revised source')&&(await rows().first().locator('[data-free-calculation="4"]').innerText())==='Ergebnis: 5','actual same-manager links resolve authorized original text and number '+js);
  for(const width of [320,390,820])for(const scale of [100,200]){
   await page.setViewportSize({width,height:1000});await page.evaluate(value=>document.documentElement.style.fontSize=value+'%',scale);views++;
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'formula grid keeps horizontal scrolling inside its touch region '+js+' '+width+' '+scale);
   check(await rows().first().locator('[name="cells[]"]').nth(2).evaluate(el=>el.getBoundingClientRect().height>=43.9),'formula editing has a touch-sized target '+js+' '+width+' '+scale);
  }
  await page.setViewportSize({width:390,height:1000});await page.evaluate(()=>document.documentElement.style.fontSize='100%');
  const row=()=>page.locator('tr[data-free-id="'+firstId+'"]');const oldVersion=await row().locator('[name=version]').inputValue();await row().locator('[name="cells[]"]').nth(2).fill('=1/');
  if(js)await page.locator('[data-free-status][data-error]').waitFor();else await Promise.all([page.waitForNavigation(),row().getByRole('button',{name:'Speichern',exact:true}).click()]);
  check(await row().locator('[name="cells[]"]').nth(2).inputValue()==='=1/'&&await row().locator('[name=version]').inputValue()===oldVersion,'invalid formula keeps the exact correction and original version '+js);
  await row().locator('[name="cells[]"]').nth(2).fill('=RUNDEN([A]*[B];2)');
  if(js)await page.waitForFunction(()=>document.querySelector('[data-free-status]')?.textContent==='Alle Änderungen gespeichert.');else await Promise.all([page.waitForNavigation(),row().getByRole('button',{name:'Speichern',exact:true}).click()]);
  await row().getByRole('link',{name:'Zellverweise',exact:true}).click();await page.locator('[data-free-references]').waitFor();const reference=page.getByRole('textbox',{name:'Zellverweis Total',exact:true});await reference.focus();const fixed=await reference.inputValue();
  check(fixed==='=@{'+id+':'+firstId+':C}'&&await reference.evaluate(el=>el.selectionStart===0&&el.selectionEnd===el.value.length || !window.KulturhusPage),'actual original reference remains available with keyboard and without clipboard permission '+js);
  await page.getByRole('link',{name:'Zur Liste zurück',exact:true}).click();await fill(newRow(),['9','0,20','=[A]*[B]','=@{'+id+':'+firstId+':D}',fixed+'*2']);
  await Promise.all([page.waitForNavigation(),newRow().getByRole('button',{name:'Neue Zeile hinzufügen',exact:true}).click()]);await page.waitForFunction(()=>document.querySelectorAll('tr[data-free-id]:not(.free-add-row)').length===2);
  check((await rows().last().locator('[data-free-calculation="4"]').innerText())==='Ergebnis: 0.6','new cross-row reference resolves the stable original calculation '+js);
  const quantity=()=>page.locator('.free-grid thead a').filter({hasText:'Quantity'});await quantity().click();await page.waitForURL(url=>url.searchParams.get('sort')==='0'&&url.searchParams.get('direction')==='asc');await page.waitForFunction(()=>[...document.querySelectorAll('.free-grid thead a')].some(a=>a.textContent.includes('Quantity')&&new URL(a.href).searchParams.get('direction')==='desc'));await quantity().click();await page.waitForURL(url=>url.searchParams.get('direction')==='desc');await page.waitForFunction(()=>document.querySelector('.free-grid thead a')?.textContent.includes('↓'));
  check(await rows().first().getAttribute('data-free-id')!==firstId&&(await rows().first().locator('[data-free-calculation="4"]').innerText())==='Ergebnis: 0.6','sorting never retargets an existing cross-row cell '+js);
  const downloadPromise=page.waitForEvent('download',{timeout:10000}).catch(async error=>{console.log('EXPORT DIAGNOSTIC',JSON.stringify({url:page.url(),alerts:await page.locator('[role=alert]').allTextContents(),dialogs:await page.locator('[role=dialog]').allTextContents(),status:await page.locator('[data-free-status]').allTextContents()}));throw error;});await page.getByRole('button',{name:'CSV exportieren',exact:true}).click();const download=await downloadPromise;const csv=fs.readFileSync(await download.path(),'utf8');check(csv.includes('0.3')&&csv.includes('1.8')&&!csv.includes('=@{')&&!csv.includes('=[A]'),'real CSV download contains current authorized results without executable expressions '+js);
  if(js){await row().locator('[name="cells[]"]').nth(0).fill('4');const pendingDownload=page.waitForEvent('download');await page.getByRole('button',{name:'CSV exportieren',exact:true}).click();const pending=await pendingDownload;const changed=fs.readFileSync(await pending.path(),'utf8');check(changed.includes('0.4')&&changed.includes('0.8'),'export waits for a pending real cell save and includes both recomputed dependent values');}
  const search=page.getByRole('searchbox',{name:'In Liste suchen',exact:true});await search.fill('1.8');if(js)await page.waitForURL(url=>url.searchParams.get('q')==='1.8');else await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Suchen',exact:true}).click()]);await page.waitForFunction(()=>document.querySelectorAll('tr[data-free-id]:not(.free-add-row)').length===1);check(await rows().count()===1,'search finds a calculated result '+js);
  await page.goto(base+'?view=free&sheet='+id+'&tools=1');const share=()=>page.locator('form').filter({has:page.locator('[name=target_actor][value=own-price-reader]')});await share().locator('[name=access]').selectOption('read');await Promise.all([page.waitForNavigation(),share().getByRole('button',{name:'Freigabe speichern',exact:true}).click()]);
  const reading=await browser.newContext({javaScriptEnabled:false});const rp=await reading.newPage();await rp.goto(fixture.origin+'/own-test-enter?role=reader');await rp.goto(base+'?view=free&sheet='+id);check((await rp.locator('[data-free-calculation][data-error]').count())>=2&&!(await rp.content()).includes('Own exact revised source')&&await rp.locator('tr[data-free-id] [name="cells[]"]').count()===0,'actual read share exposes no private source or editing control '+js);
  for(const access of ['read','none']){
   await page.goto(base+'?view=free&sheet='+fixture.data.source+'&tools=1');await share().locator('[name=access]').selectOption(access);await Promise.all([page.waitForNavigation(),share().getByRole('button',{name:'Freigabe speichern',exact:true}).click()]);await page.waitForURL(url=>url.searchParams.get('sheet')===fixture.data.source&&!url.searchParams.has('tools'));await page.locator('.free-workspace').waitFor();await page.waitForLoadState('load');await page.goto(base+'?view=free&sheet='+fixture.data.source+'&tools=1');check(await share().locator('[name=access]').inputValue()===access,'actual source share persists '+access+' '+js);await rp.goto(base+'?view=free&sheet='+id);
   check((await rp.content()).includes('Own exact revised source')===(access==='read'),'actual source '+access+' immediately controls linked result visibility '+js);
  }
  await reading.close();const bad=await context.request.post(base+'?view=free&sheet='+id,{form:{action:'free-row',csrf:'own-invalid-csrf',sheet:id,row:firstId,version:'1','cells[]':'9'},maxRedirects:0});check(bad.status()===403,'actual CSRF blocks a fabricated formula write '+js);
  await page.goto(base+'?view=free&sheet='+id);const chosen=()=>page.locator('tr[data-free-id="'+firstId+'"]');
  if(js){await chosen().getByRole('button',{name:'Zeile löschen',exact:true}).click();await Promise.all([page.waitForNavigation(),page.getByRole('dialog').getByRole('button',{name:'Zeile löschen',exact:true}).click()]);}
  else{await chosen().getByLabel('Löschen bestätigen',{exact:true}).check();await Promise.all([page.waitForNavigation(),chosen().getByRole('button',{name:'Zeile löschen',exact:true}).click()]);}
  await page.waitForFunction(()=>document.querySelectorAll('tr[data-free-id]:not(.free-add-row)').length===1);check(await chosen().count()===0,'actual consciously confirmed row removal retains an original archive path '+js);
  await page.getByRole('link',{name:'Zeilenarchiv',exact:true}).click();await chosen().waitFor();await chosen().getByRole('checkbox',{name:'Zeile auswählen',exact:true}).check();await page.locator('[name=bulk_mode]').selectOption('restore');await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Ausführen',exact:true}).click()]);await chosen().waitFor();
  check((await chosen().locator('[data-free-calculation="2"]').innerText())==='Ergebnis: '+(js?'0.4':'0.3'),'actual archive restoration retains the same row identity and exact original expression '+js);
  await context.close();
 }
 console.log(JSON.stringify({suite:'free-table-formulas-browser',checks,views,external_actions:0}));
}finally{await browser.close();}
