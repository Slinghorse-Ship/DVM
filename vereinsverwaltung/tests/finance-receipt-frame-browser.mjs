import fs from 'node:fs';
const fixture=JSON.parse(fs.readFileSync(0,'utf8'));
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE);
const browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE,headless:true});
let checks=0,views=0,external=0,stage='start';const errors=[],results=[];
const check=(ok,label)=>{if(!ok)throw Error(label);checks++;};
const go=(p,url)=>p.goto(url,{waitUntil:'domcontentloaded'});
async function login(p,origin,role,locale){
 await go(p,origin+'/login');await p.locator('[name=email]').fill(fixture.accounts[role].email);await p.locator('[name=password]').fill(fixture.password);
 await Promise.all([p.waitForResponse(r=>r.request().method()==='POST'&&new URLSearchParams(r.request().postData()||'').get('operation')==='login'),p.getByRole('button',{name:/^(Anmelden und Vereinsmanager öffnen|Sign in and open club manager)$/}).click()]);
 await p.waitForFunction(()=>!document.querySelector('form [name=operation][value=login]')&&document.querySelector('main')&&!/^\/login\/?$/.test(location.pathname));
 await go(p,origin+'/account?section=language');const form=p.locator('form:has([name=operation][value=select-language])');await form.locator('[name=interface_locale]').selectOption(locale);
 await Promise.all([p.waitForNavigation({waitUntil:'domcontentloaded'}),form.getByRole('button').click()]);
}
async function fit(p,label){
 for(const width of[320,390,820])for(const size of[16,32]){
  await p.setViewportSize({width,height:1000});await p.evaluate(size=>document.documentElement.style.fontSize=size+'px',size);
  await p.waitForFunction(()=>document.documentElement.scrollWidth<=innerWidth+1,null,{timeout:1200}).catch(()=>{});
  if(await p.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1))console.error(await p.evaluate(()=>[...document.querySelectorAll('body *')].filter(n=>n.getBoundingClientRect().right>innerWidth+1).slice(0,8).map(n=>({tag:n.tagName,cls:n.className,right:n.getBoundingClientRect().right}))));
  check(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),label+' fits '+width+'/'+size);
  check(await p.locator('#receipt-overview a').evaluate(n=>n.getBoundingClientRect().height>=43.5&&n.getBoundingClientRect().width>=43.5),label+' accessible list return keeps 44px touch target');
  check(await p.locator('[data-finance-receipt-summary] dt').first().evaluate(n=>parseFloat(getComputedStyle(n).fontSize)>=parseFloat(getComputedStyle(document.documentElement).fontSize)),label+' metadata follows enlarged text');views++;
 }
 await p.evaluate(()=>document.documentElement.style.fontSize='16px');await p.setViewportSize({width:390,height:1000});
}
try{
 let index=0;const profiles=fixture.data.profiles.filter(p=>!process.env.DVM_FINANCE_RECEIPT_CASE||p.tag===process.env.DVM_FINANCE_RECEIPT_CASE);check(profiles.length>0,'selected complete receipt profile exists');
 for(const profile of profiles){index++;stage=profile.tag;console.log('Finance receipt frame '+stage);const origin=fixture.origins[profile.app],en=profile.locale==='en',t=(de,eng)=>en?eng:de;
  for(const[role,offset]of[['admin',0],['reader',16],['foreign',32],['general',48]]){
   stage=profile.tag+'/'+role;const context=await browser.newContext({javaScriptEnabled:profile.js,viewport:{width:390,height:1000},hasTouch:true,serviceWorkers:'block',extraHTTPHeaders:{'X-Owned-Finance-Help-Client':String(index+offset)}});
   await context.route('**/*',r=>new URL(r.request().url()).origin===origin?r.continue():(external++,r.abort()));const p=await context.newPage();p.on('pageerror',e=>errors.push(e.message));await login(p,origin,role,profile.locale);
   for(const[kind,entry]of Object.entries(fixture.data.entries)){
    const query={view:'finance',tab:'receipts',entry,q:'0 Original list <&> ä🙂',status:'open',page:'3'};await go(p,origin+'/vereinsverwaltung/?'+new URLSearchParams(query));
    if(role==='foreign'||role==='general'){check(await p.locator('[data-finance-receipt-summary]').count()===0,'actual '+role+' cannot see protected receipt summary');check(!(await p.locator('main').innerText()).includes(fixture.data.descriptions.expense),'actual '+role+' cannot read original invoice purpose');continue;}
    const summary=p.locator('[data-finance-receipt-summary]');await summary.waitFor();
    check(await summary.locator('h2').first().innerText()===(kind==='expense'?t('Rechnung','Invoice'):fixture.data.descriptions[kind]),'actual '+kind+' summary localises only fixed type and retains literal business purpose');
    check(await summary.locator('dd').first().innerText()==='0','actual original zero counterparty stays visible');
    check((await summary.locator('dt').allTextContents()).slice(0,3).join('|')===t('Empfänger|Rechnungsdatum|Fälligkeit','Recipient|Invoice date|Due date'),'actual metadata uses personal language');
    check((await summary.locator('dd').allTextContents()).slice(1,3).join('|')==='24.10.2026|'+(kind==='expense'?t('Nicht angegeben','Not specified'):'25.10.2026'),'actual original dates and explicit missing due date remain clear');
    const heading=p.locator('#receipt-overview');check((await heading.locator('h1').innerText()).startsWith(t('Beleg ','Receipt '))&&await heading.locator('.status').innerText()===t('Offen','Open'),'actual receipt heading and fixed payment state use personal language');
    const back=heading.getByRole('link',{name:t('Zurück zur Liste','Back to list'),exact:true});const target=new URL(await back.getAttribute('href'),origin);check(['q','page','status'].every(key=>target.searchParams.get(key)===query[key])&&!target.searchParams.has('entry'),'actual accessible list return retains full original filter page and status');
    if(role==='reader'){check(await p.locator('[data-finance-readonly]').innerText()===t('Lesezugriff: Änderungen sind für diese Rolle gesperrt.','Read-only access: changes are disabled for this role.'),'actual read-only notice follows personal language');check(await p.locator('[data-finance-receipt] form[method=post]').count()===0,'actual reader receipt offers no mutation forms');}
    await fit(p,stage+'/'+kind);
    if(profile.tag==='front-js-en'&&role==='admin'&&kind==='income')await p.screenshot({path:'/workspace/kulturhus-gen49-browser-receipt.png',fullPage:false});
   }
   await context.close();
  }
  results.push(profile.tag);
 }
 check(external===0&&errors.length===0,'all receipt frame views remain local without script errors');fs.writeFileSync(fixture.root+'/finance-receipt-frame-results.json',JSON.stringify(results));console.log(JSON.stringify({suite:'finance-receipt-frame-browser',checks,views,profiles:results.length,external_actions:external}));
}catch(error){console.error('Finance receipt stage '+stage);for(const c of browser.contexts())for(const p of c.pages()){fs.writeFileSync('/workspace/kulturhus-gen49-browser-failure.html',await p.content(),{mode:0o600});await p.screenshot({path:'/workspace/kulturhus-gen49-browser-failure.png',fullPage:false}).catch(()=>{});console.error(JSON.stringify({url:p.url(),alerts:await p.locator('[role=alert],.error').allTextContents()}));}throw error;}finally{await browser.close();}
