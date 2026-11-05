import fs from 'node:fs';
const f=JSON.parse(fs.readFileSync(0,'utf8'));
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE);
const browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE,headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
let checks=0,posts=0;const check=(ok,label)=>{if(!ok)throw new Error(label);checks++;};
const url=(mode,extra={})=>f.origin+(mode==='integrated'?'/vereinsverwaltung/':'/')+'?'+new URLSearchParams({view:'newsletter',section:'planning',...extra});
const fit=async(p,label)=>{await p.waitForLoadState('load');let ok=false;for(let i=0;i<20;i++){if(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)){ok=true;break;}await p.waitForTimeout(50);}if(!ok){await p.screenshot({path:'/tmp/newsletter-deliveries-overflow.png',fullPage:true});const nodes=await p.evaluate(()=>[...document.querySelectorAll('body *')].filter(el=>el.getBoundingClientRect().right>innerWidth+1).slice(0,8).map(el=>({tag:el.tagName,cls:el.className,width:Math.round(el.getBoundingClientRect().width),right:Math.round(el.getBoundingClientRect().right)})));const bounds=await p.evaluate(()=>({viewport:innerWidth,scroll:document.documentElement.scrollWidth,sidebar:document.querySelector('.sidebar')?.getBoundingClientRect().toJSON(),overflow:document.querySelector('.sidebar')&&getComputedStyle(document.querySelector('.sidebar')).overflowX}));throw new Error('Real newsletter viewport overflow '+label+' '+JSON.stringify({nodes,bounds}));}check(ok,'actual history fits viewport');};
try{
 for(const mode of['native','integrated'])for(const js of[true,false]){
  const context=await browser.newContext({viewport:{width:390,height:900},hasTouch:true,javaScriptEnabled:js});await context.route('**/*',route=>new URL(route.request().url()).origin===f.origin?route.continue():route.abort('blockedbyclient'));
  const p=await context.newPage();const errors=[];p.on('pageerror',e=>errors.push(e.message));p.on('request',r=>{if(r.method()==='POST')posts++;});
  await p.goto(f.origin+'/own-test-enter?mode='+mode+'&module=newsletter');await p.locator('.newsletter-deliveries').waitFor();
  check(await p.locator('.newsletter-delivery-card').count()===6,'six actual original messages rendered');
  check(!await p.locator('.newsletter-planning').evaluate(el=>el.open),'delivery overview precedes collapsed planning controls');
  await p.locator('[name=newsletter_kind]').selectOption('test');await p.locator('[name=newsletter_state]').selectOption('pending');await p.getByRole('button',{name:'Filtern',exact:true}).click();await p.locator('.newsletter-delivery-card').first().waitFor();
  check(await p.locator('[name=newsletter_kind]').inputValue()==='test'&&await p.locator('[name=newsletter_state]').inputValue()==='pending','actual private GET preserves mail-kind and status selection');
  await p.getByRole('link',{name:'Weiter',exact:true}).click();await p.getByRole('link',{name:'Zurück',exact:true}).waitFor();check((await p.locator('.newsletter-delivery-pages').innerText()).includes('Seite 2'),'actual second page is reached without a POST');
  await p.locator('[name=newsletter_state]').focus();await p.keyboard.press('Tab');check(await p.locator('[name=newsletter_kind]').evaluate(el=>el===document.activeElement),'actual status and kind filters follow keyboard order');
  for(const width of[320,390,820])for(const zoom of[1,2]){
   console.log('Checking actual newsletter history '+mode+'/'+js+'/'+width+'/'+zoom);
   await p.setViewportSize({width,height:900});await p.goto(url(mode));if(zoom===2)await p.evaluate(()=>document.documentElement.style.fontSize='200%');await fit(p,'list '+mode+'/'+js+'/'+width+'/'+zoom);
   check(await p.locator('.newsletter-delivery-card').count()===6,'all list views retain bounded actual originals');
   await p.locator('.newsletter-delivery-card').first().getByRole('link',{name:'Zustellung öffnen',exact:true}).click();await p.locator('.newsletter-delivery-focus').waitFor();if(zoom===2)await p.evaluate(()=>document.documentElement.style.fontSize='200%');await fit(p,'focus '+mode+'/'+js+'/'+width+'/'+zoom);
   check(!await p.locator('.newsletter-delivery-list').evaluate(el=>el.open),'selected original appears above closed full history');
   const button=await p.getByRole('link',{name:'Schließen',exact:true}).boundingBox();check(button.height>=44,'actual original close control has touch size');
  }
  await p.goto(url(mode,{newsletter_job:f.jobs[mode]}));await p.locator('.newsletter-delivery-focus').waitFor();await p.locator('.newsletter-delivery-message>summary').click();check((await p.locator('.newsletter-delivery-text').innerText()).length>0,'stored actual private message can be read');
  check(await p.locator('.newsletter-delivery-original').count()===1,'original frozen edition is shown for the actual focused test mail');
  check(errors.length===0,'no actual history script errors');await context.close();
 }
 for(const mode of['native','integrated']){
  const context=await browser.newContext({viewport:{width:320,height:900},hasTouch:true,javaScriptEnabled:false});await context.route('**/*',route=>new URL(route.request().url()).origin===f.origin?route.continue():route.abort('blockedbyclient'));const p=await context.newPage();p.on('request',r=>{if(r.method()==='POST')posts++;});
  await p.goto(f.origin+'/own-test-enter?mode='+mode+'&module=newsletter&role=reader');await p.locator('.newsletter-deliveries').waitFor();await p.goto(url(mode,{newsletter_job:f.jobs[mode]}));await p.locator('.newsletter-delivery-focus').waitFor();
  check(await p.locator('.newsletter-delivery-original').count()===1,'actual read-only account opens the frozen own-club edition');check(!await p.locator('.newsletter-planning form').count(),'read-only account gets no plan mutation form');await fit(p,'read role '+mode);await context.close();
 }
 check(posts===0,'every history gesture remains read-only');console.log(JSON.stringify({browser_checks:checks,posts,external_actions:0}));
}finally{await browser.close();}
