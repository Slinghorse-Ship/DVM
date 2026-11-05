import fs from 'node:fs';
const f=JSON.parse(fs.readFileSync(0,'utf8'));
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE);
const browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE,headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
let checks=0,posts=0,views=0;
const check=(yes,label)=>{if(!yes)throw Error(label);checks++;console.log('PASS '+label);};
const url=f.origin+'/vereinsverwaltung/?view=calendar&new_event=1';
async function browse(p,value){await p.locator('[name=calendar_browse][value='+value+']').evaluate(n=>{for(let d=n.closest('details');d;d=d.parentElement.closest('details'))d.open=true;});const [response]=await Promise.all([p.waitForResponse(r=>r.request().method()==='POST'&&new URLSearchParams(r.request().postData()).get('calendar_browse')===value),p.locator('[name=calendar_browse][value='+value+']').click()]);await response.finished();await p.waitForLoadState('load');}

try{
 for(const js of [true,false]){
  const c=await browser.newContext({viewport:{width:390,height:900},javaScriptEnabled:js,serviceWorkers:'block'});
  await c.route('**/*',r=>new URL(r.request().url()).origin===f.origin?r.continue():r.abort());c.on('request',r=>{if(r.method()==='POST'){posts++;}});
  const p=await c.newPage();await p.goto(f.origin+'/own-test-enter?role=admin');await p.goto(url);
  check(await p.locator('[data-meeting-person]').count()===6,'six actual controller rows for '+js);
  check(!await p.locator('[data-meeting-individual]').evaluate(n=>n.open),'individual participant controls start collapsed '+js);await p.locator('[data-meeting-individual] > summary').click();
  await p.locator('[name=title]').fill('Own held calendar '+js);await p.locator('[name=start]').fill('2027-10-15T18:00');await p.locator('[name=end]').fill('2027-10-15T20:00');await p.locator('[name=description]').fill('Own unchanged full description Σ ä🙂');
  await p.locator('[name="attendees[]"]').first().check();if(js)check((await p.locator('[data-meeting-count]').textContent()).startsWith('1 Person ausgewählt'),'immediate live count follows conscious check without a POST');await browse(p,'next');await p.waitForFunction(()=>document.querySelector('[name=calendar_page]')?.value==='2');
  check(JSON.parse(await p.locator('[name=calendar_selected]').inputValue()).includes(f.data.first),'off-page first selection retained '+js);
  check(await p.locator('[name=title]').inputValue()==='Own held calendar '+js&&await p.locator('[name=start]').inputValue()==='2027-10-15T18:00','event fields retained '+js);
  await p.locator('[name=calendar_query]').fill('100%_! Σ Straße ä🙂');await browse(p,'search');await p.waitForFunction(()=>document.querySelectorAll('[data-meeting-person]').length===1);
  check(await p.locator('[data-meeting-person]').count()===1&&await p.locator('[name="attendees[]"]').inputValue()===f.data.last,'search reaches exact original beyond one thousand '+js);
  await p.locator('[name="attendees[]"]').check();await p.locator('[name=calendar_query]').fill('No matching person');await browse(p,'search');await p.waitForFunction(()=>document.querySelectorAll('[data-meeting-person]').length===0);
  check(await p.locator('[data-meeting-person]').count()===0&&JSON.parse(await p.locator('[name=calendar_selected]').inputValue()).length===2,'empty results retain both page selections '+js);
  await p.locator('[name=calendar_query]').fill('');await browse(p,'search');await p.waitForFunction(()=>document.querySelectorAll('[data-meeting-person]').length===6);
  for(const width of [320,390,820])for(const font of [100,200]){
   await p.setViewportSize({width,height:950});await p.evaluate(x=>document.documentElement.style.fontSize=x+'%',font);
   check(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'no horizontal overflow '+js+' '+width+' '+font);views++;
   check(await p.locator('[data-meeting-attendees]').evaluate(panel=>[...panel.querySelectorAll('button,label.check')].filter(e=>e.getClientRects().length).every(e=>e.getBoundingClientRect().height>=43.9)),'all actual choice labels and buttons have 44px touch height '+js+' '+width+' '+font);
  }
  await p.evaluate(()=>document.documentElement.style.fontSize='100%');await p.setViewportSize({width:390,height:950});
  await p.locator('[name=calendar_only_selected]').check();await browse(p,'search');await p.waitForFunction(()=>document.querySelectorAll('[data-meeting-person]').length===2);
  check(await p.locator('[data-meeting-person]').count()===2,'selected-only view exposes both exact original choices '+js);
  await p.locator('[name=calendar_only_selected]').uncheck();await browse(p,'search');await p.waitForFunction(()=>document.querySelectorAll('[data-meeting-person]').length===6);
  if(f.data.phase==='readonly'){
   await browse(p,'members');check(JSON.parse(await p.locator('[name=calendar_selected]').inputValue()).length===2,'member shortcut selects exactly two current members '+js);await browse(p,'board');check(JSON.parse(await p.locator('[name=calendar_selected]').inputValue()).length===4,'board shortcut selects exactly four confirmed offices '+js);await p.locator('[data-meeting-individual]').evaluate(n=>n.open=true);
   await browse(p,'group');await p.waitForFunction(()=>document.querySelector('[name=calendar_selected]')&&JSON.parse(document.querySelector('[name=calendar_selected]').value).length===1003);
   check(JSON.parse(await p.locator('[name=calendar_selected]').inputValue()).length===1003,'one conscious full-group action selects all 1003 '+js);
   await browse(p,'clear');await p.waitForFunction(()=>document.querySelector('[name=calendar_selected]')&&JSON.parse(document.querySelector('[name=calendar_selected]').value).length===0);
   if(js)await Promise.all([p.waitForNavigation(),p.locator('[name=activity_kind][value=board]').check()]);else{await p.locator('[name=activity_kind][value=board]').check();await browse(p,'refresh');}
   await p.waitForFunction(()=>document.querySelector('[name=calendar_kind]')?.value==='board');
   check(await p.locator('[data-meeting-person]').count()===4&&await p.locator('[name="attendees[]"]:checked').count()===4,'actual board kind selects exactly four confirmed offices '+js);
   check(await p.locator('[name=title]').inputValue()==='Own held calendar '+js,'kind switch retains custom human title '+js);
   await c.close();continue;
  }
  await p.locator('[name=end]').fill('2027-10-15T17:00');await Promise.all([p.waitForNavigation(),p.getByRole('button',{name:'Termin speichern',exact:true}).click()]);
  await p.getByRole('alert').filter({hasText:'Das Ende muss nach dem Beginn liegen.'}).waitFor();
  check(await p.locator('[name=title]').inputValue()==='Own held calendar '+js&&JSON.parse(await p.locator('[name=calendar_selected]').inputValue()).length===2,'rejected actual save retains full selection and title '+js);
  await p.locator('[name=end]').fill('2027-10-15T20:00');await Promise.all([p.waitForNavigation(),p.getByRole('button',{name:'Termin speichern',exact:true}).click()]);
  await p.waitForFunction(()=>new URL(location.href).searchParams.has('event'));
  check(await p.locator('#event-form [name=id]').inputValue()!==''&&JSON.parse(await p.locator('[name=calendar_selected]').inputValue()).length===2,'conscious actual save persists both original contacts '+js);
  check(await p.locator('[name=description]').inputValue()==='Own unchanged full description Σ ä🙂','original full description survives actual save '+js);
  await c.close();
 }
 if(f.data.phase==='readonly')for(const role of ['reader','finance-only']){const c=await browser.newContext({viewport:{width:390,height:900},javaScriptEnabled:false});await c.route('**/*',r=>new URL(r.request().url()).origin===f.origin?r.continue():r.abort());const p=await c.newPage();await p.goto(f.origin+'/own-test-enter?role='+role);await p.goto(url);check(await p.locator('#event-form').count()===0,'current '+role+' cannot gain calendar write form');await c.close();}
 check(posts===(f.data.phase==='readonly'?22:15),'all actual browse and save requests match client and server validation');
 console.log(JSON.stringify({browser_checks:checks,actual_posts:posts,mobile_font_views:views,phase:f.data.phase,external_actions:0}));
}finally{await browser.close();}
