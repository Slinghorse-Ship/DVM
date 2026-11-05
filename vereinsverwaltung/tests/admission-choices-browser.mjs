import fs from 'node:fs';
const f=JSON.parse(fs.readFileSync(0,'utf8'));
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE);
const browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE,headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
let checks=0,posts=0,views=0,staleRequests=0;const errors=[];
const check=(ok,label)=>{if(!ok)throw new Error(label);checks++;};
const url=data=>f.origin+'/vereinsverwaltung/?'+new URLSearchParams({view:'portal',tab:'application',...data});
const review=p=>p.locator('form').filter({has:p.locator('[name=action][value=review-application]')});
const manual=p=>p.locator('form').filter({has:p.locator('[name=action][value=manual-application]')});
const picker=(form,kind)=>form.locator('[data-admission-choice="'+kind+'"]');
async function submit(p,button){const response=p.waitForResponse(r=>r.request().method()==='POST');const navigation=p.waitForNavigation({waitUntil:'load'});await button.click();const actual=await response;check(actual.status()===200||actual.status()===303,'one explicit original form action succeeds');await actual.finished();await navigation;}
async function search(p,form,kind,text){await picker(form,kind).locator('input[type=search]').fill(text);await submit(p,picker(form,kind).locator('[name=admission_browse][value="'+kind+':search"]'));}
async function fit(p,label){for(const width of[320,390,820])for(const scale of[1,2]){await p.setViewportSize({width,height:1000});await p.evaluate(scale=>document.documentElement.style.fontSize=String(scale*100)+'%',scale);const measure=await p.evaluate(()=>({unreadable:[...document.querySelectorAll('[data-admission-choice] button')].filter(n=>!n.disabled&&getComputedStyle(n).color==='rgb(255, 255, 255)'&&getComputedStyle(n).backgroundColor==='rgba(0, 0, 0, 0)').map(n=>n.textContent),wide:document.documentElement.scrollWidth>innerWidth+1,bad:[...document.querySelectorAll('main *')].filter(n=>n.getClientRects().length&&n.getBoundingClientRect().right>innerWidth+1).slice(-12).map(n=>({tag:n.tagName,cls:n.className,name:n.name,right:n.getBoundingClientRect().right,text:n.textContent.slice(0,80)})),small:[...document.querySelectorAll('[data-admission-choice] :is(button,select,input),.admission-family-help>summary')].filter(n=>n.getClientRects().length&&n.getBoundingClientRect().height<43).map(n=>n.name)}));check(!measure.wide&&measure.small.length===0&&measure.unreadable.length===0,'touch choices fit '+label+'/'+width+'/'+scale+' '+JSON.stringify(measure));views++;}await p.setViewportSize({width:390,height:1000});await p.evaluate(()=>document.documentElement.style.fontSize='');}
try{
 for(const [index,js]of[true,false].entries()){
  console.log('Checking actual admission choices '+js);
  const target=f.data.targets[index];const context=await browser.newContext({viewport:{width:390,height:1000},hasTouch:true,javaScriptEnabled:js});
  await context.route('**/*',route=>new URL(route.request().url()).origin===f.origin?route.continue():route.abort());
  const p=await context.newPage();p.on('pageerror',error=>errors.push(error.message));p.on('request',r=>{if(r.method()==='POST')posts++;});
  await p.goto(f.origin+'/own-test-enter?role=admin');await p.goto(url({application:f.data.applications[index]}));
  await p.getByText('Bereits gefassten Vorstandsbeschluss eintragen',{exact:true}).click();let form=review(p);
  await form.locator('[name=decision]').selectOption('accepted');await form.locator('[name=decision_date]').fill('2026-10-15');await form.locator('[name=external_decision]').check();await form.locator('[name=start]').fill('2026-10-15');await form.locator('[name=review_note]').fill('Own exact admission decision ä🙂 '+js);
  check(await picker(form,'family').locator('select option').count()===9,'first choice contains only six families and three ordinary choices');
  await search(p,form,'family',target.familyQuery);form=review(p);
  check(await form.locator('[name=review_note]').inputValue()==='Own exact admission decision ä🙂 '+js&&await form.locator('[name=decision_date]').inputValue()==='2026-10-15'&&await form.locator('[name=external_decision]').isChecked(),'family lookup retains actual complete human decision');
  await picker(form,'family').locator('select').selectOption(target.family+':1');await search(p,form,'family','');form=review(p);
  check(await form.locator('[name=family_target]').inputValue()===target.family+':1'&&await picker(form,'family').locator('select option').count()<=10,'held original family remains chosen outside first six results');
  await submit(p,picker(form,'family').locator('[name=admission_browse][value="family:next"]'));form=review(p);check(await form.locator('[name=admission_family_page]').inputValue()==='2'&&await form.locator('[name=family_target]').inputValue()===target.family+':1','actual next family page keeps exact held selection');
  await form.locator('[name=admission_family_page]').fill('168');await submit(p,picker(form,'family').locator('[name=admission_browse][value="family:page"]'));form=review(p);check(await form.locator('[name=admission_family_page]').inputValue()==='168'&&await form.locator('[name=review_note]').inputValue()==='Own exact admission decision ä🙂 '+js,'actual direct final page keeps full human review');
  await form.getByText('Mitgliedsnummer und vorhandenes Profil',{exact:true}).click();await form.locator('[name=number]').fill('OWN-COMPACT-'+index);
  await search(p,form,'contact',target.contactQuery);form=review(p);await form.locator('[name=existing_contact]').selectOption(target.contact);await search(p,form,'contact','');form=review(p);
  check(await form.locator('[name=existing_contact]').inputValue()===target.contact&&await form.locator('[name=number]').inputValue()==='OWN-COMPACT-'+index&&await form.locator('[name=family_target]').inputValue()===target.family+':1','profile lookup retains both exact selections and human member number');
  await fit(p,'review/'+js);form=review(p);if(index===0){await picker(form,'family').scrollIntoViewIfNeeded();await p.screenshot({path:'/tmp/admission-choices-review-mobile.png'});}
  const draft=await form.evaluate(node=>Object.fromEntries(new FormData(node)));
  staleRequests++;const stale=await context.request.post(p.url(),{form:{...draft,family_target:target.family+':99'}});const staleHtml=await stale.text();
  check(staleHtml.includes('Familie oder Beitragszahler inzwischen geändert')&&staleHtml.includes('value="'+target.family+':99" selected')&&staleHtml.includes('Own exact admission decision ä🙂 '+js),'actual stale family is rejected with original version and complete human decision retained');
  await submit(p,form.getByRole('button',{name:'Beschluss speichern',exact:true}));
  try{await p.locator('.admission-application-heading .status').filter({hasText:'Aufgenommen'}).waitFor({state:'visible',timeout:10000});}catch(error){console.log('Own actual admission response '+p.url()+' '+(await p.locator('body').innerText()).slice(-3500));throw error;}
  check((await p.locator('main').innerText()).includes('Aufgenommen')&&!await review(p).count(),'explicit original decision accepts selected actual membership');
  await p.goto(url({manual:'1'}));form=manual(p);
  await form.locator('[name=first_name]').fill('Own manual '+index);await form.locator('[name=last_name]').fill('Held application ä');await form.locator('[name=birthday]').fill('1990-01-02');await form.locator('[name=email]').fill('manual-'+index+'@example.invalid');await form.locator('[name=address]').fill('Own manual address');await form.locator('[name=membership_type]').selectOption('family');await form.locator('[name=family_members]').fill('Own unsent family person\nSecond person');await form.locator('[name=consent_received]').check();await form.locator('[name=external_decision]').check();await form.locator('[name=decision_date]').fill('2026-10-15');await form.locator('[name=review_note]').fill('Own unsent manual decision '+js);
  const key=await form.locator('[name=request_key]').inputValue();await search(p,form,'family',target.familyQuery);form=manual(p);
  check(await form.locator('[name=first_name]').inputValue()==='Own manual '+index&&await form.locator('[name=request_key]').inputValue()===key&&await form.locator('[name=consent_received]').isChecked()&&await form.locator('[name=external_decision]').isChecked()&&await form.locator('[name=family_members]').inputValue()==='Own unsent family person\nSecond person','manual lookup preserves personal fields original request and both conscious confirmations');
  await form.locator('[name=family_target]').selectOption(target.family+':2');
  await form.getByText('Mitgliedsnummer und vorhandenes Profil',{exact:true}).click();await form.locator('[name=number]').fill('Own manual draft number');await search(p,form,'contact',target.contactQuery);form=manual(p);
  check(await form.locator('[name=review_note]').inputValue()==='Own unsent manual decision '+js&&await form.locator('[name=number]').inputValue()==='Own manual draft number'&&await form.locator('[name=family_target]').inputValue()===target.family+':2','manual profile lookup keeps the current original family and all decision fields');
  await fit(p,'manual/'+js);form=manual(p);if(index===0){await picker(form,'family').scrollIntoViewIfNeeded();await p.screenshot({path:'/tmp/admission-choices-manual-mobile.png'});}await form.locator('[name=external_decision]').uncheck();
  await submit(p,form.getByRole('button',{name:'Anlegen und Original anhängen',exact:true}));
  try{await p.locator('.admission-application-heading h2').filter({hasText:'Own manual '+index}).waitFor({state:'visible',timeout:10000});}catch(error){console.log('Own actual manual response '+p.url()+' '+(await p.locator('body').innerText()).slice(-3500));throw error;}
  check((await p.locator('main').innerText()).includes('Own manual '+index)&&!await manual(p).count(),'explicit manual submission creates its one original pending application');
  await context.close();
 }
 check(errors.length===0,'actual JS/plain original admission forms have no script errors');console.log(JSON.stringify({browser_checks:checks,actual_posts:posts,actual_stale_requests:staleRequests,mobile_font_views:views,external_actions:0}));
}finally{await browser.close();}
