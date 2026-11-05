import fs from 'node:fs';
const f=JSON.parse(fs.readFileSync(0,'utf8'));
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE);
const browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE,headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
let checks=0,posts=0,fontViews=0;
const check=(ok,label)=>{if(!ok)throw new Error(label);checks++;console.log('PASS '+label);};
const url=(mode,id)=>f.origin+(mode==='integrated'?'/vereinsverwaltung/':'/')+'?'+new URLSearchParams({view:'newsletter',section:'compose',[mode==='integrated'?'native_item':'item']:id});
async function client(mode,js,role='admin'){
 const c=await browser.newContext({viewport:{width:390,height:900},hasTouch:true,javaScriptEnabled:js});await c.route('**/*',r=>new URL(r.request().url()).origin===f.origin?r.continue():r.abort('blockedbyclient'));const p=await c.newPage();p.on('request',r=>{if(r.method()==='POST')posts++;});await p.goto(f.origin+'/own-test-enter?mode='+mode+'&module=newsletter&role='+role);await p.locator('.edition-library').waitFor();return{c,p};
}
const form=p=>p.locator('.compose-paper form').first();
async function loaded(p){await form(p).waitFor();await p.waitForLoadState('load');}
async function body(p,text,js){if(js){const box=form(p).locator('[data-richtext-surface]');await box.evaluate(el=>{if(el.getAttribute('contenteditable')!=='true')throw new Error('Actual rich editor not ready');});await box.fill(text);}else await form(p).locator('textarea[name=body_html]').fill('<p>'+text+'</p>');}
async function submit(p){const response=p.waitForResponse(r=>r.request().method()==='POST'&&new URL(r.url()).origin===f.origin);await form(p).getByRole('button',{name:'Entwurf speichern',exact:true}).click();await response;await p.waitForLoadState('load');}
async function fit(p,label){let fits=false;for(let i=0;i<30;i++){fits=await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1);if(fits)break;await p.waitForTimeout(50);}check(fits,'actual original correction fits '+label);const small=await form(p).evaluate(el=>[...el.querySelectorAll('input:not([type=hidden]),textarea,select,button')].filter(x=>x.getClientRects().length&&getComputedStyle(x).display!=='none').filter(x=>x.getBoundingClientRect().height<43).map(x=>x.name||x.textContent));check(small.length===0,'actual visible correction controls retain touch height '+label);}
try{
 for(const mode of['native','integrated']){
  const id=f.editions[mode].newsletter.items[1];const a=await client(mode,false);const p=a.p;await p.goto(url(mode,id));await loaded(p);check(await form(p).locator('textarea[name=body_html]').isVisible(),'newsletter human text is directly editable without JavaScript '+mode);
  const originalVersion=await form(p).locator('[name=version]').inputValue();await form(p).locator('[name=title]').fill('ä'.repeat(200));await form(p).locator('[name=summary]').fill('Own exact rejected browser summary ä🙂');await body(p,'Own exact rejected newsletter text ä🙂',false);await form(p).locator('details.compose-settings > summary').click();await form(p).locator('[name=audience]').selectOption('news');await submit(p);await loaded(p);
  check(await form(p).locator('[name=title]').inputValue()==='ä'.repeat(200)&&await form(p).locator('[name=summary]').inputValue()==='Own exact rejected browser summary ä🙂','actual byte-bound rejection keeps complete browser-valid Unicode title and summary '+mode);
  check((await form(p).locator('textarea[name=body_html]').inputValue()).includes('Own exact rejected newsletter text ä🙂')&&await form(p).locator('[name=version]').inputValue()===originalVersion&&await form(p).locator('[name=audience]').inputValue()==='news','actual rejected native POST keeps exact body audience and submitted version '+mode);
  await form(p).locator('[name=title]').fill('Own corrected browser title');await submit(p);await loaded(p);check(await form(p).locator('[name=version]').inputValue()==='2'&&(await form(p).locator('textarea[name=body_html]').inputValue()).includes('Own exact rejected newsletter text ä🙂'),'conscious actual native correction saves human original once '+mode);
  const staleVersion=await form(p).locator('[name=version]').inputValue();await form(p).locator('[name=title]').fill('Own exact stale browser title');await body(p,'Own exact stale unsaved text ä🙂',false);
  const b=await client(mode,false);await b.p.goto(url(mode,id));await loaded(b.p);await form(b.p).locator('[name=title]').fill('Own conscious later browser title');await body(b.p,'Own consciously changed later text',false);await submit(b.p);await loaded(b.p);check(await form(b.p).locator('[name=version]').inputValue()==='3','independent real editor consciously saves its newer source '+mode);await b.c.close();await submit(p);await loaded(p);
  check(await form(p).locator('[name=version]').inputValue()===staleVersion&&await form(p).locator('[name=title]').inputValue()==='Own exact stale browser title'&&(await form(p).locator('textarea[name=body_html]').inputValue()).includes('Own exact stale unsaved text ä🙂'),'actual concurrency rejection retains exact original stale form without newer version substitution '+mode);
  await p.goto(url(mode,id));await loaded(p);await form(p).locator('[name=title]').fill('Own transient database failure');await body(p,'Own complete human text after actual database failure',false);await submit(p);await loaded(p);check(await form(p).locator('[name=title]').inputValue()==='Own transient database failure'&&(await form(p).locator('textarea[name=body_html]').inputValue()).includes('Own complete human text after actual database failure')&&await form(p).locator('[name=version]').inputValue()==='3','actual rejected SQL UPDATE also retains exact original human correction '+mode);await a.c.close();
  for(const js of[true,false]){
   const own=await client(mode,js);const page=own.p;const errors=[];page.on('pageerror',e=>errors.push(e.message));
   for(const width of[320,390,820])for(const font of[1,2]){
    await page.setViewportSize({width,height:900});await page.goto(url(mode,id));await loaded(page);await form(page).locator('[name=title]').fill('ä'.repeat(200));await form(page).locator('[name=summary]').fill('Own exact mobile correction summary');await body(page,'Own exact mobile rejected text ä🙂',js);await submit(page);await loaded(page);
    check(await form(page).locator('[name=title]').inputValue()==='ä'.repeat(200)&&await form(page).locator('[name=summary]').inputValue()==='Own exact mobile correction summary'&&await form(page).locator('[name=version]').inputValue()==='3','actual JS or native rejected form retains original mobile fields '+mode+'/'+js+'/'+width+'/'+font);
    const text=js?await form(page).locator('[data-richtext-surface]').innerText():await form(page).locator('textarea[name=body_html]').inputValue();check(text.includes('Own exact mobile rejected text ä🙂'),'actual mobile human text survives validation '+mode+'/'+js+'/'+width+'/'+font);
    if(font===2)await page.evaluate(()=>document.documentElement.style.fontSize='200%');await fit(page,mode+'/'+js+'/'+width+'/'+font);fontViews++;
   }
   check(errors.length===0,'actual correction has no script errors '+mode+'/'+js);await own.c.close();
  }
  const read=await client(mode,false,'reader');await read.p.goto(url(mode,id));await read.p.locator('.edition-reading').waitFor();check(await read.p.locator('.compose-paper form').count()===0,'actual current reader cannot gain newsletter write controls '+mode);await read.c.close();
 }
 console.log(JSON.stringify({suite:'newsletter-compose-browser',checks,posts,font_views:fontViews,external_actions:0,status:'passed'}));
}finally{await browser.close();}
