import fs from 'node:fs';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE);
const f=JSON.parse(fs.readFileSync(process.argv[2]));
const browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE,headless:true});
let checks=0;
const check=(v,label)=>{if(!v)throw Error(label);checks++;};
const url=(origin,query)=>origin+'/vereinsverwaltung/?'+new URLSearchParams({view:'finance',tab:'bank',...query});
async function go(page,target){const r=await page.goto(target,{waitUntil:'domcontentloaded'});if(r.status()!==200)console.error(JSON.stringify({target,status:r.status(),alerts:await page.locator('.error,[role=alert]').allTextContents(),body:(await page.locator('body').innerText()).slice(-1500)}));check(r.status()===200,'real authenticated catalogue response');}
async function login(page,origin,role='admin'){
 await go(page,origin+'/login');await page.locator('[name=email]').fill(f.accounts[role].email);await page.locator('[name=password]').fill(f.password);await page.getByRole('button',{name:'Anmelden und Vereinsmanager öffnen',exact:true}).click();
}
async function layout(page,label){
 for(const width of [320,390,820])for(const size of [16,32]){
  await page.setViewportSize({width,height:1000});await page.evaluate(size=>document.documentElement.style.fontSize=size+'px',size);await page.evaluate(()=>document.fonts.ready);
  await page.waitForFunction(()=>document.documentElement.scrollWidth<=innerWidth+1,null,{timeout:3000}).catch(async error=>{await page.screenshot({path:'/workspace/kulturhus-bank-catalogue-overflow.png',fullPage:false});console.error(await page.evaluate(()=>[...document.querySelectorAll('body *')].filter(e=>e.getBoundingClientRect().right>innerWidth+1).slice(0,8).map(e=>({tag:e.tagName,cls:e.className,right:e.getBoundingClientRect().right}))));throw error;});
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),label+' fits '+width+'/'+size);
 }
 await page.evaluate(()=>document.documentElement.style.fontSize='16px');await page.setViewportSize({width:390,height:1000});
}
try{
 for(const app of ['front','direct'])for(const js of [false,true]){
  console.log('Bank catalogue browser '+app+'/'+js);
  const origin=f.origins[app],data=f.data.cases[app+(js?'-js':'-nojs')];
  const context=await browser.newContext({javaScriptEnabled:js,viewport:{width:390,height:1000},hasTouch:true});
  let external=0;await context.route('**/*',r=>new URL(r.request().url()).origin===origin?r.continue():(external++,r.abort()));
  const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));await login(page,origin);
  await go(page,origin+'/vereinsverwaltung/?'+new URLSearchParams({view:'finance',tab:'receipts',entry:data.reverse_receipt,payment_match:'1',match_account:f.data.bank,match_page:'51'}));
  const scope={account:f.data.bank,bank_page:'26',bank_q:'',assignment:'open',bank_from:'2021-06-01',bank_to:'2021-06-01',bank_direction:'income',bank_amount:'2.50'};
  await go(page,url(origin,scope));await page.locator('[data-bank-journal-paged]').waitFor();
  check(await page.locator('[data-bank-journal-row]').count()<=40,'bounded complete bank page');
  const originalPage=await page.locator('[name=bank_page][type=number]').inputValue();
  check(Number(originalPage)>=25,'old ledger page is still accessible after earlier assignments');
  await layout(page,'paginated bank list');if(app==='front'&&!js)await page.screenshot({path:'/workspace/kulturhus-bank-catalogue-mobile.png',fullPage:true});
  await page.locator('[data-bank-transaction="'+data.source+'"]').getByRole('link').first().click();await page.getByRole('heading',{name:'Kontoumsatz zuordnen',exact:true}).waitFor();
  check(new URL(page.url()).searchParams.get('bank_page')===originalPage,'opening original preserves actual bank page');
  check(new URL(page.url()).searchParams.get('bank_from')===scope.bank_from,'opening original preserves date filters');
  if(data.receipt==='receipt-0'){
   const pager=page.locator('form').filter({has:page.locator('input[name=match_page][type=number]')});await pager.locator('[name=match_page]').fill('51');await pager.getByRole('button',{name:'Seite anzeigen',exact:true}).click();
  }else{
   const search=page.locator('form').filter({has:page.getByLabel('Beleg suchen',{exact:true})});await search.getByLabel('Beleg suchen',{exact:true}).fill(data.reference);await search.getByRole('button',{name:'Suchen',exact:true}).click();await page.waitForURL(u=>u.searchParams.get('match_q')===data.reference);
  }
  console.log('Bank catalogue allocate '+app+'/'+js+' '+page.url());
  const receipt=page.locator('form').filter({has:page.locator('[name=entry_id][value="'+data.receipt+'"]')});await receipt.waitFor();
  check(await receipt.locator('[name=amount]').inputValue()==='2.50','exact older receipt amount is retained');
  await receipt.getByRole('button',{name:'Zuordnen',exact:true}).click();await page.getByText('Zuordnung gespeichert. Der Kontostand bleibt unverändert.',{exact:true}).waitFor();
  check(new URL(page.url()).searchParams.get('bank_page')===originalPage,'successful assignment preserves list context: '+originalPage+' => '+page.url());
  await page.getByRole('link',{name:'Zurück zum Konto',exact:true}).click();await page.locator('[data-bank-journal-paged]').waitFor();
  check(new URL(page.url()).searchParams.get('assignment')==='open'&&new URL(page.url()).searchParams.get('bank_amount')==='2.50','back to current complete filtered ledger');
  check(await page.locator('[data-bank-transaction="'+data.source+'"]').count()===0,'assigned original leaves the open queue');
  const search=page.locator('[data-bank-journal-filter]');await search.locator('[name=bank_q]').fill(data.auto_source);await search.getByRole('button',{name:'Filter anwenden',exact:true}).click();
  await page.locator('[data-bank-transaction="'+data.auto_source+'"]').waitFor();check(await page.locator('[data-bank-journal-row]').count()===1,'server search finds a newer original outside the previously displayed page');
  const automatic=page.locator('form').filter({has:page.locator('[name=action][value=auto-match]')});
  check(await automatic.locator('[name="transactions[]"]').count()===1&&await automatic.locator('[name="transactions[]"]').inputValue()===data.auto_source,'automatic form carries exactly the visible filtered original');
  await automatic.getByRole('button',{name:'Umsätze dieser Seite prüfen',exact:true}).click().catch(async error=>{await page.screenshot({path:'/workspace/kulturhus-bank-catalogue-button.png',fullPage:true});console.error(await automatic.evaluate(el=>{const rows=[];for(let e=el;e;e=e.parentElement)rows.push({tag:e.tagName,cls:e.className,hidden:e.hidden,display:getComputedStyle(e).display,visibility:getComputedStyle(e).visibility,rect:e.getBoundingClientRect().toJSON()});return rows;}));throw error;});await page.getByText('1 Umsätze dieser Seite eindeutig zugeordnet. Übrige Umsätze bitte manuell prüfen.',{exact:true}).waitFor();
  check(new URL(page.url()).searchParams.get('bank_q')===data.auto_source&&await page.locator('[data-bank-journal-row]').count()===0,'actual automatic matching keeps filters and removes the classified open original');
  await go(page,url(origin,{...scope,transaction:data.outgoing,own_page:'51',own_account:f.data.second}));
  const pair=page.locator('form').filter({has:page.locator('[name=other_id][value="'+data.counter+'"]')});await pair.waitFor();
  await pair.locator('[name=reason]').fill('Tatsächlicher eigener Gegenumsatz aus vollständigem Katalog');await pair.getByRole('button',{name:'Als eigenen Geldtransfer zuordnen',exact:true}).click();
  await page.getByRole('heading',{name:'Geldtransfers zwischen eigenen Konten',exact:true}).waitFor();
  check(new URL(page.url()).searchParams.get('own_page')==='51','own matching page retained after successful classification');
  check(await page.getByText('Noch keine Zuordnung vorhanden.',{exact:true}).count()===0,'own transfer has no contradictory empty receipt history');
  await page.getByRole('link',{name:'Gegenumsatz öffnen',exact:true}).click();await page.waitForURL(u=>u.searchParams.get('transaction')===data.counter);await page.getByRole('heading',{name:'Geldtransfers zwischen eigenen Konten',exact:true}).waitFor();
  check(new URL(page.url()).searchParams.get('transaction')===data.counter,'actual older counterpart opens');
  check(new URL(page.url()).searchParams.get('bank_from')===scope.bank_from,'counterpart keeps source list filters');
  console.log('Bank catalogue receipt to bank '+app+'/'+js);
  await go(page,origin+'/vereinsverwaltung/?'+new URLSearchParams({view:'finance',tab:'receipts',entry:data.reverse_receipt,payment_match:'1',match_account:f.data.bank,match_page:'51'}));
  const receiptSearch=page.locator('form').filter({has:page.getByLabel('Umsatz suchen',{exact:true})});
  await receiptSearch.getByLabel('Umsatz suchen',{exact:true}).fill(data.reverse_source);await receiptSearch.getByRole('button',{name:'Suchen',exact:true}).click();await page.waitForURL(u=>u.searchParams.get('match_q')===data.reverse_source);
  const original=page.locator('form').filter({has:page.locator('[name=transaction_id][value="'+data.reverse_source+'"]')});await original.waitFor();
  check(new URL(page.url()).searchParams.get('payment_match')==='1','receipt search retains the explicitly opened matching area');
  check(await page.locator('.matching-candidate').count()===1,'receipt search covers originals outside the displayed last bank page');
  await original.getByRole('button',{name:'Zuordnen',exact:true}).click();await page.getByText('Zuordnung gespeichert. Der Kontostand bleibt unverändert.',{exact:true}).waitFor();
  check(new URL(page.url()).searchParams.get('match_q')===data.reverse_source&&new URL(page.url()).searchParams.get('match_account')===f.data.bank,'receipt assignment preserves the full candidate search');
  await layout(page,'receipt matching result');check(errors.length===0&&external===0,'no script errors or external requests');await context.close();
 }
 for(const app of ['front','direct']){
  const origin=f.origins[app],context=await browser.newContext({javaScriptEnabled:false});const page=await context.newPage();await login(page,origin,'reader');await go(page,url(origin,{account:f.data.bank,bank_q:'%'}));
  check(await page.locator('[data-finance-readonly]').count()===1&&await page.locator('[data-bank-journal-row]').count()===1,'reader can search complete own originals');
  check(await page.getByRole('button',{name:'Bargeldbewegung erfassen und zuordnen',exact:true}).count()===0,'reader has no cash mutation control');await context.close();
 }
 console.log(JSON.stringify({suite:'bank-catalogue-browser',checks,external_actions:0}));
}finally{await browser.close();}
