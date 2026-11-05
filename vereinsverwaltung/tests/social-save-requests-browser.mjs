import assert from 'node:assert/strict';
import {readFile,writeFile} from 'node:fs/promises';
const chunks=[];for await(const chunk of process.stdin)chunks.push(chunk);const f=JSON.parse(Buffer.concat(chunks));assert.match(f.origin,/^http:\/\/127\.0\.0\.1:[0-9]+$/);
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE||'/usr/bin/chromium',args:['--no-sandbox']});
let checks=0,views=0,posts=0,external=0,active;const errors=[],saves=[];
const check=(ok,label)=>{assert.ok(ok,label);checks++;};
const form=p=>p.locator('.compose-paper form').first();
const current=p=>form(p).locator('[name=version]');
const url=(mode,id='')=>f.origin+(mode==='integrated'?'/vereinsverwaltung/':'/')+'?'+new URLSearchParams({view:'social',section:'compose',...(id?{[mode==='integrated'?'native_item':'item']:id}:{})});
async function fit(p,label){
 for(const width of [390,820])for(const size of [16,32]){await p.setViewportSize({width,height:1000});await p.evaluate(size=>document.documentElement.style.fontSize=size+'px',size);check(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'editor fits phone/tablet and doubled font '+label+' '+width+'/'+size);check(await form(p).locator('button').evaluateAll(nodes=>nodes.filter(n=>n.getClientRects().length&&getComputedStyle(n).visibility!=='hidden').every(n=>n.getBoundingClientRect().height>=43.5)),'visible editor actions retain touch height '+label);views++;}
 await p.evaluate(()=>document.documentElement.style.fontSize='');await p.setViewportSize({width:390,height:1000});
}
async function filled(p,js,text){if(js)await form(p).locator('[data-richtext-surface]').fill(text);else await form(p).locator('textarea[name=body_html]:visible').fill('<p>'+text+'</p>');}
async function step(p,selector){await Promise.all([p.waitForNavigation({waitUntil:'load'}),form(p).locator(selector).click()]);await form(p).waitFor();}
async function save(p,locale,expected){await form(p).getByRole('button',{name:locale==='en'?'Save draft':'Entwurf speichern',exact:true}).first().click();await p.waitForFunction(expected=>[...document.forms].find(n=>n.elements.namedItem('operation')?.value==='communication-save')?.elements.namedItem('version')?.value===String(expected),expected);}
try{
const smoke=process.env.DVM_SOCIAL_SAVE_SMOKE==='1';
for(const mode of smoke?['integrated']:['native','integrated'])for(const locale of smoke?['en']:['de','en'])for(const js of smoke?[true]:[true,false]){
 const context=await browser.newContext({javaScriptEnabled:js,hasTouch:true,viewport:{width:390,height:1000},serviceWorkers:'block'});context.setDefaultNavigationTimeout(60000);context.setDefaultTimeout(45000);
 await context.route('**/*',async route=>{const request=route.request();if(new URL(request.url()).origin!==f.origin){external++;return route.abort();}if(request.method()==='POST')posts++;return route.continue();});
 const p=await context.newPage();active=p;p.on('pageerror',error=>errors.push(error.message));await p.goto(f.origin+'/own-test-enter?'+new URLSearchParams({mode,locale}));
 for(const kind of smoke?['post','story']:['post','story','reel','carousel']){
  console.log(JSON.stringify({social_save_start:{mode,locale,js,kind}}));await p.goto(url(mode));await form(p).waitFor();check(await p.locator('html').getAttribute('lang')===locale,'actual personal language '+mode+'/'+locale);
  const key=await form(p).locator('[name=save_request_key]').inputValue();check(/^[a-f0-9]{32}$/.test(key),'new real form owns a save key');
  const title='0 Own '+mode+'/'+locale+'/'+js+'/'+kind+' Titel Suchen ä🙂 <literal>',body='0 Own exact social body Text ä🙂';
  await form(p).locator('[name=title]').fill(title);await form(p).locator('[name=summary]').fill('0 Own exact image caption');await filled(p,js,body);
  await form(p).locator('.compose-settings>summary').click();await form(p).locator('select[name=kind]').selectOption(kind);
  if(!js&&kind!=='post'){await step(p,'[name=social_format]');check(await form(p).locator('[name=save_request_key]').inputValue()===key&&await form(p).locator('[name=title]').inputValue()===title,'NoJS format change preserves human input and key');}
  if(kind==='story'||kind==='carousel'){
   if(js){const root=form(p).locator('[data-kh-editor="'+(kind==='story'?'story':'carousel')+'"]');if(kind==='story')await root.locator('[data-editor-add=slide]').click();await root.locator('[data-editor-field=title]:visible').fill('Own first '+kind);await root.locator('[data-editor-field=text]:visible').fill('0 Own first text');if(kind==='story')await root.locator('[data-editor-add=slide]').click();else await root.locator('[data-editor-step="1"]').click();await root.locator('[data-editor-field=title]:visible').fill('Own second '+kind);await root.locator('[data-editor-field=text]:visible').fill('0 Own second text ä🙂');}
   else{const prefix=kind==='story'?'story_slides':'carousel_cards';await form(p).locator('[name="'+prefix+'[0][title]"]').fill('Own first '+kind);await form(p).locator('[name="'+prefix+'[0][text]"]').fill('0 Own first text');if(kind==='story')await step(p,'[name=story_browse][value=add]');await form(p).locator('[name="'+prefix+'[1][title]"]').fill('Own second '+kind);await form(p).locator('[name="'+prefix+'[1][text]"]').fill('0 Own second text ä🙂');await step(p,'[name='+ (kind==='story'?'story_browse':'carousel_browse')+'][value="up:1"]');check(await form(p).locator('[name="'+prefix+'[0][title]"]').inputValue()==='Own second '+kind&&await form(p).locator('[name=save_request_key]').inputValue()===key,'actual NoJS reordering preserves original slide text and form key');}
  }
  if(!smoke&&kind==='post'){
   await form(p).locator('[name=title]').fill('0 Own deliberate social storage failure');
   const failure=p.waitForResponse(r=>r.request().method()==='POST'&&new URL(r.url()).origin===f.origin);
   if(js)await form(p).getByRole('button',{name:locale==='en'?'Save draft':'Entwurf speichern',exact:true}).first().click();
   else await Promise.all([p.waitForNavigation({waitUntil:'load'}),form(p).getByRole('button',{name:locale==='en'?'Save draft':'Entwurf speichern',exact:true}).first().click()]);
   const response=await failure;await p.getByText(locale==='en'?'Saving was not confirmed. Your input is retained. Check the saved version or retry saving the unchanged input.':'Speicherung nicht bestätigt. Deine Angaben bleiben erhalten. Gespeicherten Stand prüfen oder unverändert erneut speichern.',{exact:true}).first().waitFor();
   check(response.status()===500&&await form(p).locator('[name=save_request_key]').inputValue()===key&&await form(p).locator('[name=title]').inputValue()==='0 Own deliberate social storage failure'&&await form(p).locator('[name=summary]').inputValue()==='0 Own exact image caption','actual SQL failure retains the whole editable form with localized recovery guidance');await form(p).locator('[name=title]').fill(title);
  }
  if(!smoke){
   await form(p).locator('[name=title]').fill('');await form(p).evaluate(node=>node.noValidate=true);
   const response=p.waitForResponse(r=>r.request().method()==='POST'&&new URL(r.url()).origin===f.origin);
   if(js){await form(p).getByRole('button',{name:locale==='en'?'Save draft':'Entwurf speichern',exact:true}).first().click();await response;await p.getByText(locale==='en'?'A required field is missing.':'Ein Pflichtfeld fehlt.',{exact:true}).first().waitFor();}
   else{await Promise.all([p.waitForNavigation({waitUntil:'load'}),form(p).getByRole('button',{name:locale==='en'?'Save draft':'Entwurf speichern',exact:true}).first().click()]);await response;await p.getByText(locale==='en'?'A required field is missing.':'Ein Pflichtfeld fehlt.',{exact:true}).first().waitFor();}
   check(await form(p).locator('[name=save_request_key]').inputValue()===key&&await form(p).locator('[name=title]').inputValue()===''&&await form(p).locator('[name=summary]').inputValue()==='0 Own exact image caption','actual rejected save retains key and complete input for correction');await form(p).locator('[name=title]').fill(title);
  }
  const fields=await form(p).evaluate(n=>[...new FormData(n)].filter(([,v])=>typeof v==='string'));
  await fit(p,mode+'/'+locale+'/'+js+'/'+kind);
  if(js){
   let committed=0,resolve;const finished=new Promise(done=>resolve=done);let deadline;
   await p.route('**/*',async route=>{if(route.request().method()==='POST'&&route.request().postData()?.includes('communication-save')){const response=await route.fetch({timeout:60000,maxRedirects:0});committed=response.status();await response.dispose();await route.abort('failed');resolve();}else await route.continue();});
   await form(p).getByRole('button',{name:locale==='en'?'Save draft':'Entwurf speichern',exact:true}).first().tap();try{await Promise.race([finished,new Promise((_,reject)=>deadline=setTimeout(()=>reject(new Error('Actual accepted save deadline exceeded')),65000))]);}finally{clearTimeout(deadline);}
   const status=mode==='integrated'?form(p).locator('[data-save-status]'):p.locator('#notice');await status.waitFor({state:'visible'});await p.waitForFunction(({mode,word})=>{const node=mode==='integrated'?document.querySelector('[data-save-status]'):document.querySelector('#notice');return node?.textContent.includes(word);},{mode,word:locale==='en'?'Your input is retained':'Deine Angaben bleiben erhalten'});
   check(committed===200&&await form(p).locator('[name=title]').inputValue()===title&&await form(p).locator('[name=save_request_key]').inputValue()===key,'actual committed save with lost HTTP retains original text and key');await p.unroute('**/*');
  }
  await save(p,locale,1);const id=await form(p).locator('[name=id]').inputValue();check(/^[a-f0-9]{32}$/.test(id)&&await form(p).locator('[name=title]').inputValue()===title,'first original saved once after conscious create/retry');
  const replay=await context.request.post(url(mode),{data:new URLSearchParams(fields).toString(),headers:{'Content-Type':'application/x-www-form-urlencoded',Accept:'application/json',Origin:f.origin},maxRedirects:0});check(replay.status()===200,'actual original HTTP input replays without new save');
  await p.reload();check(await current(p).inputValue()==='1','repeated original request creates no second revision');
  const editKey=await form(p).locator('[name=save_request_key]').inputValue();check(editKey!==key,'next conscious edit receives a new key');await form(p).locator('[name=title]').fill(title+' later');await save(p,locale,2);
  await form(p).locator('[name=save_request_key]').evaluate((node,key)=>node.value=key,key);await form(p).locator('[name=title]').fill('0 Own unsaved text remains');
  let lookup=p;if(js){await form(p).getByRole('button',{name:locale==='en'?'Check saved version':'Gespeicherten Stand prüfen',exact:true}).click();await form(p).locator('[data-save-original]').waitFor({state:'visible'});}
  else{const opened=p.waitForEvent('popup');await form(p).getByRole('button',{name:locale==='en'?'Check saved version':'Gespeicherten Stand prüfen',exact:true}).click();lookup=await opened;await lookup.waitForLoadState('load');await lookup.locator('[data-save-original]').waitFor();}
  check(await form(p).locator('[name=title]').inputValue()==='0 Own unsaved text remains'&&await current(p).inputValue()==='2','read-only original lookup keeps the current unsaved input');const link=lookup===p?form(p).locator('[data-save-original]'):lookup.locator('a[data-save-original][href*="revision="]');check(await link.count()===1,'original editorial result has one precise immutable revision link');check(new URL(await link.getAttribute('href'),f.origin).searchParams.get('revision')==='1','lookup links to first immutable version despite later edits');if(lookup!==p)await lookup.close();
  saves.push({mode,locale,js,kind,id,key,edit_key:editKey,title,body});console.log(JSON.stringify({social_save_profile:{mode,locale,js,kind,checks,views}}));
 }
 await context.close();
}
check(external===0&&errors.length===0,'no external transport or browser errors');const result={checks,views,posts,external_actions:external,browser_errors:errors,saves};await writeFile(f.root+'/social-save-results.json',JSON.stringify(result,null,2)+'\n');console.log(JSON.stringify({social_save_browser:{checks,views,posts,original_drafts:saves.length,external_actions:external,browser_errors:errors}}));
}catch(error){if(active&&!active.isClosed()){await active.screenshot({path:f.root+'/social-save-failure.png'}).catch(()=>{});await active.content().then(html=>writeFile(f.root+'/social-save-failure.html',html)).catch(()=>{});}throw error;}finally{await browser.close();}
