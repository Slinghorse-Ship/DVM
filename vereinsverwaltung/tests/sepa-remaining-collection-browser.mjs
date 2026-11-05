import fs from 'node:fs';
import crypto from 'node:crypto';
const fixture=JSON.parse(fs.readFileSync(0,'utf8'));
const {chromium}=await import('/workspace/.setup/browser/node_modules/playwright/index.mjs');
const browser=await chromium.launch({executablePath:'/usr/bin/chromium',headless:true});
let checks=0,views=0,external=0,activeJs=true,stage='start';const errors=[];
const hash=bytes=>crypto.createHash('sha256').update(bytes).digest('hex');
const check=(ok,label)=>{if(!ok)throw Error(label);checks++;};
const go=(page,url)=>page.goto(url,{waitUntil:'domcontentloaded'});
const bank=(origin,query={})=>origin+'/vereinsverwaltung/?'+new URLSearchParams({view:'finance',tab:'debit', sepa_manual:'1',...query});
async function language(page,origin,locale){
  await go(page,origin+'/account?section=language');const form=page.locator('form:has([name=operation][value=select-language])');
  await form.locator('[name=interface_locale]').selectOption(locale);
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),form.getByRole('button').click()]);
}
async function login(page,origin,role,locale){
  await go(page,origin+'/login');await page.locator('[name=email]').fill(fixture.accounts[role].email);await page.locator('[name=password]').fill(fixture.password);
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.getByRole('button',{name:/^(Anmelden und Vereinsmanager öffnen|Sign in and open club manager)$/}).click()]);
  await language(page,origin,locale);
}
async function post(page,kind,intent){
  const sepa=kind==='sepa',form=page.locator(sepa?'[data-sepa-form]':'[data-debit-form]'),nonce=crypto.randomBytes(12).toString('hex');
  await form.evaluate((element,value)=>element.dataset.ownRemainingSubmit=value,nonce);
  const action=sepa?'sepa-native':'debit-notice-native',matches=request=>request.method()==='POST'&&new URLSearchParams(request.postData()||'').get('action')===action;
  const requested=page.waitForRequest(matches),responded=page.waitForResponse(response=>matches(response.request()));
  const loaded=activeJs?Promise.resolve():page.waitForNavigation({waitUntil:'domcontentloaded'});
  const [request]=await Promise.all([requested,responded,loaded,form.locator('[name='+kind+'_intent][value='+intent+']').first().press('Enter')]);
  if(activeJs)await page.waitForFunction(({nonce,sepa})=>!document.querySelector('[data-own-remaining-submit="'+nonce+'"]')&&document.querySelector(sepa?'[data-sepa-history] form':'[data-debit-history]'),{nonce,sepa},{polling:100});
  console.log(stage+' '+kind+' '+intent+' applied');return request;
}
async function fit(page,label,controls){
  for(const width of[320,390,820])for(const size of[16,32]){
    await page.setViewportSize({width,height:1000});await page.evaluate(value=>document.documentElement.style.fontSize=value+'px',size);
    check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),label+' fits '+width+'/'+size);
    check(await page.locator(controls).evaluateAll(elements=>elements.filter(element=>element.getClientRects().length).every(element=>element.getBoundingClientRect().height>=43.5)),label+' retains44px targets');views++;
  }
  await page.evaluate(()=>document.documentElement.style.fontSize='16px');await page.setViewportSize({width:390,height:1000});
}
async function personal(page,context,selector){
  const url=new URL(await page.locator(selector).getAttribute('href'),page.url()).href,response=await context.request.get(url),bytes=await response.body();
  check(response.status()===200,'actual private first downloads');const first=JSON.parse(bytes),head=await context.request.head(url),bad=await context.request.post(url,{form:{action:'create-account'}});
  check(head.status()===200&&Number(head.headers()['content-length'])===bytes.length&&(await head.body()).length===0&&bad.status()===405,'actual private first supports exact HEAD and refuses POST');
  return {url,first,sha:hash(bytes)};
}
try{
  for(const profile of fixture.data.profiles){
    stage=profile.name;activeJs=profile.js;console.log('Actual remaining collection '+stage);
    const source=fixture.data.cases.find(value=>value.profile_index===profile.index),origin=fixture.origins[profile.app];
    const context=await browser.newContext({javaScriptEnabled:profile.js,viewport:{width:390,height:1000},hasTouch:true,serviceWorkers:'block',extraHTTPHeaders:{'X-Owned-Sepa-Client':String(profile.index+1)}});
    await context.route('**/*',route=>new URL(route.request().url()).origin===origin?route.continue():(external++,route.abort()));
    const page=await context.newPage();page.on('pageerror',error=>errors.push(error.message));await login(page,origin,'finance',profile.locale);
    await go(page,origin+'/vereinsverwaltung/?'+new URLSearchParams({view:'documents',doc:source.document}));
    check(await page.locator('[data-debit-notice-open]').count()===1,'actual partially paid issued invoice exposes its integrated notice entry');
    await page.locator('[data-debit-notice-open]').click();await page.locator('[data-debit-form]').waitFor();
    check(await page.locator('[data-debit-funding]').count()===1&&(await page.locator('[data-debit-funding]').innerText()).includes('100,00')&&(await page.locator('[data-debit-source]').innerText()).includes(profile.locale==='en'?'Original invoice amount':'Ursprünglicher Rechnungsbetrag'),'actual remaining notice identifies original100 separately from its forty collection');
    const invoiceUrl=new URL(await page.locator('[data-debit-claim]').getAttribute('href'),origin).href;
    check(hash(await(await context.request.get(invoiceUrl)).body())===source.invoice_sha256,'actual notice opens the same issued100 original PDF');
    await page.locator('[name=day]').fill(fixture.data.contract.notice);await page.locator('[name=collection]').fill(fixture.data.contract.date);
    await page.locator('[data-debit-form] [name=reason]').fill('\nOwn actual remaining40 notice ä🙂\r\n');
    await post(page,'debit','preview');check(await page.locator('[data-debit-review]').count()===1&&!await page.locator('[name=debit_approved]').isChecked(),'actual remaining notice review has its own empty consent');
    await fit(page,stage+' notice40','[data-debit-notice-native] button,[data-debit-notice-native] .btn,[data-debit-notice-native] .general-settings-check');
    await post(page,'debit','accept');check(await page.locator('[data-debit-first]').count()===0&&await page.locator('[data-debit-review]').count()===1,'missing notice consent preserves its reviewed forty without an output');
    await page.locator('[name=debit_approved]').check();await post(page,'debit','accept');
    const notice=await personal(page,context,'[data-debit-original]');
    check(notice.first.review.source.claim.amount_cents===4000&&notice.first.review.source.claim.funding.face_cents===10000&&notice.first.review.source.claim.funding.paid_cents===6000&&notice.first.review.source.values.ZAHLBETRAG==='40,00 €','actual personal notice binds original100 paid60 and announced40');
    const noticePdfUrl=new URL(await page.locator('[data-debit-pdf]').getAttribute('href'),origin).href;
    check(hash(await(await context.request.get(noticePdfUrl)).body())===notice.first.result.sha256,'actual canonical notice40 PDF matches its accepted original');

    await go(page,bank(origin,{sepa_new:'1'}));await page.locator('[data-sepa-form]').waitFor();
    await page.locator('[name="data[account]"]').selectOption(fixture.data.contract.account);
    await page.locator('[name="data[collection_date]"]').fill(fixture.data.contract.date);await page.locator('[name="data[notice_date]"]').fill(fixture.data.contract.notice);
    await page.locator('[name="data[reason]"]').fill('\nOwn actual remaining40 collection ä🙂\r\n');await page.locator('[name="data[evidence]"]').fill('Own timely communicated synthetic notice40; no external mail or bank request');
    await page.locator('[name=sepa_candidate_q]').fill(source.number);await page.locator('[name=sepa_candidate_page]').fill('1');await post(page,'sepa','selection');
    const candidate=page.locator('[data-sepa-candidate="'+source.entry+'"]');
    check(await candidate.locator('input[type=checkbox]').isEnabled()&&await candidate.locator('[data-sepa-candidate-funding]').count()===1&&(await candidate.innerText()).includes('40,00')&&(await candidate.innerText()).includes(profile.locale==='en'?'Still to collect':'Noch einzuziehen'),'actual candidate permits only the remaining40 while explaining original100 and prior60');
    await candidate.locator('input[type=checkbox]').check();await post(page,'sepa','preview');
    check(await page.locator('[data-sepa-collection-funding]').count()===1&&(await page.locator('[data-sepa-collection-funding]').innerText()).includes('60,00')&&(await page.locator('[data-sepa-collection-funding]').innerText()).includes(profile.locale==='en'?'Already paid':'Bereits bezahlt')&&!await page.locator('[name=sepa_approved]').isChecked(),'actual partial bank preview shows its fixed earlier sixty and separate remaining forty');
    await fit(page,stage+' native40 preview','.general-settings-native button,.general-settings-native .btn,.general-settings-check');
    await post(page,'sepa','accept');check(await page.locator('[data-sepa-receipt]').count()===0&&await page.locator('[data-sepa-review]').count()===1,'missing bank consent creates no partial collection order');
    await page.locator('[name=sepa_approved]').check();const preparationRequest=await post(page,'sepa','accept');
    const prepared=await personal(page,context,'[data-sepa-original]'),order=prepared.first.result.order.id;
    check(prepared.first.result.order.total_cents===4000&&prepared.first.review.source.preview.source.items[0].funding.paid_cents===6000&&!JSON.stringify(prepared.first).includes('DE89370400440532013000')&&!JSON.stringify(prepared.first).includes('DE12500105170648489890'),'actual prepared native40 first binds prior60 and automatically masks both full bank numbers');
    const replay=await context.request.post(preparationRequest.url(),{headers:{Origin:origin,'User-Agent':await page.evaluate(()=>navigator.userAgent),'Content-Type':'application/x-www-form-urlencoded'},data:preparationRequest.postData()});
    check([200,303].includes(replay.status())&&hash(await(await context.request.get(prepared.url)).body())===prepared.sha,'exact original partial preparation HTTP replay returns its unchanged first');
    let exported;
    for(const operation of['approve','export','submitted']){
      await go(page,bank(origin,{sepa_order:order,sepa_new:'1'}));await page.locator('[name=operation]').selectOption(operation);await page.locator('[name="data[reason]"]').fill('Own deliberate remaining40 '+operation);
      if(operation==='submitted'){
        const details=page.locator('details:has([name="data[bank_reference]"])');if(!await details.evaluate(element=>element.open))await details.locator('summary').press('Enter');
        await page.locator('[name="data[bank_reference]"]').fill('OWN-REMAINING40-'+profile.index);await page.locator('[name="data[evidence]"]').fill('Own actual synthetic submission evidence; no external bank request');
      }
      await post(page,'sepa','preview');check(await page.locator('[data-sepa-review] [data-sepa-collection-funding]').count()===1&&!await page.locator('[name=sepa_approved]').isChecked(),'actual '+operation+' rechecks original partial funding with fresh separate consent');
      await page.locator('[name=sepa_approved]').check();await post(page,'sepa','accept');const decision=await personal(page,context,'[data-sepa-original]');
      check(decision.first.input.operation===operation&&decision.first.result.order.id===order,'actual '+operation+' retains the same real forty bank order');
      if(operation==='export'){
        const url=new URL(await page.locator('[data-sepa-xml]').getAttribute('href'),origin).href,file=await context.request.get(url),bytes=await file.body();
        check(file.status()===200&&hash(bytes)===decision.first.result.xml_sha256&&bytes.toString().includes('>40.00<'),'actual bank download is the exact approved forty XML');exported={url,sha:hash(bytes)};
      }
    }
    const movementResponse=await context.request.post(origin+'/owned-sepa-remaining-fixture',{form:{nonce:fixture.data.nonce,case:String(profile.index),operation:'actual-money',order}});
    check(movementResponse.status()===200,'owned actual money is created only after the real browser-submitted forty order');const money=await movementResponse.json();
    await go(page,bank(origin,{sepa_order:order,sepa_new:'1'}));await page.locator('[name=operation]').selectOption('return');
    const details=page.locator('details:has([name="data[item]"])');if(!await details.evaluate(element=>element.open))await details.locator('summary').press('Enter');
    await page.locator('[name="data[item]"]').selectOption(money.item);await page.locator('[name="data[transaction]"]').selectOption(money.counter);await page.locator('[name="data[reason]"]').fill('\nOwn actual returned partial40 ä🙂\r\n');
    await post(page,'sepa','selection');await page.locator('[name="sepa_return_allocations[]"][value="'+money.allocation+'"]').check();await post(page,'sepa','preview');
    check(await page.locator('[data-sepa-return-financial-review]').count()===1&&(await page.locator('[data-sepa-return-financial-review]').innerText()).includes('60,00')&&(await page.locator('[data-sepa-return-financial-review]').innerText()).includes('40,00'),'actual partial return review preserves paid60 and reopens only its forty');
    await page.locator('[name=sepa_approved]').check();await post(page,'sepa','accept');const returned=await personal(page,context,'[data-sepa-original]');
    check(returned.first.result.return.entry.allocated_cents===6000&&returned.first.result.return.entry.remaining_cents===4000&&returned.first.result.return.transaction.remaining_cents===0,'actual native partial return stores paid60 open40 and fully classified negative40 together');
    await fit(page,stage+' actual returned40','.general-settings-native button,.general-settings-native .btn,.general-settings-check');
    await go(page,origin+'/vereinsverwaltung/?'+new URLSearchParams({view:'finance',tab:'receipts',entry:source.entry}));
    check(await page.locator('[data-allocation-funding]').count()===1&&await page.locator('[data-allocation-refund]').count()===1,'actual receipt explains only the returned forty alongside the separate original sixty');
    await fit(page,stage+' original100 receipt','.allocation-history-row .btn');
    check(hash(await(await context.request.get(notice.url)).body())===notice.sha&&hash(await(await context.request.get(noticePdfUrl)).body())===notice.first.result.sha256&&hash(await(await context.request.get(invoiceUrl)).body())===source.invoice_sha256&&hash(await(await context.request.get(exported.url)).body())===exported.sha,'later actual returned40 preserves the whole earlier notice first notice PDF original100 PDF and exported40 XML');
    await language(page,origin,'de');await context.close();
    for(const role of['reader','foreign']){
      const other=await browser.newContext({javaScriptEnabled:profile.js,viewport:{width:390,height:1000},serviceWorkers:'block',extraHTTPHeaders:{'X-Owned-Sepa-Client':String(20+profile.index)}});
      await other.route('**/*',route=>new URL(route.request().url()).origin===origin?route.continue():(external++,route.abort()));const otherPage=await other.newPage();otherPage.on('pageerror',error=>errors.push(error.message));await login(otherPage,origin,role,profile.locale);
      for(const url of[notice.url,prepared.url,returned.url])check([403,404].includes((await other.request.get(url)).status()),'another '+role+' cannot inherit personal remaining collection or notice originals');
      await go(otherPage,origin+'/vereinsverwaltung/?'+new URLSearchParams({view:'finance',tab:'receipts',entry:source.entry}));check(await otherPage.locator('[data-allocation-funding]').count()===(role==='reader'?1:0),'actual '+role+' sees only its authorized returned forty financial history');await language(otherPage,origin,'de');await other.close();
    }
  }
  check(external===0&&errors.length===0,'actual remaining collection browser stays local without script errors');console.log(JSON.stringify({suite:'sepa-remaining-collection-browser',checks,views,profiles:fixture.data.profiles.length,external_actions:0}));
}catch(error){
  console.error(JSON.stringify({stage,error:error.message}));
  for(const context of browser.contexts())for(const page of context.pages()){fs.writeFileSync('/workspace/kulturhus-sepa-remaining-failure.html',await page.content(),{mode:0o600});await page.screenshot({path:'/workspace/kulturhus-sepa-remaining-failure.png',fullPage:true}).catch(()=>{});console.error(JSON.stringify({url:page.url(),alerts:await page.locator('[role=alert],.error').allTextContents()}));}
  throw error;
}finally{await browser.close();}
