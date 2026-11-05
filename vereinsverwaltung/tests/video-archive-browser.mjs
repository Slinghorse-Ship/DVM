import fs from 'node:fs/promises';
import assert from 'node:assert/strict';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE);
const f=JSON.parse(await fs.readFile(process.env.DVM_VIDEO_ARCHIVE_FIXTURE,'utf8'));
const browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE,args:['--no-sandbox','--disable-dev-shm-usage']});
let checks=0,posts=0;const errors=[],outside=[],videoRequests=[];
const check=(ok,label)=>{assert.ok(ok,label);checks++;console.log('PASS '+label);};
const url=f.origin+'/?view=social&section=compose&item='+f.item+'&video_page=1';
const form=page=>page.locator('.social-video-archive-actions form').filter({has:page.locator('input[name=source_id][value="'+f.source+'"]')});
const tools=async page=>{const t=form(page).locator("xpath=ancestor::details[contains(@class,'social-video-card-tools')]");if(!await t.getAttribute("open"))await t.locator(":scope > summary").click();};
const panel=page=>page.locator('#social-videos');
const select=async(page,archived)=>{await panel(page).getByRole('link',{name:new RegExp('^'+(archived?'Archiv':'Aktuell')+' ·')}).click();await page.waitForFunction(state=>new URL(location.href).searchParams.get('video_archive')===state,archived?'1':'0');};
const fit=async(page)=>{
 const r=await panel(page).evaluate(root=>({right:root.getBoundingClientRect().right,width:document.documentElement.clientWidth,overflow:root.scrollWidth>root.clientWidth+2,small:[...root.querySelectorAll('.social-video-archive-actions button,.social-video-archive-actions a,.social-video-pager a,.social-video-archive-history summary')].filter(e=>e.getClientRects().length&&e.getBoundingClientRect().height>0).filter(e=>e.getBoundingClientRect().height<44||parseFloat(getComputedStyle(e).fontSize)<16).map(e=>e.textContent)}));
 check(r.right<=r.width+2&&!r.overflow,'video archive fits current viewport '+JSON.stringify(r));check(!r.small.length,'archive touch targets remain readable '+JSON.stringify(r));
};
try{
 for(const js of [true,false])for(const width of [320,390,820])for(const zoom of [1,2]){
  const context=await browser.newContext({viewport:{width,height:1100},javaScriptEnabled:js,hasTouch:true});const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
  context.on('request',r=>{if(!r.url().startsWith(f.origin+'/'))outside.push(r.url());if(r.url().includes('/social-video/'))videoRequests.push(r.url());});
  await page.goto(url);if(zoom===2)await page.evaluate(()=>document.documentElement.style.fontSize='200%');await fit(page);
  check(await panel(page).locator('.social-video-grid > article').count()===6,'active list is limited to six original versions');
  check(await panel(page).locator('.social-video-player[open]').count()===0,'video players start closed');
  check(await panel(page).locator('.social-video-card-tools[open]').count()===0,'all original rows start compact');
  const height=await panel(page).evaluate(e=>e.getBoundingClientRect().height);
  check(height<(zoom===2?1800:1100),'six original rows stay compact at '+width+'px/'+zoom+'x: '+height);
  const first=panel(page).locator('.social-video-card-tools').nth(0),second=panel(page).locator('.social-video-card-tools').nth(1);
  await first.locator(':scope > summary').focus();await page.keyboard.press('Enter');check(await first.getAttribute('open')!==null,'keyboard opens the chosen original row');
  await second.locator(':scope > summary').focus();await page.keyboard.press('Enter');check(await first.getAttribute('open')===null&&await second.getAttribute('open')!==null,'only one chosen original opens at a time');
  await page.keyboard.press('Enter');check(await second.getAttribute('open')===null,'keyboard closes the same original without a write');
  await select(page,true);await fit(page);check((await panel(page).textContent()).includes('Keine archivierten Videos.'),'empty archive is available without hiding current source');
  await context.close();
 }
 for(const js of [true,false]){
  const context=await browser.newContext({viewport:{width:390,height:900},hasTouch:true,javaScriptEnabled:js});const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));context.on('request',r=>{if(r.method()==='POST')posts++;});
  await page.goto(url);if(!await form(page).count()){await panel(page).getByRole('link',{name:'Weitere Videos',exact:true}).first().click();await form(page).waitFor({state:"attached"});}
  await tools(page);
  if(js){await form(page).locator('[data-archive-save]').tap();await form(page).locator('..').locator('[data-archive-status]').filter({hasText:'Archivwahl gespeichert.'}).waitFor();await select(page,true);}
  else{const popup=page.waitForEvent('popup');await form(page).locator('[data-archive-save]').tap();const saved=await popup;await saved.waitForLoadState('load');const receipt=JSON.parse(await saved.locator('[data-archive-confirmed]').getAttribute('data-archive-confirmed'));check(receipt.archived,'NoJS original archive save is confirmed in its own window');await saved.close();await page.goto(url+'&video_archive=1');}
  await tools(page);
  check(await form(page).getByRole('button',{name:'Video zurückholen',exact:true}).count()===1,'actual original archive form selects its short archive tab');
  check(await panel(page).getByRole('button',{name:'Diese Fassung freigeben',exact:true}).count()===0,'archived original offers no new approval');await fit(page);
  if(js){
   const player=panel(page).locator('.social-video-player').first();await player.locator('summary').tap();await player.locator('video').evaluate(video=>video.play());
   check(await player.locator('video').evaluate(video=>!video.paused),'deliberately opened original plays its actual AVC/AAC file');
   await panel(page).locator('.social-video-card-tools > summary').first().tap();await page.waitForFunction(()=>document.querySelector('#social-videos video')?.paused===true);
   check(await player.locator('video').evaluate(video=>video.paused),'closing original tools stops hidden video playback');await tools(page);
  }
  const history=form(page).locator("xpath=ancestor::div[contains(@class,'social-video-archive-actions')]").locator('.social-video-archive-history');await history.locator('summary').click();await history.getByRole('link',{name:'Verlauf öffnen',exact:true}).click();await page.waitForFunction(()=>new URL(location.href).searchParams.get('video_archive_source')!==null);
  check((await panel(page).locator('.social-video-archive-history[open]').textContent()).includes('Archiviert'),'explicit original history shows actual archive record');
  const original=await context.request.get(f.origin+'/social-video/'+f.video);check(original.status()===200&&(await original.body()).length===60207,'original private MP4 remains downloadable while archived');
  if(js){await form(page).locator('[data-archive-save]').tap();await form(page).locator('..').locator('[data-archive-status]').filter({hasText:'Archivwahl gespeichert.'}).waitFor();await select(page,false);}
  else{const popup=page.waitForEvent('popup');await form(page).locator('[data-archive-save]').tap();const saved=await popup;await saved.waitForLoadState('load');const receipt=JSON.parse(await saved.locator('[data-archive-confirmed]').getAttribute('data-archive-confirmed'));check(!receipt.archived,'NoJS original source restore is confirmed in its own window');await saved.close();await page.goto(url+'&video_archive=0');}
  check((await panel(page).textContent()).includes('Aktuell · 7'),'actual return restores all seven current original versions');await context.close();
 }
 check(posts===4,'actual archive and return forms make exactly four explicit POSTs');check(!outside.length,'layout checks contact no external service');check(!videoRequests.length,'closed lists do not download any MP4');check(!errors.length,'no browser errors '+errors.join(';'));
 await fs.mkdir('.local/browser-tests/video-archive',{recursive:true});const context=await browser.newContext({viewport:{width:390,height:844}});const page=await context.newPage();await page.goto(url);await panel(page).screenshot({path:'.local/browser-tests/video-archive/mobile.png'});await context.close();
 console.log(JSON.stringify({suite:'video-archive-browser',checks,posts,status:'passed',live_transport:false}));
}finally{await browser.close();}
