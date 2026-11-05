import fs from 'node:fs';
const fixture=JSON.parse(fs.readFileSync(0,'utf8'));
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'/workspace/.setup/browser/node_modules/playwright/index.mjs');
let browser,checks=0,external=0;const errors=[];
const check=(value,label)=>{if(!value)throw Error(label);checks++;console.log('PASS '+label);};
const route=(origin,entry)=>origin+'/vereinsverwaltung/?'+new URLSearchParams({view:'finance',tab:'receipts',entry});
async function login(page,origin,role='finance'){
  await page.goto(origin+'/login',{waitUntil:'domcontentloaded'});
  await page.locator('[name=email]').fill(fixture.accounts[role].email);await page.locator('[name=password]').fill(fixture.password);
  await Promise.all([page.waitForURL(u=>u.pathname==='/vereinsverwaltung/',{waitUntil:'domcontentloaded'}),page.getByRole('button',{name:/^(Anmelden und Vereinsmanager öffnen|Sign in and open club manager)$/}).click()]);
}
try{
  browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE||'/usr/bin/chromium',headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
  for(const sample of fixture.data.payment_cases){
    const origin=fixture.origins[sample.dispatcher],label=sample.dispatcher+' '+sample.mode+' '+sample.kind+' js='+sample.js;
    const context=await browser.newContext({javaScriptEnabled:sample.js,viewport:{width:390,height:900}});
    await context.route('**/*',r=>Object.values(fixture.origins).includes(new URL(r.request().url()).origin)?r.continue():(external++,r.abort()));
    const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));await login(page,origin);
    let response=await page.goto(route(origin,sample.entry),{waitUntil:'domcontentloaded'});
    check(response.status()===200&&await page.locator('[data-transfer-payment-form]').count()===1,'approved invoice opens payment directly '+label);
    check(await page.locator('[data-transfer-payment-form] [name=payment_mode]').count()===2&&await page.locator('[data-transfer-payment-form] [name=operation], [data-transfer-payment-form] textarea, [data-transfer-payment-form] [type=checkbox]').count()===0,'only normal or instant choice without a reason or second approval '+label);
    check((await page.locator('[data-transfer-payment]').innerText()).includes(sample.recipient),'payment names the actual supplier or reimbursed member '+label);
    for(const width of[320,820,1440]){
      await page.setViewportSize({width,height:1000});
      for(const size of[100,200]){
        await page.evaluate(size=>document.documentElement.style.fontSize=size+'%',size);
        const fit=await page.locator('[data-transfer-payment]').evaluate(el=>({fits:el.scrollWidth<=el.clientWidth+1,targets:[...el.querySelectorAll('button,label,a.btn')].filter(e=>e.getClientRects().length).every(e=>e.getBoundingClientRect().height>=43.5)}));
        if(!fit.fits||!fit.targets){
          const diagnostic=await page.locator('[data-transfer-payment]').evaluate(el=>({width:el.clientWidth,scroll:el.scrollWidth,nodes:[...el.querySelectorAll('dt,dd,legend,label,span,button,a.btn,input')].filter(e=>e.getClientRects().length).map(e=>({tag:e.tagName,text:e.textContent.slice(0,90),width:e.getBoundingClientRect().width,height:e.getBoundingClientRect().height,scroll:e.scrollWidth,font:getComputedStyle(e).fontSize}))}));
          console.log(JSON.stringify({payment_fit_diagnostic:label,width,size,fit,diagnostic}));await page.locator('[data-transfer-payment]').screenshot({path:'/tmp/kulturhus-zahlung-layout-1856.png'});
        }
        check(fit.fits&&fit.targets,'payment fits with touch targets '+width+' '+size+' '+label);
      }
    }
    await page.evaluate(()=>document.documentElement.style.fontSize='');await page.setViewportSize({width:390,height:1000});await page.locator('[name=payment_mode][value='+sample.mode+']').check();
    const form=await page.locator('[data-transfer-payment-form]').evaluate(form=>Object.fromEntries(new FormData(form)));
    if(sample.index===0){
      check((await page.locator('[data-transfer-payment]').innerText()).includes('OWN-SUPPLIER-2026-0815'),'automatic purpose includes the original supplier invoice number');
      const invalid=await page.request.post(route(origin,sample.entry),{form:{...form,payment_mode:'0'},maxRedirects:0});check(invalid.status()===422,'actual controller rejects an unknown mode');
      const csrf=await page.request.post(route(origin,sample.entry),{form:{...form,csrf:'invalid'},maxRedirects:0});check(csrf.status()===403,'actual payment controller checks CSRF');
      const moved=await page.request.post(route(origin,fixture.data.payment_cases[1].entry),{form,maxRedirects:0});check(moved.status()===404,'actual controller binds the form to its invoice');
      await page.locator('[data-transfer-payment]').screenshot({path:'/tmp/kulturhus-zahlung-1856.png'});
    }
    const [submitted]=await Promise.all([page.waitForResponse(r=>r.request().method()==='POST'&&new URLSearchParams(r.request().postData()||'').get('action')==='transfer-payment'),page.waitForURL(u=>u.searchParams.get('payment')==='transfer',{waitUntil:'domcontentloaded'}),page.locator('[data-transfer-payment-form] button').click()]);
    const bank=new URL(page.url()),order=bank.searchParams.get('payment_order');check(submitted.status()===303&&bank.searchParams.get('fints')==='1'&&order?.length===32,'one click opens the exact FinTS order '+label);
    const repeated=await page.request.post(route(origin,sample.entry),{form,maxRedirects:0});check(repeated.status()===303&&new URL(repeated.headers().location,origin).searchParams.get('payment_order')===order,'repeated submission resumes the same bank order '+label);
    const opposite=await page.request.post(route(origin,sample.entry),{form:{...form,payment_mode:sample.mode==='standard'?'instant':'standard'},maxRedirects:0});check(opposite.status()===409,'other mode on an accepted form cannot create a second payment '+label);
    response=await page.goto(route(origin,sample.entry),{waitUntil:'domcontentloaded'});const resume=page.locator('[data-fints-payment-link]');
    check(response.status()===200&&await page.locator('[data-transfer-payment-form]').count()===0&&await resume.count()===1&&new URL(await resume.getAttribute('href'),origin).searchParams.get('payment_order')===order,'returning to the invoice resumes its current payment '+label);
    check((await page.locator('[data-transfer-payment]').innerText()).includes(sample.mode==='instant'?'Echtzeitüberweisung':'Normale Überweisung'),'invoice retains the selected transfer mode '+label);await context.close();
  }
  for(const sample of fixture.data.already_paid){
    const origin=fixture.origins.front,context=await browser.newContext({javaScriptEnabled:sample.kind==='cash'}),page=await context.newPage();
    await context.route('**/*',r=>Object.values(fixture.origins).includes(new URL(r.request().url()).origin)?r.continue():(external++,r.abort()));
    await login(page,origin);await page.goto(route(origin,sample.entry),{waitUntil:'domcontentloaded'});
    check(await page.locator('[data-transfer-payment-form]').count()===0&&await page.locator('[data-payment-already-paid]').count()===1,'already-paid action remains available when supplier IBAN is missing '+sample.kind);
    await Promise.all([page.waitForURL(u=>u.searchParams.get('manual_payment')==='1',{waitUntil:'domcontentloaded'}),page.locator('[data-payment-already-paid]').click()]);
    const form=page.locator('#manual-payment form:has([name=action][value=record-payment])');check(await form.count()===1,'already-paid action opens the account or cash payment form '+sample.kind);
    await form.locator('[name=account]').selectOption(sample.account);await form.locator('[name=amount]').fill('125.50');await form.locator('[name=paid_on]').fill(fixture.data.today);
    const [saved]=await Promise.all([page.waitForResponse(r=>r.request().method()==='POST'&&new URLSearchParams(r.request().postData()||'').get('action')==='record-payment'),page.waitForEvent('domcontentloaded'),form.getByRole('button').click()]);
    check(saved.status()===303&&await page.locator('[data-payment-already-paid]').count()===0&&await page.locator('[data-transfer-payment-form]').count()===0,'past '+sample.kind+' payment is saved and assigned in the actual manager');await context.close();
  }
  const origin=fixture.origins.front,context=await browser.newContext(),page=await context.newPage();await login(page,origin,'writer');await page.goto(route(origin,fixture.data.payment_cases[0].entry),{waitUntil:'domcontentloaded'});
  check(await page.locator('[data-transfer-payment-form], [data-fints-payment-link]').count()===0,'finance writer without payment office cannot initiate or resume payments');await context.close();
  check(external===0&&errors.length===0,'payment browser has no external requests or client errors');console.log(JSON.stringify({suite:'transfer-payment-browser',checks,cases:fixture.data.payment_cases.length,external_actions:external}));
}catch(error){console.error(JSON.stringify({suite:'transfer-payment-browser',checks,error:String(error),client_errors:errors.slice(0,5)}));process.exitCode=1;}finally{await browser?.close();}
