import fs from 'node:fs/promises';
import assert from 'node:assert/strict';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE);
const f=JSON.parse(await fs.readFile(process.env.DVM_TICKET_REFUND_FIXTURE,'utf8'));
const browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE,args:['--no-sandbox','--disable-dev-shm-usage']});
let checks=0;const check=(value,label)=>{assert.ok(value,label);checks++;};const errors=[];
const open=async(page)=>{await page.locator('#event-tickets > summary').click();await page.locator('#event-tickets summary').filter({hasText:/^Onlinebestellungen/}).click();};
const card=(page,name)=>page.locator('#event-tickets article').filter({has:page.getByRole('heading',{name,exact:true})});
const inspect=async(page)=>{const result=await page.locator('#event-tickets').evaluate(e=>({parents:(()=>{const a=[];for(let p=e;p;p=p.parentElement)a.push([p.tagName,p.className,p.getBoundingClientRect().width,getComputedStyle(p).minWidth,getComputedStyle(p).gridTemplateColumns]);return a;})(),right:e.getBoundingClientRect().right,width:document.documentElement.clientWidth,small:[...e.querySelectorAll('input:not([type=hidden]):not([type=checkbox]),button')].filter(x=>x.getClientRects().length).filter(x=>x.getBoundingClientRect().height<44||parseFloat(getComputedStyle(x).fontSize)<16).map(x=>x.name)}));check(result.right<=result.width+2,'ticket controls fit viewport '+JSON.stringify(result));check(!result.small.length,'refund controls have readable touch targets '+JSON.stringify(result));};
try{
 for(const js of [true,false])for(const width of [320,390,820])for(const zoom of [1,2]){
  const context=await browser.newContext({viewport:{width,height:1000},javaScriptEnabled:js});const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
  await page.goto(f.origin);await open(page);await card(page,'Browserrefund').locator('summary').click();if(zoom===2)await page.evaluate(()=>document.documentElement.style.fontSize='200%');
  await inspect(page);check(await card(page,'Browserrefund').getByLabel('Stornogrund',{exact:true}).count()===1,'one labelled original refund form');await context.close();
 }
 const context=await browser.newContext({viewport:{width:390,height:844},isMobile:true,hasTouch:true});const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));await page.goto(f.origin);await open(page);await card(page,'Browserrefund').locator('summary').tap();
 await card(page,'Browserrefund').getByLabel('Stornogrund',{exact:true}).fill('Browser customer cancellation');await card(page,'Browserrefund').getByRole('button',{name:'Storno bestätigen',exact:true}).tap();
 check(await card(page,'Browserrefund').getByRole('checkbox').evaluate(e=>!e.validity.valid),'explicit confirmation required before actual submit');await card(page,'Browserrefund').getByRole('checkbox').check();
 await Promise.all([page.waitForResponse(r=>r.request().method()==='POST'),card(page,'Browserrefund').getByRole('button',{name:'Storno bestätigen',exact:true}).tap()]);await page.waitForFunction(()=>document.getElementById('event-tickets')?.textContent.includes('Erstattung noch nicht bestätigt'));await open(page);
 check((await card(page,'Browserrefund').textContent()).includes('Erstattung noch nicht bestätigt'),'actual uncertain provider result remains visible');check(await card(page,'Browserrefund').getByRole('button',{name:'Storno bestätigen',exact:true}).count()===0,'pending refund offers no second write');await inspect(page);
 await Promise.all([page.waitForResponse(r=>r.request().method()==='POST'),card(page,'Browserrefund').getByRole('button',{name:'Erstattung prüfen',exact:true}).tap()]);await page.waitForFunction(()=>!document.getElementById('event-tickets')?.textContent.includes('Erstattung noch nicht bestätigt'));await open(page);check((await card(page,'Browserrefund').textContent()).includes('Erstattet'),'actual read-only continuation confirms original refund');
 await page.goto(f.origin+'/tickets/order?access='+f.access);check(await page.locator('.ticket-qr').count()===0&&(await page.locator('main').textContent()).includes('Erstattet'),'public original order loses both QR cards after confirmed refund');await context.close();
 const nojs=await browser.newContext({viewport:{width:320,height:900},javaScriptEnabled:false});const p=await nojs.newPage();await p.goto(f.origin);await open(p);await card(p,'Browserfree').locator('summary').click();await card(p,'Browserfree').getByLabel('Stornogrund',{exact:true}).fill('No JavaScript cancellation');await card(p,'Browserfree').getByRole('checkbox').check();
 await Promise.all([p.waitForNavigation(),card(p,'Browserfree').getByRole('button',{name:'Storno bestätigen',exact:true}).click()]);await open(p);check((await card(p,'Browserfree').textContent()).includes('Storniert'),'real form cancels free original without JavaScript');await nojs.close();
 check(!errors.length,'no browser exceptions '+errors.join(';'));console.log(JSON.stringify({checks,status:'passed',live_transport:false}));
}finally{await browser.close();}
