// Functional browser regression; no database mutation or external document fetch.
// Requires a local manager asset server and Playwright resolvable via NODE_PATH.
// PREVIEW_TEST_FIXTURE_DIR must contain synthetic-only index.html rendered from
// views/Documents/pdf-viewer.php and image-preview.php, a two-page synthetic.pdf, and a
// large (>1000px) synthetic.png. HTML must include #signature-test (unrelated
// canvas), manager style.css/admissions.css, and a <=600px preview container.
// The private fixture generator is intentionally not a deployed/public route.
// Optional PREVIEW_TEST_BASE_URL, PREVIEW_TEST_BROWSER_CHANNEL, PREVIEW_TEST_SCREENSHOT.
const {chromium}=require('playwright');const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const base=new URL(process.env.PREVIEW_TEST_BASE_URL||'http://127.0.0.1:8091/');
assert(['localhost','127.0.0.1','[::1]'].includes(base.hostname)&&base.protocol==='http:','Only an isolated localhost server is allowed');
const fixture=process.env.PREVIEW_TEST_FIXTURE_DIR;
assert(fixture,'Set PREVIEW_TEST_FIXTURE_DIR to synthetic index.html, two-page synthetic.pdf, and large synthetic.png');

(async()=>{const b=await chromium.launch({channel:process.env.PREVIEW_TEST_BROWSER_CHANNEL||'msedge',headless:true}),p=await b.newPage({viewport:{width:800,height:900},hasTouch:true});const errors=[];p.on('pageerror',e=>errors.push(e.message));await p.route('**/pan-fixture',r=>r.fulfill({contentType:'text/html',body:fs.readFileSync(path.join(fixture,'index.html'))}));for(const [file,type] of [['synthetic.pdf','application/pdf'],['synthetic.png','image/png']])await p.route('**/'+file,r=>r.fulfill({contentType:type,body:fs.readFileSync(path.join(fixture,file))}));await p.goto(new URL('pan-fixture',base).href);await p.locator('[data-pdf-next]:not([disabled])').waitFor();await p.locator('[data-pdf-zoom]').selectOption('2');await p.waitForFunction(()=>document.querySelector('.pdf-canvas-wrap canvas').width>1000);const wrap=p.locator('.pdf-canvas-wrap');await wrap.scrollIntoViewIfNeeded();await p.waitForFunction(()=>document.querySelector('.pdf-canvas-wrap').classList.contains('can-pan'));async function mousePan(w){const box=await w.boundingBox();await p.mouse.move(box.x+Math.min(280,box.width-30),box.y+Math.min(260,box.height-30));await p.mouse.down();await p.mouse.move(box.x+70,box.y+70,{steps:8});await p.mouse.up();assert(await w.evaluate(e=>e.scrollLeft>50&&e.scrollTop>50));}await mousePan(wrap);await p.locator('[data-pdf-next]').click();await p.waitForFunction(()=>document.querySelector('[data-pdf-page]').textContent.includes('2 von 2'));await p.locator('[data-pdf-prev]').click();await p.waitForFunction(()=>document.querySelector('[data-pdf-page]').textContent.includes('1 von 2'));await p.locator('[data-pdf-fit]').click();await p.waitForFunction(()=>document.querySelector('.pdf-canvas-wrap').scrollWidth<=document.querySelector('.pdf-canvas-wrap').clientWidth+1);
async function pinchTest(w,content){
 await w.scrollIntoViewIfNeeded();await w.evaluate(e=>{e.scrollLeft=0;e.scrollTop=0;});
 const box=await w.boundingBox(),session=await p.context().newCDPSession(p),x=box.x+Math.min(180,box.width/2),y=box.y+Math.min(180,box.height/2);
 const before=await content.evaluate((e,{x,y})=>{const r=e.getBoundingClientRect();return {width:r.width,u:(x-r.left)/r.width,v:(y-r.top)/r.height};},{x,y});
 await session.send('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[{id:1,x:x-30,y},{id:2,x:x+30,y}]});
 for(let i=1;i<=4;i++)await session.send('Input.dispatchTouchEvent',{type:'touchMove',touchPoints:[{id:1,x:x-30-i*12,y},{id:2,x:x+30+i*12,y}]});
 await p.waitForTimeout(700);
 assert(await content.evaluate((e,b)=>e.getBoundingClientRect().width>b.width*1.5,before),'two-finger zoom');
 const focal=await content.evaluate((e,{before,x,y})=>{const r=e.getBoundingClientRect();return {dx:r.left+before.u*r.width-x,dy:r.top+before.v*r.height-y};},{before,x,y});
 assert(Math.abs(focal.dx)<4&&Math.abs(focal.dy)<4,'pinch focal point '+JSON.stringify(focal));
 await session.send('Input.dispatchTouchEvent',{type:'touchEnd',touchPoints:[{id:2,x:x+78,y}]});
 const top=await w.evaluate(e=>e.scrollTop);
 await session.send('Input.dispatchTouchEvent',{type:'touchMove',touchPoints:[{id:1,x:x-98,y:y-30}]});
 await session.send('Input.dispatchTouchEvent',{type:'touchEnd',touchPoints:[]});
 await p.waitForTimeout(100);assert(await w.evaluate((e,t)=>e.scrollTop>=t+20,top),'one finger continues after pinch');
 assert.equal(await w.evaluate(e=>e.classList.contains('is-panning')),false);
 // Cancellation clears capture; subsequent gestures and controls remain usable.
 await session.send('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[{id:1,x,y}]});
 await session.send('Input.dispatchTouchEvent',{type:'touchCancel',touchPoints:[]});
 assert.equal(await w.evaluate(e=>e.classList.contains('is-panning')),false);
 const large=await content.evaluate(e=>e.getBoundingClientRect().width);
 await session.send('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[{id:5,x:x-65,y},{id:6,x:x+65,y}]});
 await session.send('Input.dispatchTouchEvent',{type:'touchMove',touchPoints:[{id:5,x:x-25,y},{id:6,x:x+25,y}]});
 await session.send('Input.dispatchTouchEvent',{type:'touchEnd',touchPoints:[]});
 await p.waitForTimeout(700);assert(await content.evaluate((e,width)=>e.getBoundingClientRect().width<width*.8,large),'pinch inward decreases scale');
}
await pinchTest(wrap,p.locator('.pdf-canvas-wrap canvas'));await p.locator('[data-pdf-fit]').click();
const iw=p.locator('.image-preview-scroll');await p.locator('[data-image-original]').click();await iw.scrollIntoViewIfNeeded();await mousePan(iw);await iw.evaluate(e=>{e.scrollLeft=0;e.scrollTop=0;});const box=await iw.boundingBox(),cdp=await p.context().newCDPSession(p);const x=box.x+250,y=box.y+220;await cdp.send('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[{x,y}]});for(let n=1;n<=5;n++)await cdp.send('Input.dispatchTouchEvent',{type:'touchMove',touchPoints:[{x:x-n*25,y:y-n*20}]});await cdp.send('Input.dispatchTouchEvent',{type:'touchEnd',touchPoints:[]});assert(await iw.evaluate(e=>e.scrollLeft>50&&e.scrollTop>50));await p.locator('[data-image-fit]').click();await pinchTest(iw,p.locator('.image-preview-scroll img'));assert.equal(await p.locator('#signature-test').getAttribute('data-pan-ready'),null);for(const button of await p.locator('[data-pdf-preview] button,[data-image-preview] button').all()){const r=await button.boundingBox();assert(r.height>=44&&r.width>=44);}await p.setViewportSize({width:390,height:844});await p.locator('[data-image-fit]').click();await p.locator('[data-pdf-fit]').click();await p.waitForTimeout(250);await pinchTest(wrap,p.locator('.pdf-canvas-wrap canvas'));await p.locator('[data-pdf-fit]').click();await pinchTest(iw,p.locator('.image-preview-scroll img'));await p.locator('[data-image-fit]').click();assert(await p.evaluate(()=>getComputedStyle(document.body).touchAction!=='none'));assert(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));assert.deepEqual(errors,[]);if(process.env.PREVIEW_TEST_SCREENSHOT)await p.screenshot({path:process.env.PREVIEW_TEST_SCREENSHOT});console.log('PASS PDF/image two-finger focal zoom, pinch-to-pan, cancel, mouse pan XY, touch XY, page next/previous, fit, 44px targets, mobile390, signature untouched, no page errors');await b.close()})().catch(e=>{console.error(e);process.exit(1)});
