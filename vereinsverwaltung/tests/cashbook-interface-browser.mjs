import fs from 'node:fs';
const fixture=JSON.parse(fs.readFileSync(0,'utf8'));
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE);
const browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE,headless:true});
let checks=0,views=0,external=0,stage='start';const errors=[];
const check=(ok,label)=>{if(!ok)throw Error(label);checks++;};
const go=(page,url)=>page.goto(url,{waitUntil:'domcontentloaded'});
const url=(origin,query={})=>origin+'/vereinsverwaltung/?'+new URLSearchParams({view:'finance',tab:'bank',...query});
async function login(page,origin,role,locale){
 await go(page,origin+'/login');await page.locator('[name=email]').fill(fixture.accounts[role].email);await page.locator('[name=password]').fill(fixture.password);
 await Promise.all([page.waitForResponse(r=>r.request().method()==='POST'&&new URLSearchParams(r.request().postData()||'').get('operation')==='login'),page.getByRole('button',{name:/^(Anmelden und Vereinsmanager öffnen|Sign in and open club manager)$/}).click()]);
 await page.waitForFunction(()=>!document.querySelector('form [name=operation][value=login]')&&document.querySelector('main')&&!/^\/login\/?$/.test(location.pathname));
 await go(page,origin+'/account?section=language');const form=page.locator('form:has([name=operation][value=select-language])');await form.locator('[name=interface_locale]').selectOption(locale);await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),form.getByRole('button').click()]);
 return page.locator('form:has([name=operation][value=select-language]) [name=csrf]').inputValue();
}
async function fit(page,label,scope){
 for(const width of [320,390,820])for(const size of [16,32]){
  await page.setViewportSize({width,height:1000});await page.evaluate(value=>document.documentElement.style.fontSize=value+'px',size);
  await page.waitForFunction(()=>document.documentElement.scrollWidth<=innerWidth+1,null,{timeout:3000}).catch(()=>{});
  const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1);
  if(overflow)console.error(await page.evaluate(()=>[...document.querySelectorAll('body *')].filter(e=>e.getBoundingClientRect().right>innerWidth+1).slice(0,10).map(e=>({tag:e.tagName,cls:e.className,right:e.getBoundingClientRect().right,overflow:getComputedStyle(e).overflow,width:e.getBoundingClientRect().width,parent:e.parentElement?.className}))));
  check(!overflow,label+' fits '+width+'/'+size);
  check(await page.locator(scope+' a,'+scope+' summary,'+scope+' button,'+scope+' input:not([type=hidden]):not([type=radio]),'+scope+' select').evaluateAll(nodes=>nodes.filter(n=>n.getClientRects().length).every(n=>n.getBoundingClientRect().height>=43.5)),label+' has44px controls');views++;
 }
 await page.evaluate(()=>document.documentElement.style.fontSize='16px');await page.setViewportSize({width:390,height:1000});
}
const money=cents=>new Intl.NumberFormat('de-DE',{style:'currency',currency:'EUR'}).format(cents/100).replaceAll(/\s/g,'');
const textMoney=text=>text.replaceAll(/\s/g,'');
const submit=async(page,form)=>{await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),form.getByRole('button').first().press('Enter')]);};
try{
 const profiles=fixture.data.profiles.filter(p=>!process.env.DVM_CASHBOOK_CASE||process.env.DVM_CASHBOOK_CASE===p.tag);if(!profiles.length)throw Error('Actual cashbook profile unavailable.');
 for(const[index,profile]of profiles.entries()){
  stage=profile.tag;console.log('Cashbook '+stage);const en=profile.locale==='en',origin=fixture.origins[profile.app],data=fixture.data;
  const context=await browser.newContext({javaScriptEnabled:profile.js,viewport:{width:390,height:1000},hasTouch:true,serviceWorkers:'block',extraHTTPHeaders:{'X-Owned-Cashbook-Client':String(index+1)}});await context.route('**/*',route=>new URL(route.request().url()).origin===origin?route.continue():(external++,route.abort()));
  const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));await login(page,origin,'admin',profile.locale);
  const scope={account:data.cash,from:'2021-06-01',to:'2021-06-02',bank_q:'0 <&> original cash context'};
  await go(page,url(origin,{...scope,cash_page:'2'}));await page.locator('[data-cashbook-interface]').waitFor();
  let rows=page.locator('[data-cashbook-transaction]');check(await rows.count()===40,'actual middle cash page renders exactly forty original movements');
  check(JSON.stringify(await rows.evaluateAll(nodes=>nodes.map(n=>n.dataset.cashbookTransaction)))===JSON.stringify(data.pages[2].rows.map(r=>r.transaction_id)),'actual middle cash page has exact original chronological source identities');
  const match=page.locator('form:has([name=action][value=auto-match])');check(JSON.stringify(await match.locator('[name="transactions[]"]').evaluateAll(nodes=>nodes.map(n=>n.value)))===JSON.stringify(data.pages[2].rows.map(r=>r.transaction_id)),'actual match form selects exactly the visible middle page and no hidden last-page sources');
  check(textMoney(await page.locator('[data-cashbook-page-opening]').innerText())===money(data.pages[2].page_opening)&&textMoney(await page.locator('[data-cashbook-page-closing]').innerText())===money(data.pages[2].page_closing),'actual middle page carries its complete preceding balance and exact resulting cents');
  check((await page.locator('[data-cashbook-interface]').innerText()).includes(en?'not a tax income and expense report':'keine steuerliche Einnahmen-Ausgaben-Auswertung'),'cash overview explains physical flows separately from tax figures');
  await fit(page,'actual middle cash page','[data-cashbook-interface]');if(index===0){await page.screenshot({path:'/workspace/kulturhus-gen44-browser-cashbook.png',fullPage:false});await page.locator('[data-cashbook-transaction]').first().scrollIntoViewIfNeeded();await page.screenshot({path:'/workspace/kulturhus-gen44-browser-cashbook-rows.png',fullPage:false});}
  const sourceLink=page.locator('[data-cashbook-source]').first();const chosen=new URL(await sourceLink.getAttribute('href'),origin);check(chosen.searchParams.get('cash_page')==='2'&&chosen.searchParams.get('bank_q')===scope.bank_q,'actual cash source link keeps exact page period and literal original search');
  await Promise.all([page.waitForURL(u=>u.searchParams.has('transaction')),sourceLink.press('Enter')]);await page.locator('[data-transaction-remaining]').waitFor();const back=page.getByRole('link',{name:en?'Back to account':'Zurück zum Konto',exact:true});await Promise.all([page.waitForURL(u=>!u.searchParams.has('transaction')),back.press('Enter')]);
  check(new URL(page.url()).searchParams.get('cash_page')==='2'&&await page.locator('[data-cashbook-transaction]').count()===40,'actual transaction and account return preserve the original cash page');
  await Promise.all([page.waitForURL(u=>u.searchParams.get('cash_page')==='3'),page.locator('.bank-catalogue-pager a[rel=next]').press('Enter')]);check(await page.locator('[data-cashbook-transaction]').count()===4&&textMoney(await page.locator('[data-cashbook-page-closing]').innerText())===money(data.pages[3].closing),'actual next-page link reaches every final source and the complete-period closing balance');await fit(page,'actual final cash page','[data-cashbook-interface]');
  const sourcePage=Object.values(data.pages).find(p=>p.rows.some(r=>r.id===data.source)).page;await go(page,url(origin,{...scope,cash_page:String(sourcePage)}));const article=page.locator('[data-cashbook-transaction="'+data.source+'"]');
  check(await article.locator('.cashbook-receipts a').first().innerText()==='0'&&await article.locator('.cashbook-receipts a').nth(1).innerText()===data.literal,'actual cash receipt links preserve literal zero comma whole Unicode and German label-like originals');check(await article.locator('.cashbook-association').innerText()===(en?'Own-account transfer':'Eigener Geldtransfer'),'only the typed financial association label follows the chosen language');await fit(page,'actual typed cash receipt originals','[data-cashbook-interface]');
  let filter=page.locator('[data-cashbook-filter]');await filter.locator('[name=cash_page]').fill('1.5');await submit(page,filter);check(await page.locator('[data-cashbook-filter] [name=cash_page]').inputValue()==='1.5'&&(await page.locator('[data-cashbook-error]').innerText()).includes(en?'whole page number':'ganze Zahl'),'actual rejected page retains whole invalid input and translated correction');check(await page.locator('[data-cashbook-period-totals]').count()===0&&await page.locator('form:has([name=action][value=auto-match])').count()===0,'actual rejected page invents no period totals or unrelated matching action');await fit(page,'actual cash page correction','[data-cashbook-interface]');
  filter=page.locator('[data-cashbook-filter]');await filter.locator('[name=cash_page]').fill('1');await filter.locator('[name=from]').fill('2021-06-02');await filter.locator('[name=to]').fill('2021-06-01');await submit(page,filter);check(await page.locator('[data-cashbook-filter] [name=from]').inputValue()==='2021-06-02'&&await page.locator('[data-cashbook-filter] [name=to]').inputValue()==='2021-06-01'&&await page.locator('[data-cashbook-error]').count()===1,'actual reversed date error retains both dates and usable correction');
  filter=page.locator('[data-cashbook-filter]');await filter.locator('[name=from]').fill('2021-06-01');await filter.locator('[name=to]').fill('2021-06-02');await submit(page,filter);check(await page.locator('[data-cashbook-transaction]').count()===40,'explicit corrected period and page restore actual cash originals');
  const[download]=await Promise.all([page.waitForEvent('download'),page.locator('#cash-export button').click()]);check(fs.readFileSync(await download.path(),'utf8')===data.csv,'actual CSV button downloads every whole-period original byte rather than the visible forty rows');
  await go(page,url(origin,{account:data.cash,from:'2021-05-30',to:'2021-05-30'}));check(await page.locator('[data-cashbook-transaction]').count()===0&&(await page.locator('[data-cashbook-interface]').innerText()).includes(en?'No cash movements in this period.':'Keine Bargeldbewegungen in diesem Zeitraum.'),'actual empty cash period explains its state outside a wide table');await fit(page,'actual empty cash period','[data-cashbook-interface]');await context.close();
  for(const[role,offset]of[['reader',20],['foreign',40]]){
   const other=await browser.newContext({javaScriptEnabled:profile.js,extraHTTPHeaders:{'X-Owned-Cashbook-Client':String(index+offset)}});await other.route('**/*',route=>new URL(route.request().url()).origin===origin?route.continue():(external++,route.abort()));const otherPage=await other.newPage();otherPage.on('pageerror',e=>errors.push(e.message));await login(otherPage,origin,role,profile.locale);await go(otherPage,url(origin,{...scope,cash_page:'2'}));
   if(role==='reader')check(await otherPage.locator('[data-cashbook-transaction]').count()===40&&await otherPage.locator('form:has([name=action][value=auto-match]),form:has([name=action][value=close-cashbook])').count()===0,'actual finance reader sees whole original cash page without matching or closing controls');
   else check(await otherPage.locator('[data-cashbook-transaction]').count()===0&&!(await otherPage.locator('body').innerText()).includes('Whole period original'),'foreign account cannot disclose original cash movement text');
   await other.close();
  }
 }
 check(external===0&&errors.length===0,'all cashbook browser profiles stay local without script errors');console.log(JSON.stringify({suite:'cashbook-interface-browser',checks,views,profiles:profiles.length,external_actions:external}));
}catch(error){console.error('Cashbook stage '+stage);for(const context of browser.contexts())for(const page of context.pages()){fs.writeFileSync('/workspace/kulturhus-gen44-browser-failure.html',await page.content(),{mode:0o600});await page.screenshot({path:'/workspace/kulturhus-gen44-browser-failure.png',fullPage:false}).catch(()=>{});console.error(JSON.stringify({url:page.url(),alerts:await page.locator('[role=alert],.error').allTextContents()}));}throw error;}finally{await browser.close();}
