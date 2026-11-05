import fs from 'node:fs';
import {createHash} from 'node:crypto';
const f=JSON.parse(fs.readFileSync(0,'utf8')),{chromium}=await import(process.env.PLAYWRIGHT_MODULE),browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE,headless:true});
let checks=0,views=0,external=0,posts=0,financialPosts=0,stage='start';const errors=[],results=[];
const check=(ok,label)=>{if(!ok)throw Error(stage+': '+label);checks++;};
const go=(p,url)=>p.goto(url,{waitUntil:'domcontentloaded'});
async function login(p,origin,role,locale){
 await go(p,origin+'/login');await p.locator('[name=email]').fill(f.accounts[role].email);await p.locator('[name=password]').fill(f.password);
 await Promise.all([p.waitForResponse(r=>r.request().method()==='POST'&&new URLSearchParams(r.request().postData()||'').get('operation')==='login'),p.getByRole('button',{name:/^(Anmelden und Vereinsmanager öffnen|Sign in and open club manager)$/}).click()]);
 await p.waitForFunction(()=>!document.querySelector('form [name=operation][value=login]')&&document.querySelector('main')&&!/^\/login\/?$/.test(location.pathname));
 await go(p,origin+'/account?section=language');const form=p.locator('form:has([name=operation][value=select-language])');await form.locator('[name=interface_locale]').selectOption(locale);
 await Promise.all([p.waitForNavigation({waitUntil:'domcontentloaded'}),form.getByRole('button').click()]);
}
async function readerHandoff(p,link,entry,origin){const target=new URL(await link.getAttribute('href'),origin);await go(p,target.href);const form=p.locator('[data-donation-form]');await form.waitFor();check(await form.locator('[name=source_id]').inputValue()===entry,'actual existing reader preview opens the exact original source');check(await form.locator('[name=action][value=donation-preview]').count()===1&&await form.locator('[name=action][value=donation-issue],[name=action][value=donation-save]').count()===0,'reader target supports preview while keeping save and issue unavailable');}
async function fit(p,scope){
 for(const width of[320,820])for(const size of[16,32]){
  await p.setViewportSize({width,height:1000});await p.evaluate(size=>document.documentElement.style.fontSize=size+'px',size);await p.evaluate(()=>document.fonts.ready);
  check(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'no horizontal page overflow '+width+'/'+size);
  check(await (await scope.locator('h2').count()?scope.locator('h2').first():scope).evaluate(n=>parseFloat(getComputedStyle(n).fontSize)>=parseFloat(getComputedStyle(document.documentElement).fontSize)),'heading follows enlarged text');
  for(const link of await scope.locator('a,button').all())check(await link.evaluate(n=>n.getBoundingClientRect().height>=43.5&&n.getBoundingClientRect().width>=43.5),'touch target remains at least 44px');views++;
 }
 await p.evaluate(()=>document.documentElement.style.fontSize='16px');await p.setViewportSize({width:390,height:1000});
}
try{
 let index=0;for(const profile of f.data.profiles.filter(p=>!process.env.DVM_RECEIPT_DOCUMENTS_CASE||p.tag===process.env.DVM_RECEIPT_DOCUMENTS_CASE)){
  index++;const origin=f.origins[profile.app],t=(de,en)=>profile.locale==='en'?en:de;console.log('Receipt documents '+profile.tag);
  for(const[role,offset]of[['admin',0],['reader',16],['foreign',32]]){
   stage=profile.tag+'/'+role;const context=await browser.newContext({javaScriptEnabled:profile.js,viewport:{width:390,height:1000},hasTouch:true,serviceWorkers:'block',extraHTTPHeaders:{'X-Owned-Finance-Help-Client':String(index+offset)}});
   await context.route('**/*',route=>new URL(route.request().url()).origin===origin?route.continue():(external++,route.abort()));context.on('request',r=>{if(r.method()==='POST'){posts++;if(new URL(r.url()).pathname.startsWith('/vereinsverwaltung/'))financialPosts++;}});
   const p=await context.newPage();p.on('pageerror',e=>errors.push(e.message));await login(p,origin,role,profile.locale);
   for(const[state,entry]of Object.entries(f.data.entries)){
    if(role==='foreign'&&!['issued','donation'].includes(state))continue;stage=profile.tag+'/'+role+'/'+state;
    const url=origin+'/vereinsverwaltung/?'+new URLSearchParams({view:'finance',tab:'receipts',entry});await go(p,url);
    if(role==='foreign'){check(await p.locator('[data-contribution-invoice],[data-contribution-payment],[data-receipt-donation]').count()===0,'foreign source cannot expose original finance/certificate cards');continue;}
    const card=p.locator(state==='donation'?'[data-receipt-donation]':'[data-contribution-invoice]');await card.waitFor();
    check((await p.locator('[data-finance-receipt-summary]').innerText()).includes(f.data.originals[state].counterparty),'original payer name including markup Unicode stays literal');
    if(state==='donation'){
     const link=card.locator('a');check(await link.innerText()===(role==='reader'?t('Zuwendungsformular und Bestätigungen ansehen','View donation form and certificates'):t('Zuwendungsbescheinigung erstellen / verwalten','Create / manage donation certificate')),'donation handoff uses translated role wording');
     check(new URL(await link.getAttribute('href'),origin).searchParams.get('source_id')===entry,'donation handoff retains original source');await fit(p,card);if(role==='reader')await readerHandoff(p,link,entry,origin);continue;
    }
    check(await card.locator('h2').innerText()===t('Jahresbeitragsrechnung','Annual contribution invoice'),'actual annual invoice heading translated');
    const payment=p.locator('[data-contribution-payment]');check(await payment.locator('h2').innerText()===t('Mitgliedsbeitrag · Zahlung und Bestätigung','Membership contribution · payment and certificate'),'actual allocated contribution status translated');
    check(await card.locator('form').count()===(role==='admin'&&!['issued','cancelled'].includes(state)?1:0),'only writer can create non-issued active annual invoice');
    if(state==='draft'){check((await card.innerText()).includes(t('Vereinsanschrift','Club address')),'actual missing fields translated');check(await card.locator('a').innerText()===t('Rechnungsentwurf öffnen','Open invoice draft'),'original incomplete PDF is clearly a draft');}
    if(state==='issued'){
     check(await card.locator('a').innerText()===t('Rechnungs-PDF öffnen','Open invoice PDF'),'verified issued original link available');
     const response=await context.request.get(origin+'/vereinsverwaltung/?'+new URLSearchParams({view:'documents',document_file:f.data.issued.document_id,preview:'1'}));
     check(response.status()===200&&createHash('sha256').update(await response.body()).digest('hex')===f.data.pdf_hash,'protected actual PDF download is byte-exact original for current role');
     await card.locator('a').focus();check(await card.locator('a').evaluate(n=>document.activeElement===n),'original PDF link keyboard focus available');
    }
    if(state==='partial')check((await payment.innerText()).includes(t('Teilzahlung:','Partial payment:'))&&(await payment.innerText()).includes('1,00 €'),'only actual partial payment amount shown');
    if(state==='certified')check((await payment.innerText()).includes(f.data.certificate)&&(await payment.innerText()).includes(t('Zur Unterschrift erstellt','Created for signature')),'genuine certificate visible without claiming signature');
    if(['partial','certified'].includes(state)){
     const handoff=payment.locator('a').last();check(new URL(await handoff.getAttribute('href'),origin).searchParams.get('source_id')===entry,'certificate handoff retains allocated annual source');
     if(role==='reader')check(await handoff.innerText()===t('Zuwendungsformular und Bestätigungen ansehen','View donation form and certificates'),'reader handoff accurately offers view');
    }
    if(state==='cancelled')check(await payment.locator('a').count()===0,'discarded contribution offers no new certificate');
    if(role==='reader')check(await p.locator('[data-finance-receipt] form[method=post]').count()===0,'reader offers no payment or issue mutation form');
    await fit(p,card);await fit(p,payment);
    if(role==='reader'&&state==='partial')await readerHandoff(p,payment.locator('a').last(),entry,origin);
    if(profile.tag==='front-js-en'&&role==='admin'&&state==='partial')await p.screenshot({path:'/workspace/kulturhus-gen76-receipt-mobile.png',fullPage:true});
   }
   await context.close();
  }
  results.push(profile.tag);
 }
 check(results.length>0&&external===0&&errors.length===0&&financialPosts===0,'actual UI and original downloads require no external requests scripts or financial POSTs');fs.writeFileSync(f.root+'/receipt-documents-results.json',JSON.stringify(results));console.log(JSON.stringify({suite:'receipt-documents-browser',checks,views,profiles:results.length,posts,financial_posts:financialPosts,external_requests:external,javascript_errors:errors}));
}catch(error){console.error('Receipt documents stage '+stage);for(const c of browser.contexts())for(const p of c.pages()){fs.writeFileSync('/workspace/kulturhus-gen76-browser-failure.html',await p.content(),{mode:0o600});await p.screenshot({path:'/workspace/kulturhus-gen76-browser-failure.png',fullPage:false}).catch(()=>{});}throw error;}finally{await browser.close();}
