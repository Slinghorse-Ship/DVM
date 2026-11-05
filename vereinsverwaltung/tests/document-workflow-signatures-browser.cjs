'use strict';
// Only the gated loopback preview with synthetic requests from the HTTP suite.
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {chromium}=require('C:/Users/wehla/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const base='http://127.0.0.1:8117/workflows',fixture=JSON.parse(fs.readFileSync('.local/document-workflows-20261007/signature-browser.json','utf8'));
const checks=[],errors=[],blocked=[];
const check=(ok,label)=>{assert(ok,label);checks.push(label);};
(async()=>{
 const browser=await chromium.launch({headless:true});
 const ctx=await browser.newContext({viewport:{width:1440,height:1000},acceptDownloads:true});
 await ctx.route('**/*',route=>{const u=new URL(route.request().url());if(u.origin!==new URL(base).origin){blocked.push(u.href);return route.abort();}return route.continue();});
 const page=await ctx.newPage();page.on('pageerror',error=>errors.push(error.message));
 const output=path.resolve('output/pdf');fs.mkdirSync(output,{recursive:true});
 const role=async id=>{
  await page.locator('.dwf-demo-header select[name=actor]').selectOption(id);
  await Promise.all([page.waitForURL(url=>url.searchParams.get('view')==='documents'&&!url.searchParams.has('wf_sign')),page.getByRole('button',{name:'Rolle wechseln',exact:true}).click()]);
 };
 try{
  await page.goto(base);await role('wf-reader');
  await page.goto(base+'?wf_own=1');await page.locator('.dwf-own-signatures a').first().click();
  const canvas=page.locator('[data-dwf-signature-canvas]');await canvas.waitFor();
  check(await page.getByRole('heading',{name:'Meine Unterschriften',exact:true}).count()===1,'personal signing workspace shown');
  await page.locator('[name=wf_accepted]').check();
  await page.getByRole('button',{name:'Unterschreiben',exact:true}).click();
  check((await page.locator('[data-dwf-signature-error]').innerText()).includes('Bitte mit Finger'),'blank drawing receives inline message');
  const box=await canvas.boundingBox();
  const draw=async()=>{
   await page.mouse.move(box.x+30,box.y+120);await page.mouse.down();
   for(const [x,y] of [[60,20],[100,130],[140,50],[200,100],[280,30],[360,140],[440,60],[540,110]])await page.mouse.move(box.x+x,box.y+y,{steps:3});
   await page.mouse.up();
  };
  await draw();
  check(await page.locator('[data-dwf-signature-form]').getAttribute('data-dwf-dirty')==='1','drawn signature participates in unsaved-change protection');
  await page.getByRole('button',{name:'Unterschrift neu schreiben',exact:true}).click();
  check(await page.locator('[data-dwf-signature-form]').getAttribute('data-dwf-dirty')===null,'clear resets drawing and unsaved status');
  await draw();
  // Repeated manager mounts must not reset an existing handwritten signature.
  await page.evaluate(()=>{document.dispatchEvent(new CustomEvent('manager:mounted',{detail:{root:document}}));});
  check(await page.locator('[data-dwf-signature-form]').getAttribute('data-dwf-dirty')==='1','lifecycle remount preserves handwriting');
  await page.locator('.dwf-card').screenshot({path:path.join(output,'gefaehrdungsbeurteilung-unterschrift-desktop.png')});
  await Promise.all([page.waitForURL(url=>url.searchParams.has('wf_sign')&&url.searchParams.has('view')),page.getByRole('button',{name:'Unterschreiben',exact:true}).click()]);
  check(await page.locator('[data-dwf-signature-canvas]').count()===0 && (await page.locator('.dwf-card').innerText()).includes('Unterschrieben von Demo · Lesezugang'),'desktop handwritten acknowledgement saves and locks');
  await role('wf-member');await page.setViewportSize({width:390,height:844});await page.goto(base+'?wf_sign='+fixture.request);await canvas.waitFor();
  check(await page.getByRole('link',{name:'Zur Dokumentübersicht',exact:true}).count()===0,'member without module rights has no global document link');
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'mobile personal form has no body overflow');
  const mobileBox=await canvas.boundingBox();
  const cdp=await ctx.newCDPSession(page);
  const touch=async(type,x,y)=>cdp.send('Input.dispatchTouchEvent',{type,touchPoints:type==='touchEnd'?[]:[{x,y,id:1}]});
  await canvas.scrollIntoViewIfNeeded();const touchBox=await canvas.boundingBox();
  await touch('touchStart',touchBox.x+20,touchBox.y+touchBox.height*.75);
  for(const [x,y] of [[.15,.2],[.25,.8],[.4,.3],[.55,.7],[.75,.2],[.9,.65]])await touch('touchMove',touchBox.x+touchBox.width*x,touchBox.y+touchBox.height*y);
  await touch('touchEnd');
  check(await page.locator('[data-dwf-signature-form]').getAttribute('data-dwf-dirty')==='1','touch handwriting captured without scrolling');
  await page.locator('[name=wf_accepted]').check();
  await page.locator('.dwf-card').screenshot({path:path.join(output,'gefaehrdungsbeurteilung-unterschrift-mobil.png')});
  await Promise.all([page.waitForURL(url=>url.searchParams.has('wf_sign')&&url.searchParams.has('view')),page.getByRole('button',{name:'Unterschreiben',exact:true}).click()]);
  check(await canvas.count()===0 && (await page.locator('.dwf-card').innerText()).includes('Unterschrieben von Mara Muster'),'touch acknowledgement saves under authenticated member name');
  const [download]=await Promise.all([page.waitForEvent('download'),page.getByRole('link',{name:'Beurteilung mit Anlagen als PDF lesen',exact:true}).click()]);
  await download.saveAs(path.join(output,'rwe-gefaehrdungsbeurteilung-online-unterschrieben.pdf'));
  check(fs.readFileSync(path.join(output,'rwe-gefaehrdungsbeurteilung-online-unterschrieben.pdf')).subarray(0,5).toString()==='%PDF-','member downloads own signed PDF');
  await role('wf-author');await page.goto(base+'?wf_doc='+fixture.document);
  check((await page.locator('.dwf-card').filter({has:page.getByRole('heading',{name:'Unterschriften der Mitglieder',exact:true})}).innerText()).includes('Mara Muster'),'writer sees member signing result');
  const button=page.locator('[data-dwf-signature-all]');await button.click();
  const boxes=page.locator('[name="wf_recipients[]"]');check(await boxes.count()>0 && await boxes.evaluateAll(nodes=>nodes.every(node=>node.checked)),'all available recipients can be selected together');
  await button.click();check(await boxes.evaluateAll(nodes=>nodes.every(node=>!node.checked)),'bulk selection can be undone without sending requests');
  check(errors.length===0,'no JavaScript errors');check(blocked.length===0,'no external requests');
  const report={suite:'document-workflow-signatures-browser',status:'passed',checks:checks.length,labels:checks,errors,blocked,production_changed:false,finished_at_utc:new Date().toISOString()};
  fs.writeFileSync('audit/document-workflow-signatures-browser-20261008.json',JSON.stringify(report,null,2));console.log(JSON.stringify(report,null,2));
 }finally{await ctx.close();await browser.close();}
})().catch(error=>{console.error(error);process.exit(1);});
