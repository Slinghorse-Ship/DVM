import fs from 'node:fs';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE);
const f=JSON.parse(fs.readFileSync(process.argv[2]));
const browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE,headless:true});
let checks=0;
function check(value,label){if(!value)throw new Error(label);checks++;}
const url=(origin,id)=>origin+'/vereinsverwaltung/?'+new URLSearchParams({view:'finance',tab:'bank',transaction:id});
try{
 for(const app of ['front','direct'])for(const js of [false,true]){
  console.log('Browser case '+app+'/'+js);
  const origin=f.origins[app],data=f.data[app+(js?'-js':'-nojs')];
  const context=await browser.newContext({javaScriptEnabled:js,viewport:{width:js?390:320,height:900},hasTouch:true});
  let external=0;await context.route('**/*',r=>new URL(r.request().url()).origin===origin?r.continue():(external++,r.abort()));
  const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto(origin+'/login',{waitUntil:'domcontentloaded'});await page.locator('[name=email]').fill(f.accounts.admin.email);await page.locator('[name=password]').fill(f.password);
  await page.getByRole('button',{name:'Anmelden und Vereinsmanager öffnen',exact:true}).click();
  check((await page.goto(url(origin,data.source),{waitUntil:'domcontentloaded'})).status()===200,'actual transaction page');
  await page.getByText('Vorhandenen Gegenumsatz auf eigenem Konto zuordnen',{exact:true}).click();
  const pair=page.locator('form').filter({has:page.locator('input[name=other_id][value="'+data.counter+'"]')});
  await pair.locator('[name=reason]').fill('Tatsächlicher eigener Kontoübertrag');
  await pair.getByRole('button',{name:'Als eigenen Geldtransfer zuordnen',exact:true}).click();
  await page.getByRole('heading',{name:'Geldtransfers zwischen eigenen Konten',exact:true}).waitFor({timeout:30000}).catch(async error=>{console.error(JSON.stringify({url:page.url(),headings:await page.locator('h1,h2').allTextContents(),errors:await page.locator('.error,[role=alert]').allTextContents(),body:(await page.locator('body').innerText()).slice(0,200)}));throw error;});checks++;
  check(await page.getByText('Als eigener Geldtransfer zugeordnet · kein Ertrag oder Aufwand.',{exact:true}).count()===1,'financial classification explained');
  const counter=page.getByRole('link',{name:'Gegenumsatz öffnen',exact:true});await counter.click();
  check(new URL(page.url()).searchParams.get('transaction')===data.counter,'counter movement navigation');
  const release=page.locator('form').filter({has:page.locator('[name=action][value=internal-release]')});await release.locator('[name=reason]').fill('Falsche Zuordnung bewusst korrigiert');
  await release.getByRole('button',{name:'Transferzuordnung aufheben',exact:true}).click();await page.getByText('Aufgehoben: Falsche Zuordnung bewusst korrigiert',{exact:true}).waitFor();
  check(await page.getByText('Aufgehoben: Falsche Zuordnung bewusst korrigiert',{exact:true}).count()===1,'correction reason preserved');
  await page.goto(url(origin,data.cash_source),{waitUntil:'domcontentloaded'});await page.getByText('Abhebung: tatsächlichen Zugang in der Handkasse erfassen',{exact:true}).click();
  const cash=page.locator('form').filter({has:page.locator('[name=action][value=internal-cash]')});await cash.locator('[name=cash_account]').selectOption(data.cash);await cash.locator('[name=reason]').fill('Bargeld tatsächlich in Handkasse übernommen');await cash.locator('[name=cash_confirm]').check();
  await cash.getByRole('button',{name:'Bargeldbewegung erfassen und zuordnen',exact:true}).click();await page.getByText('Bargeld tatsächlich in Handkasse übernommen',{exact:true}).waitFor();
  check(await page.getByText('Bargeld tatsächlich in Handkasse übernommen',{exact:true}).count()===1,'actual cash action persisted');
  for(const width of [320,390,820])for(const size of [16,32]){
   await page.setViewportSize({width,height:1000});await page.evaluate(size=>document.documentElement.style.fontSize=size+'px',size);await page.evaluate(()=>document.fonts.ready);await page.waitForFunction(()=>document.documentElement.scrollWidth<=innerWidth+1,null,{timeout:3000});
   const fits=await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1);if(!fits){await page.screenshot({path:'/workspace/kulturhus-internal-overflow.png',fullPage:true});console.error(await page.evaluate(()=>[...document.querySelectorAll('body *')].filter(e=>e.getBoundingClientRect().right>innerWidth+1).slice(0,8).map(e=>({tag:e.tagName,cls:e.className,right:e.getBoundingClientRect().right}))));}check(fits,'transfer history fits '+width+'/'+size);
  }
  check(errors.length===0&&external===0,'no browser errors or external requests');
  await context.close();
 }
 console.log(JSON.stringify({suite:'internal-movements-browser',checks,external_actions:0}));
}finally{await browser.close();}
