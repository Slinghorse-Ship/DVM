'use strict';
// Manual original-fixture browser companion; integrated cloud coverage is calendar-people-browser.mjs.
const {chromium}=require('playwright'),fs=require('node:fs'),assert=require('node:assert/strict');
(async()=>{
 const f=JSON.parse(fs.readFileSync(process.env.KULTURHUS_MEMBER_PROFILE_FIXTURE,'utf8').replace(/^\uFEFF/,'')),base=new URL(f.base);
 assert(['localhost','127.0.0.1'].includes(base.hostname)&&base.port==='8091'&&f.club.startsWith('member-profile-'));
 const browser=await chromium.launch(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE,headless:true,args:['--no-sandbox']}:{channel:'msedge',headless:true});let n=0;
 const ok=(value,label)=>{assert(value,label);console.log('PASS '+label);n++;};
 try{
  const c=await browser.newContext({viewport:{width:1280,height:900},serviceWorkers:'block'});await c.addCookies([{name:'KH_VEREIN_DEMO',value:f.sessions.admin,url:base.origin+'/'}]);
  await c.route('**/*',r=>{const q=r.request();if(new URL(q.url()).origin!==base.origin)return r.abort();if(q.method()!=='GET'&&!(q.method()==='POST'&&['save-event','preview-event-mail'].includes(new URLSearchParams(q.postData()).get('action'))))return r.abort();return r.continue();});
  const p=await c.newPage(),errors=[];p.on('pageerror',e=>errors.push(e.message));
  const post=async click=>{const [r]=await Promise.all([p.waitForResponse(r=>r.request().method()==='POST'),click()]);await r.finished();await p.waitForLoadState('load');};
  const browse=value=>post(()=>p.locator('[name=calendar_browse][value='+value+']').click());
  const kind=value=>post(()=>p.locator('[name=activity_kind][value='+value+']').check());
  const chosen=()=>p.locator('#event-form').evaluate(form=>{const ids=new Set(JSON.parse(form.elements.calendar_selected.value));for(const id of JSON.parse(form.elements.calendar_visible.value))ids.delete(id);for(const e of form.querySelectorAll('[name="attendees[]"]:checked'))ids.add(e.value);return ids.size;});
  const response=await p.goto(base.origin+'/?view=calendar&new_event=1&meeting=1');assert.equal(response.headers()['x-kulturhus-local-project'],f.project_hash);
  ok(await p.locator('[name=title]').inputValue()==='Mitgliederversammlung','new assembly has original standard title');
  ok(await p.locator('[data-meeting-person]').count()===2,'assembly contains only current active and passive members');
  await browse('group');ok(await chosen()===2,'one explicit full group action selects both actual members');
  await kind('board');ok(await chosen()===4,'actual type refresh preselects four confirmed offices');
  ok(await p.locator('[name=title]').inputValue()==='Vorstandsversammlung','standard title follows actual type refresh');
  await p.locator('[name=title]').fill('Unser individueller Beratungstermin');await p.locator('[name=start]').fill('2027-04-03T18:00');await p.locator('[name=end]').fill('2027-04-03T20:00');
  await p.locator('#meeting-person-search').fill('Office 2');await browse('search');ok(await p.locator('[data-meeting-person]').count()===1&&await chosen()===4,'server search keeps all selected off-page officers');
  await p.locator('#meeting-person-search').fill('');await browse('search');
  await post(()=>p.getByRole('button',{name:'Termin speichern',exact:true}).click());await p.reload();
  ok(await chosen()===4&&await p.locator('[name=title]').inputValue()==='Unser individueller Beratungstermin','actual save preserves exact officers and human title');
  ok(await p.locator('.page-heading h1').textContent()==='Termin bearbeiten','saved event retains editing heading');
  await kind('assembly');ok(await p.locator('[name=title]').inputValue()==='Unser individueller Beratungstermin'&&await chosen()===0,'conscious kind change preserves human title and resets incompatible choices');
  for(const width of [320,390,820])for(const font of [100,200]){await p.setViewportSize({width,height:950});await p.evaluate(v=>document.documentElement.style.fontSize=v+'%',font);ok(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'bounded mobile and enlarged text '+width+' '+font);}
  ok(!errors.length,'no browser JavaScript errors');await c.close();console.log(JSON.stringify({checks:n,no_dispatch:true}));
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
