import fs from 'node:fs';
const fixture=JSON.parse(fs.readFileSync(0,'utf8'));
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE);
const browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE,headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
let checks=0,posts=0,states=0;
const check=(ok,label)=>{if(!ok)throw new Error(label);checks++;};
const root=p=>p.locator('[data-native-chat]'),creation=p=>p.locator('.chat-poll-create form');
async function ready(page){await root(page).waitFor();await page.waitForLoadState('load');}
async function fields(form){return await form.evaluate(f=>Object.fromEntries(new FormData(f)));}
async function fit(page,label){for(let i=0;i<20;i++){if(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)){check(true,'actual poll fits '+label);return;}await page.waitForTimeout(50);}await page.screenshot({path:'/tmp/chat-poll-forms-overflow.png',fullPage:true});throw new Error('Actual poll overflow '+label);}
try{
 for(const mode of['native','integrated'])for(const js of[true,false]){
  const flow=js?'js':'basic',account=fixture.accounts[mode][flow],title='Actual browser poll '+mode+' '+flow+' ä🙂';console.log('Checking actual poll '+mode+'/'+flow);
  const context=await browser.newContext({viewport:{width:390,height:1000},hasTouch:true,javaScriptEnabled:js});await context.route('**/*',r=>new URL(r.request().url()).origin===fixture.origin?r.continue():r.abort('blockedbyclient'));
  const page=await context.newPage();page.setDefaultTimeout(15000);const errors=[];let lastPost;
  page.on('pageerror',e=>errors.push(e.message));page.on('request',r=>{if(r.method()==='POST'){posts++;lastPost={url:r.url(),data:r.postDataBuffer(),type:r.headers()['content-type']};}});
  const post=async(button)=>{
   if(mode==='native'&&js){const old=await page.locator('#notice').innerText();await root(page).evaluate(r=>r.dataset.ownActionPending='1');await Promise.all([page.waitForResponse(r=>r.request().method()==='POST'),button.tap()]);await page.waitForFunction(old=>!document.querySelector('[data-native-chat]')?.dataset.ownActionPending||document.getElementById('notice')?.textContent!==old,old);}
   else await Promise.all([page.waitForNavigation({waitUntil:'load'}),button.tap()]);
   await ready(page);
  };
  const request=async(data)=>{posts++;return await context.request.post(page.url(),{form:data});};
  await page.goto(fixture.origin+'/own-test-enter?mode='+mode+'&flow='+flow);await ready(page);await page.locator('.chat-poll-create > summary').tap();
  check(await creation(page).locator('[name=question]').inputValue()===''&&await creation(page).locator('[name=options]').inputValue()==='','actual poll starts with empty personal inputs');
  const key=await creation(page).locator('[name=request_key]').inputValue();await creation(page).locator('[name=question]').fill(title);await creation(page).locator('[name=options]').fill('Dienstag\n DIENSTAG ');await creation(page).locator('[name=closes_at]').fill('2050-10-14T18:30');await post(creation(page).getByRole('button',{name:'Umfrage erstellen',exact:true}));
  check(await page.locator('.chat-poll-create').evaluate(d=>d.open),'actual rejected poll stays open for correction');
  check(await creation(page).locator('[name=question]').inputValue()===title&&await creation(page).locator('[name=options]').inputValue()==='Dienstag\n DIENSTAG '&&await creation(page).locator('[name=request_key]').inputValue()===key&&await creation(page).locator('[name=closes_at]').inputValue()==='2050-10-14T18:30','actual failed form retains human question, whitespace, date and original retry identity');
  for(const width of[320,390,820])for(const scale of[1,2]){states++;await page.setViewportSize({width,height:1000});await page.evaluate(s=>document.documentElement.style.fontSize=s===2?'200%':'100%',scale);await fit(page,mode+'/'+flow+'/'+width+'/'+scale);check((await creation(page).getByRole('button',{name:'Umfrage erstellen',exact:true}).boundingBox()).height>=44,'actual creation has a readable touch target');}
  await page.setViewportSize({width:390,height:1000});await page.evaluate(()=>document.documentElement.style.fontSize='100%');await creation(page).locator('[name=options]').fill('Dienstag\nMittwoch\n0');
  if(mode==='native'&&js){let lost=false;await page.route('**/*',async r=>{if(!lost&&r.request().method()==='POST'){lost=true;const accepted=await r.fetch();check(accepted.status()===200,'actual poll commits before lost HTTP response');await r.abort('failed');}else await r.continue();});const previous=await page.locator('#notice').innerText();await creation(page).getByRole('button',{name:'Umfrage erstellen',exact:true}).tap();await page.waitForFunction(old=>document.getElementById('notice')?.textContent!==old,previous);check(lost&&await creation(page).locator('[name=question]').inputValue()===title&&await creation(page).locator('[name=request_key]').inputValue()===key,'accepted lost response keeps exact poll for original retry');await page.unroute('**/*');}
  await post(creation(page).getByRole('button',{name:'Umfrage erstellen',exact:true}));const created=lastPost;
  const poll=()=>page.locator('.chat-poll').filter({has:page.getByRole('heading',{name:title,exact:true})});
  check(await poll().count()===1&&(await poll().innerText()).includes('14.10.2050 · 18:30'),'actual corrected poll appears once with the original local deadline');check(await creation(page).locator('[name=question]').inputValue()===''&&await creation(page).locator('[name=request_key]').inputValue()!==key,'accepted creation clears only the completed poll input');
  posts++;const replay=await context.request.post(created.url,{data:created.data,headers:{'content-type':created.type}});check(replay.status()===200,'actual accepted HTTP replay returns the existing poll');
  const vote=()=>poll().locator('form').filter({has:page.locator('[name=operation][value=poll-vote]')});
  const original=await fields(vote());const first=await request({...original,option:'1'});check(first.status()===200,'independent exact HTTP action commits first human vote');
  await vote().locator('[name=option][value="2"]').check();await post(vote().getByRole('button',{name:'Abstimmen',exact:true}));
  check(await vote().locator('[name=version]').inputValue()==='0'&&await vote().locator('[name=request_key]').inputValue()===original.request_key&&await vote().locator('[name=option][value="2"]').isChecked(),'actual stale vote keeps original zero version, same identity and unsaved human choice');
  const refresh=root(page).getByRole('link',{name:'Aktualisieren',exact:true});
  if(mode==='native'&&js){await refresh.tap();await page.locator('#discard[open]').waitFor();check(await vote().locator('[name=option][value="2"]').isChecked(),'discard prompt protects the original unsaved vote');await Promise.all([page.waitForResponse(r=>r.request().method()==='GET'&&r.request().headers()['accept']?.includes('application/json')&&r.url().includes('view=chat')),page.locator('#discard [data-choice=discard]').tap()]);}
  else await Promise.all([page.waitForNavigation({waitUntil:'load'}),refresh.tap()]);
  await ready(page);await vote().locator('[name=version][value="1"]').waitFor({state:'attached'});
  check(await vote().locator('[name=request_key]').inputValue()!==original.request_key&&await vote().locator('[name=option][value="1"]').isChecked(),'explicit refresh renews vote form around the exact currently saved answer');
  await vote().locator('[name=option][value="2"]').check();await post(vote().getByRole('button',{name:'Stimme ändern',exact:true}));
  check(await vote().locator('[name=version]').inputValue()==='2'&&await vote().locator('[name=option][value="2"]').isChecked()&&(await poll().innerText()).includes('1 Stimmen'),'actual intentional change saves one original personal answer');
  const close=poll().locator('form').filter({has:page.locator('[name=operation][value=poll-close]')});const closeFields=await fields(close);const closed=await request(closeFields);check(closed.status()===200,'independent exact HTTP action closes the original poll');await vote().locator('[name=option][value="1"]').check();await post(vote().getByRole('button',{name:'Stimme ändern',exact:true}));
  if(mode==='native'&&js)check(await vote().locator('[name=option][value="1"]').isChecked(),'late rejected AJAX vote keeps personal unsaved choice');else check((await poll().innerText()).includes('Auswahl nicht gespeichert: Dienstag')&&await vote().count()===0,'late original correction shows unsaved answer and closed results');
  check(errors.length===0,'actual poll workflow has no browser script errors');await context.close();
 }
 console.log(JSON.stringify({browser_checks:checks,actual_posts:posts,viewport_states:states,external_actions:0}));
}finally{await browser.close();}
