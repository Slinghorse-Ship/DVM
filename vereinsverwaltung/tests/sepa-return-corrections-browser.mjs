import fs from 'node:fs';
import crypto from 'node:crypto';
const fixture=JSON.parse(fs.readFileSync(0,'utf8'));
const {chromium}=await import('/workspace/.setup/browser/node_modules/playwright/index.mjs');
const browser=await chromium.launch({executablePath:'/usr/bin/chromium',headless:true});
let checks=0,views=0,external=0,activeJs=true,stage='start';const errors=[];
const hash=bytes=>crypto.createHash('sha256').update(bytes).digest('hex');
const check=(ok,label)=>{if(!ok)throw Error(label);checks++;};
const go=(page,url)=>page.goto(url,{waitUntil:'domcontentloaded'});
const bank=(origin,query={})=>origin+'/vereinsverwaltung/?'+new URLSearchParams({view:'finance',tab:'debit',...query});
async function language(page,origin,locale){
  await go(page,origin+'/account?section=language');const form=page.locator('form:has([name=operation][value=select-language])');
  await form.locator('[name=interface_locale]').selectOption(locale);
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),form.getByRole('button').click()]);
}
async function login(page,origin,role,locale){
  await go(page,origin+'/login');await page.locator('[name=email]').fill(fixture.accounts[role].email);await page.locator('[name=password]').fill(fixture.password);
  const response=page.waitForResponse(value=>value.request().method()==='POST'&&new URLSearchParams(value.request().postData()||'').get('operation')==='login');
  await Promise.all([response,page.getByRole('button',{name:/^(Anmelden und Vereinsmanager öffnen|Sign in and open club manager)$/}).click()]);
  // A signed-in member portal may update its account-result fragment without
  // another DOMContentLoaded. Wait for the actual authenticated page instead.
  await page.waitForFunction(()=>!document.querySelector('form [name=operation][value=login]')&&document.querySelector('main')&&!/^\/login\/?$/.test(location.pathname));
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
    stage=profile.name;activeJs=profile.js;console.log('Actual native correction '+stage);
    const origin=fixture.origins[profile.app],context=await browser.newContext({javaScriptEnabled:profile.js,viewport:{width:390,height:1000},hasTouch:true,serviceWorkers:'block',extraHTTPHeaders:{'X-Owned-Sepa-Client':String(profile.index+1)}});
    await context.route('**/*',route=>new URL(route.request().url()).origin===origin?route.continue():(external++,route.abort()));const page=await context.newPage();page.on('pageerror',error=>errors.push(error.message));await login(page,origin,'finance',profile.locale);
    const firsts=[];
    async function correction(source,operation){
      stage=profile.name+' '+source.kind+' '+operation;
      await go(page,bank(origin,{sepa_order:source.order,sepa_new:'1'}));await page.locator('[name=operation]').selectOption(operation);
      await page.locator('[name="data[reason]"]').fill('\nOwn deliberate full correction ä🙂\n0 <b>literal</b>\r\n');await page.locator('[name="data[evidence]"]').fill('\nOwn actual synthetic bank evidence ä🙂\n0\r\n');
      const details=page.locator('details:has([name="data[item]"])');if(!await details.evaluate(element=>element.open))await details.locator('summary').press('Enter');await post(page,'sepa','selection');await page.locator('[name="data[item]"]').selectOption(source.item);await post(page,'sepa','selection');
      check(await page.locator('[data-sepa-return-correction]').count()===1&&(await page.locator('[data-sepa-return-correction]').innerText()).includes(profile.locale==='en'?'Review the documented returned debit together':'Dokumentierte Rücklastschrift gemeinsam prüfen'),'actual correction identifies its fixed original return in the selected language');
      if(operation==='return_attach')for(const id of source.allocations)await page.locator('[name="sepa_return_allocations[]"][value="'+id+'"]').check();
      if(operation==='return_existing')for(const id of source.financial_ids)await page.locator('[name="sepa_return_financials[]"][value="'+id+'"]').check();
      if(operation==='return_existing'){
        await page.locator('[name=sepa_return_financial_page]').fill('9999999');await post(page,'sepa','selection');check(await page.locator('input[type=hidden][name="sepa_return_financials[]"]').count()===source.financial_ids.length,'nonexistent financial original page preserves the full explicit selection without choosing a last-page substitute');
        await page.locator('[name=sepa_return_financial_page]').fill('1');await post(page,'sepa','selection');check(await page.locator('[name="sepa_return_financials[]"]:checked').count()===source.financial_ids.length,'returning to the actual financial original page restores all exact selected choices');
        await page.locator('[name=operation]').selectOption('return_undo');await post(page,'sepa','selection');check(await page.locator('input[type=hidden][name="sepa_return_financials[]"]').count()===source.financial_ids.length&&await page.locator('[name=sepa_intent][value=clear-financials]').count()===1,'changing action preserves all earlier financial choices and offers their explicit clearing');
        await post(page,'sepa','preview');check(await page.locator('[data-sepa-review]').count()===0&&await page.locator('input[type=hidden][name="sepa_return_financials[]"]').count()===source.financial_ids.length,'incompatible correction action retains the complete rejected original financial selection');
        await page.locator('[name=operation]').selectOption('return_existing');await post(page,'sepa','selection');check(await page.locator('[name="sepa_return_financials[]"]:checked').count()===source.financial_ids.length,'the original correction action resumes its exact original financial choices');
      }
      await post(page,'sepa','preview');check(await page.locator('[data-sepa-correction-review]').count()===1&&!await page.locator('[name=sepa_approved]').isChecked(),'actual joint correction reviews its current financial outcome with fresh separate consent');
      check(await page.locator('[data-sepa-correction-source]').count()===1&&(await page.locator('[data-sepa-correction-source]').innerText()).includes(profile.locale==='en'?'Originally recorded returned debit reason':'Dokumentierter Rücklastschriftgrund'),'actual correction review identifies the exact earlier recorded reason and real counter source in the selected language');
      check((await page.locator('[data-sepa-correction-review]').innerText()).includes(profile.locale==='en'?'Reviewed joint returned debit correction':'Gemeinsam geprüfte Rücklastschriftänderung'),'actual correction outcome is translated');
      await fit(page,stage+' '+source.kind+' '+operation,'.general-settings-native button,.general-settings-native .btn,.general-settings-check');
      await post(page,'sepa','accept');check(await page.locator('[data-sepa-review]').count()===1&&await page.locator('[data-sepa-receipt]').count()===0,'missing correction consent preserves the complete reviewed input');
      await page.locator('[name=sepa_approved]').check();const request=await post(page,'sepa','accept'),first=await personal(page,context,'[data-sepa-original]');firsts.push(first);
      const actual=first.first.result.correction,expectedPaid=operation==='return_undo'?10000:0,expectedCounter=operation==='return_undo'?(source.kind==='partial'?4000:10000):0;
      check(actual.entry.amount_cents===10000&&actual.entry.allocated_cents===expectedPaid&&actual.entry.remaining_cents===10000-expectedPaid&&actual.transaction.remaining_cents===expectedCounter,'actual correction first captures the real after-payment invoice position and actual counter availability');
      check(await page.locator('[data-sepa-correction-result]').count()===1&&(await page.locator('[data-sepa-correction-result]').innerText()).includes(source.number)&&(await page.locator('[data-sepa-correction-result]').innerText()).includes(profile.locale==='en'?'Counter movement still to allocate at that time':'Damals noch zuzuordnender Rückumsatz'),'actual correction receipt displays its separately saved financial outcome in the selected language');
      check(first.first.input.operation===operation&&first.first.input.data.reason===new URLSearchParams(request.postData()).get('data[reason]')&&first.first.input.data.evidence===new URLSearchParams(request.postData()).get('data[evidence]')&&first.first.input.data.reason.replaceAll('\r\n','\n')==='\nOwn deliberate full correction ä🙂\n0 <b>literal</b>\n'&&first.first.review.no_transport&&first.first.review.no_new_money,'actual accepted correction retains full literal multiline input and creates no transport or money');
      await fit(page,stage+' saved correction outcome','.general-settings-native button,.general-settings-native .btn,.general-settings-check');
      const replay=await context.request.post(request.url(),{headers:{Origin:origin,'User-Agent':await page.evaluate(()=>navigator.userAgent),'Content-Type':'application/x-www-form-urlencoded'},data:request.postData()});check([200,303].includes(replay.status())&&hash(await(await context.request.get(first.url)).body())===first.sha,'exact correction POST replay retains the same complete private first');
      return first;
    }
    for(const source of fixture.data.cases.filter(value=>value.profile_index===profile.index)){
      const xmlUrl=bank(origin,{sepa_order:source.order,sepa_order_xml:source.order}),xml=await context.request.get(xmlUrl);check(xml.status()===200,'actual original bank XML is available before correction');const xmlSha=hash(await xml.body());
      if(source.kind==='attach')await correction(source,'return_attach');
      if(source.kind==='existing')await correction(source,'return_existing');
      await correction(source,'return_undo');
      check(await page.locator('[data-sepa-return-undone]').count()===1,'actual current native item explains its conscious joint undo');
      if(source.kind==='partial'){
        await go(page,bank(origin,{sepa_order:source.order,sepa_new:'1'}));await page.locator('[name=operation]').selectOption('return');const details=page.locator('details:has([name="data[item]"])');if(!await details.evaluate(element=>element.open))await details.locator('summary').press('Enter');await post(page,'sepa','selection');await page.locator('[name="data[item]"]').selectOption(source.item);await page.locator('[name="data[transaction]"]').selectOption(source.counter);await page.locator('[name="data[reason]"]').fill('Own deliberate reclassification of actual forty');await post(page,'sepa','selection');await page.locator('[name="sepa_return_allocations[]"][value="'+source.allocation+'"]').check();await post(page,'sepa','preview');await page.locator('[name=sepa_approved]').check();await post(page,'sepa','accept');const returned=await personal(page,context,'[data-sepa-original]');firsts.push(returned);
        check(returned.first.result.return.entry.allocated_cents===6000&&returned.first.result.return.entry.remaining_cents===4000&&returned.first.result.return.transaction.remaining_cents===0,'actual later forty classification again opens only forty alongside the original sixty');
      }
      check(hash(await(await context.request.get(xmlUrl)).body())===xmlSha,'all actual corrections preserve the exact original bank XML');
      await go(page,origin+'/vereinsverwaltung/?'+new URLSearchParams({view:'finance',tab:'receipts',entry:source.entry}));await fit(page,stage+' '+source.kind+' current receipt','.allocation-history-row .btn');
    }
    for(const first of firsts)check(hash(await(await context.request.get(first.url)).body())===first.sha,'later native changes preserve every complete earlier private correction original');
    await language(page,origin,'de');await context.close();
    for(const role of['reader','foreign']){
      const other=await browser.newContext({javaScriptEnabled:profile.js,viewport:{width:390,height:1000},serviceWorkers:'block',extraHTTPHeaders:{'X-Owned-Sepa-Client':String(20+profile.index)}});await other.route('**/*',route=>new URL(route.request().url()).origin===origin?route.continue():(external++,route.abort()));const otherPage=await other.newPage();otherPage.on('pageerror',error=>errors.push(error.message));await login(otherPage,origin,role,profile.locale);
      for(const first of firsts)check([403,404].includes((await other.request.get(first.url)).status()),'another '+role+' cannot inherit personal joint correction firsts');await language(otherPage,origin,'de');await other.close();
    }
  }
  check(external===0&&errors.length===0,'actual joint correction browser stays local without script errors');console.log(JSON.stringify({suite:'sepa-return-corrections-browser',checks,views,profiles:fixture.data.profiles.length,external_actions:0}));
}catch(error){
  console.error(JSON.stringify({stage,error:error.message}));for(const context of browser.contexts())for(const page of context.pages()){fs.writeFileSync('/workspace/kulturhus-sepa-correction-failure.html',await page.content(),{mode:0o600});await page.screenshot({path:'/workspace/kulturhus-sepa-correction-failure.png',fullPage:true}).catch(()=>{});console.error(JSON.stringify({url:page.url(),alerts:await page.locator('[role=alert],.error').allTextContents()}));}throw error;
}finally{await browser.close();}
