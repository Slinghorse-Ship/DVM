import fs from 'node:fs/promises';
import assert from 'node:assert/strict';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE);
const fixture=JSON.parse(await fs.readFile(process.env.DVM_PRESS_RECIPIENTS_FIXTURE,'utf8'));
const browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE,args:['--no-sandbox','--disable-dev-shm-usage']});
let checks=0,posts=0;const errors=[],outside=[];
const check=(ok,label)=>{assert.ok(ok,label);checks++;console.log('PASS '+label);};
const track=page=>{page.on('pageerror',e=>errors.push(e.message));page.on('request',r=>{if(r.method()==='POST')posts++;if(!r.url().startsWith(fixture.origin+'/'))outside.push(r.url());});page.on('dialog',d=>d.type()==='beforeunload'?d.accept():d.dismiss());};
const enter=async(page,mode)=>{const response=await page.goto(fixture.origin+'/own-test-enter?mode='+mode+'&module=press');check(response.status()===200,'actual own '+mode+' press controller renders');};
const url=(mode,query)=>fixture.origin+(mode==='native'?'/?':'/vereinsverwaltung/?')+new URLSearchParams({view:'press',...query});
const fit=async(page,selector,label)=>{
 const metrics=await page.locator(selector).evaluate(root=>({right:root.getBoundingClientRect().right,width:document.documentElement.clientWidth,overflow:root.scrollWidth>root.clientWidth+2,small:[...root.querySelectorAll('button,input:not([type=hidden]):not([type=checkbox]),select,summary,a.btn')].filter(n=>n.getClientRects().length&&n.getBoundingClientRect().height>0).filter(n=>n.getBoundingClientRect().height<43.5||parseFloat(getComputedStyle(n).fontSize)<16).map(n=>({name:n.name||n.textContent.slice(0,40),height:n.getBoundingClientRect().height,font:getComputedStyle(n).fontSize}))}));
 check(metrics.right<=metrics.width+2&&!metrics.overflow,'press view fits '+label+' '+JSON.stringify(metrics));check(!metrics.small.length,'press controls meet touch sizing '+label+' '+JSON.stringify(metrics.small));
};
const submit=async(page,button,status=200)=>{
 const response=page.waitForResponse(r=>r.request().method()==='POST');await button.click();const r=await response;check(r.status()===status||(status===200&&r.status()===303),'explicit own press action succeeds with its normal HTML or JSON response');await page.waitForLoadState('load');return r;
};
try{
 for(const mode of ['native','integrated'])for(const js of [true,false])for(const width of [320,390,820])for(const zoom of [1,2]){
  const context=await browser.newContext({viewport:{width,height:1100},javaScriptEnabled:js,hasTouch:true});const page=await context.newPage();track(page);await enter(page,mode);
  await page.goto(url(mode,{section:'planning',press_item:fixture.item}));if(zoom===2)await page.locator('.press-deliveries').evaluate(n=>n.ownerDocument.documentElement.style.fontSize='200%');
  check(await page.locator('.press-delivery').count()===6,'eighteen actual recipient originals show only six rows');check(await page.locator('.press-delivery details[open]').count()===0,'all individual delivery details start compact');
  const first=page.locator('.press-delivery details').first();await first.locator('summary').focus();await page.keyboard.press('Enter');check(await first.getAttribute('open')!==null,'keyboard opens chosen delivery original');await fit(page,'.press-deliveries',mode+'/'+js+'/'+width+'/'+zoom);
  await page.goto(url(mode,{section:'compose',[mode==='native'?'item':'native_item']:fixture.item}));if(zoom===2)await page.locator('.press-send-planning').evaluate(n=>n.ownerDocument.documentElement.style.fontSize='200%');await fit(page,'.press-send-planning',mode+'/planning/'+js+'/'+width+'/'+zoom);
  await context.close();
 }
 for(const mode of ['native','integrated'])for(const js of [true,false]){
  const context=await browser.newContext({viewport:{width:390,height:1000},javaScriptEnabled:js,hasTouch:true});const page=await context.newPage();track(page);await enter(page,mode);
  const form=()=>page.locator('form').filter({has:page.locator('[name=operation][value=communication-save]')});
  await form().locator('[name=title]').fill('Browser human '+mode+' '+js);await form().locator('[name=summary]').fill('Original browser human introduction');
  if(js)await form().locator('[contenteditable=true]').first().fill('Original human browser press text ä🙂');else await form().locator('textarea[name=body_html]').fill('<p>Original human browser press text ä🙂</p>');
  const settings=page.locator('.compose-settings');await settings.locator(':scope > summary').click();const picker=page.locator('[data-press-picker]');await picker.locator(':scope > summary').click();
  await picker.locator('[name="press_choice[]"]').nth(0).check();await picker.locator('[name="press_choice[]"]').nth(1).check();
  await submit(page,form().getByRole('button',{name:'Entwurf speichern',exact:true}).first());await page.waitForFunction(mode=>new URL(location.href).searchParams.get(mode==='native'?'item':'native_item')?.length===32,mode);
  const id=new URL(page.url()).searchParams.get(mode==='native'?'item':'native_item');check(await form().locator('[name=version]').inputValue()==='1','actual human draft creates exactly its first revision');
  const approve=page.locator('form').filter({has:page.locator('[name=operation][value=communication-approve]')});await submit(page,approve.getByRole('button',{name:'Freigeben',exact:true}));
  const schedule=()=>page.locator('form').filter({has:page.locator('[name=operation][value=communication-schedule]')});await schedule().waitFor();check(await schedule().locator('[name=version]').inputValue()==='2','actual approval pins the second original revision');
  await schedule().locator('[name=scheduled_at]').fill('2050-10-25T19:30');await schedule().locator('[name=version]').evaluate(input=>input.value='1');await submit(page,schedule().getByRole('button',{name:'Versand einplanen',exact:true}),422);
  check(await schedule().locator('[name=version]').inputValue()==='1'&&await schedule().locator('[name=scheduled_at]').inputValue()==='2050-10-25T19:30','actual rejected schedule retains the human date and its submitted stale version '+mode+'/'+js);
  await page.goto(url(mode,{section:'compose',[mode==='native'?'item':'native_item']:id}));await schedule().locator('[name=scheduled_at]').fill('2050-10-25T19:30');await submit(page,schedule().getByRole('button',{name:'Versand einplanen',exact:true}));
  await page.waitForFunction(()=>!document.querySelector('[name=operation][value=communication-schedule]'));
  await page.goto(url(mode,{section:'planning',press_item:id}));check(await page.locator('.press-delivery').count()===2&&await page.locator('.press-delivery .badge').evaluateAll(nodes=>nodes.every(n=>n.textContent==='Vorgemerkt')),'actual scheduling queues exactly two own recipients without sending SMTP');
  check(await page.locator('.press-delivery').first().textContent().then(t=>t.includes('Browser human '+mode+' '+js)&&t.includes('Fassung 3')),'individual delivery history shows its exact human source and third revision');await fit(page,'.press-deliveries',mode+'/complete/'+js);
  await page.goto(url(mode,{section:'planning',press_item:fixture.item}));const next=page.locator('.press-delivery-pages').getByRole('link',{name:'Weiter'});await next.click();await page.waitForURL(u=>u.searchParams.get('press_page')==='2');check(await page.locator('.press-delivery').count()===6,'next history page preserves item filter and another six exact originals');
  await context.close();
 }
 check(!outside.length,'all real controller requests stay in the owned loopback fixture');check(!errors.length,'no actual browser exceptions '+errors.join(';'));
 console.log(JSON.stringify({suite:'press-delivery-browser',checks,posts,live_transport:false,status:'passed'}));
}finally{await browser.close();}
