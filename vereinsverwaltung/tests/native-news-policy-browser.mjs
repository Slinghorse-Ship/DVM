import assert from 'node:assert/strict';
import {readFile,writeFile} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';

const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const root=fileURLToPath(new URL('../../',import.meta.url));
const ownFixture=process.env.DVM_EDITORIAL_WORKFLOW_FIXTURE?JSON.parse(await readFile(process.env.DVM_EDITORIAL_WORKFLOW_FIXTURE,'utf8')):null;
const fixture=ownFixture?.data??JSON.parse(await readFile(root+'.local/browser-tests/news-policy.json','utf8'));
const credentials=ownFixture?.accounts??JSON.parse(await readFile(root+'.local/native-cms-integration/integrated-manager-runtime/browser-credentials.json','utf8'));
const origin=ownFixture?.origin??'http://127.0.0.1:8111',browser=await chromium.launch({executablePath:process.env.CHROMIUM_EXECUTABLE||'/usr/bin/chromium'});
let checks=0,saves=0;const errors=[],external=[];
const check=(ok,label)=>{assert(ok,label);checks++;console.log('PASS '+label);};
const form=(page,operation)=>page.locator('form').filter({has:page.locator(`input[name=operation][value="${operation}"]`)});
const review=page=>page.getByRole('region',{name:'Redaktionelle Prüfung'});
const fits=async page=>{
  await page.waitForFunction(()=>[...document.querySelectorAll('link[rel=stylesheet]')].every(link=>link.sheet!==null));
  await page.evaluate(()=>document.fonts.ready);
  return page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2);
};
const fields=locator=>locator.evaluate(element=>[...new FormData(element).entries()]);
const submit=async(page,js,action)=>{
  if(js)await action();
  else await Promise.all([page.waitForNavigation({waitUntil:'load'}),action()]);
};
const contexts=[];
const sessions=new Map();
const session=async role=>{
  if(sessions.has(role))return sessions.get(role);
  const path=root+`.local/browser-tests/controls-sessions/${role}.json`;
  const c=await browser.newContext(ownFixture?{}:{storageState:path}),p=await c.newPage();
  await c.route('**/*',r=>new URL(r.request().url()).origin===origin?r.continue():(external.push(r.request().url()),r.abort()));
  try{
    if(ownFixture)await p.goto(origin+'/own-test-enter?role='+role);else
    await p.goto(origin+'/vereinsverwaltung/?view=website&tab=news');
    if(new URL(p.url()).pathname==='/login/'){
      await p.getByLabel('E-Mail-Adresse oder persönlicher Benutzername',{exact:true}).fill(credentials[role].email);
      await p.getByLabel('Passwort',{exact:true}).fill(credentials[role].password);
      await Promise.all([p.waitForURL(u=>u.pathname==='/vereinsverwaltung/'),p.getByRole('button',{name:'Anmelden und Vereinsmanager öffnen',exact:true}).click()]);
    }
    check(new URL(p.url()).pathname==='/vereinsverwaltung/','current authenticated session for '+role);
    const state=await c.storageState();sessions.set(role,state);if(!ownFixture)await writeFile(path,JSON.stringify(state),{mode:0o600});return state;
  }finally{await c.close();}
};
const context=async(role,js,width)=>{
  const c=await browser.newContext({storageState:await session(role),javaScriptEnabled:js,viewport:{width,height:900},hasTouch:width<=390});contexts.push(c);
  await c.route('**/*',r=>new URL(r.request().url()).origin===origin?r.continue():(external.push(r.request().url()),r.abort()));
  const page=await c.newPage();page.on('pageerror',e=>errors.push(e.message));return {c,page};
};
const rule=async(page,enabled)=>{
  check((await page.goto(origin+'/vereinsverwaltung/?view=website&tab=integrations')).status()===200,'settings opens with current account');
  const panel=page.getByRole('region',{name:'Freigabe für Beiträge'});await panel.getByLabel('Vier-Augen-Freigabe verlangen').setChecked(enabled);
  const version=await form(page,'news-policy').locator('[name=version]').inputValue();
  await panel.getByRole('button',{name:'Freigaberegel speichern',exact:true}).click();saves++;
  await page.waitForFunction(v=>document.querySelector('input[name=operation][value="news-policy"]')?.form.querySelector('[name=version]')?.value!==v,version);
  check(await panel.getByLabel('Vier-Augen-Freigabe verlangen').isChecked()===enabled,'saved club rule is explicit');
  check(await fits(page),'settings fits viewport');
};
try {
  const {page:settings}=await context('admin',true,320);await rule(settings,true);
  const staleRule=await fields(form(settings,'news-policy'));
  await rule(settings,false);await rule(settings,true);
  const stale=await settings.request.post(settings.url(),{headers:{Accept:'application/json'},form:Object.fromEntries(staleRule)});
  check([409,422].includes(stale.status()),'stale settings cannot replace a newer rule');
  for(const item of fixture.items){
    const {c:authorContext,page:author}=await context('admin',item.js,item.width);
    const {page:reviewer}=await context('editor',item.js,item.width);
    const url=origin+'/vereinsverwaltung/?view=website&tab=news&native_item='+item.id;
    check((await author.goto(url)).status()===200,'author opens exact private draft');
    check(await author.getByRole('button',{name:'Beitrag veröffentlichen',exact:true}).isDisabled(),'unapproved publication is disabled');
    check(await author.getByRole('button',{name:'Veröffentlichung planen',exact:true}).isDisabled(),'unapproved scheduling is disabled');
    check(await review(author).getByRole('button',{name:'Redaktionell freigeben',exact:true}).count()===0,'author receives no self-approval button');
    const originalResponse=await author.request.get(origin+'/'+item.slug+'/');check(originalResponse.status()===200,'exact original own published page really exists');const original=await originalResponse.text();
    const selfPost=Object.fromEntries(await fields(form(author,'article-publish')));selfPost.operation='article-review';selfPost.review_status='approved';
    const rejected=await authorContext.request.post(url,{headers:{Accept:'application/json'},form:selfPost});
    check([409,422].includes(rejected.status()),'real HTTP route rejects manufactured self-approval');
    check((await reviewer.goto(url)).status()===200,'second publisher opens exact saved original');
    const button=review(reviewer).getByRole('button',{name:'Redaktionell freigeben',exact:true});
    await button.focus();check(await button.evaluate(e=>e===document.activeElement),'review control is keyboard reachable');
    const box=await button.boundingBox();check(box.height>=44&&box.width>=44,'review control provides a touch target');
    const oldApproval=await fields(form(reviewer,'article-review').filter({has:reviewer.locator('[name=review_status][value=approved]')}));
    await submit(reviewer,item.js,()=>item.width<=390?button.tap():button.press('Enter'));saves++;
    await reviewer.waitForFunction(()=>document.querySelector('[data-four-eyes=approved]'));
    check(await fits(reviewer),'review panel fits current viewport');
    if(ownFixture&&item.width===390&&item.js){await review(reviewer).scrollIntoViewIfNeeded();await reviewer.screenshot({path:'/tmp/editorial-review-mobile.png'});}
    await author.reload();check(await author.getByRole('button',{name:'Beitrag veröffentlichen',exact:true}).isEnabled(),'exact second-person approval enables publication');
    check(await author.getByRole('button',{name:'Veröffentlichung planen',exact:true}).isEnabled(),'same approval enables scheduling');
    check(await (await author.request.get(origin+'/'+item.slug+'/')).text()===original,'private approval keeps public original byte-identical');
    const v=await form(author,'article-save').locator('[name=version]').inputValue();
    await submit(author,item.js,()=>author.getByRole('button',{name:'Beitrag veröffentlichen',exact:true}).click());saves++;
    await author.waitForFunction(v=>document.querySelector('input[name=operation][value="article-save"]')?.form.querySelector('[name=version]')?.value!==v,v);
    const publishedResponse=await author.request.get(origin+'/'+item.slug+'/');const published=await publishedResponse.text();check(publishedResponse.status()===200&&(await author.evaluate(html=>new DOMParser().parseFromString(html,'text/html').body.textContent,published)).includes(item.body),'actual conscious local publication retains its original public body');
    const excerpt='Private neue Kurzfassung '+item.id;
    await form(author,'article-save').getByLabel('Kurztext für die Übersicht',{exact:true}).fill(excerpt);
    const editVersion=await form(author,'article-save').locator('[name=version]').inputValue();
    await submit(author,item.js,()=>form(author,'article-save').getByRole('button',{name:'Entwurf speichern',exact:true}).click());saves++;
    await author.waitForFunction(v=>document.querySelector('input[name=operation][value="article-save"]')?.form.querySelector('[name=version]')?.value!==v,editVersion);
    check(await author.getByRole('button',{name:'Beitrag veröffentlichen',exact:true}).isDisabled(),'new actual content invalidates approval');
    check(await (await author.request.get(origin+'/'+item.slug+'/')).text()===published,'private edit preserves exact published original');
    const old=await reviewer.request.post(url,{headers:{Accept:'application/json'},form:Object.fromEntries(oldApproval)});
    check([409,422].includes(old.status()),'old reviewer form cannot approve later content');
    await reviewer.reload();await submit(reviewer,item.js,()=>review(reviewer).getByRole('button',{name:'Redaktionell freigeben',exact:true}).click());saves++;
    await reviewer.waitForFunction(()=>document.querySelector('[data-four-eyes=approved]'));
    await author.reload();check(await author.getByRole('button',{name:'Beitrag veröffentlichen',exact:true}).isEnabled(),'new exact content can receive independent approval');
    check(await fits(author),'author editor fits all tested widths');
    if(item.width===390&&item.js){await author.evaluate(()=>document.documentElement.style.zoom='2');check(await fits(author),'200 percent zoom keeps publication controls reachable');}
    if(!item.js)check(await review(author).locator('[data-four-eyes=approved]').count()===1,'complete review workflow works without JavaScript');
  }
  await rule(settings,false);await rule(settings,true);
  const {page:reviewer}=await context('editor',true,320);const item=fixture.items[0];
  await reviewer.goto(origin+'/vereinsverwaltung/?view=website&tab=news&native_item='+item.id);
  const renew=review(reviewer).getByRole('button',{name:'Erneut freigeben',exact:true});check(await renew.count()===1,'policy change offers approval renewal without fake content edit');
  await renew.tap();saves++;await reviewer.waitForFunction(()=>document.querySelector('[data-four-eyes=approved]'));
  check(await fits(reviewer),'renewal fits smallest phone');
  await rule(settings,fixture.initial.enabled);
  check(errors.length===0,'no browser script errors');check(external.length===0,'no external requests or provider dispatch');
  console.log(JSON.stringify({passed:checks,private_saves:saves,roles:2,widths:[1440,820,390,320],no_javascript:true,live_transport:false}));
} finally {for(const c of contexts)await c.close();await browser.close();}
