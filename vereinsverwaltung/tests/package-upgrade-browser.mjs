import fs from 'node:fs';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || '/workspace/.setup/browser/node_modules/playwright/index.mjs');
const input = JSON.parse(fs.readFileSync(0, 'utf8'));
const browser = await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || '/usr/bin/chromium',headless:true,args:['--no-sandbox']});
let checks=0;
let realStepPosts=0,realStatusPosts=0;
const check=(ok,label)=>{if(!ok)throw new Error(label);checks++;};
async function ownRoute(context,loseStep=()=>false) {
  await context.route('https://upgrade.example/**',async route=>{
    const request=route.request(), url=new URL(request.url()), headers=await request.allHeaders();
    headers.host='upgrade.example';delete headers['content-length'];
    if(request.method()==='POST') { const data=new URLSearchParams(request.postData()||''); if(data.get('action')==='step')realStepPosts++; if(data.get('action')==='status')realStatusPosts++; }
    const response=await context.request.fetch(input.url+url.pathname+url.search,{method:request.method(),headers,data:request.postDataBuffer()||undefined,maxRedirects:0});
    if(await loseStep(request,response))await route.abort('connectionreset');else await route.fulfill({response});
  });
}
try {
  if(input.active) {
    const withoutJs=await browser.newContext({viewport:{width:390,height:850},javaScriptEnabled:false}); await ownRoute(withoutJs);
    const page=await withoutJs.newPage();await page.goto('https://upgrade.example/upgrade/');await page.locator('#upgrade-key').fill(input.token);await page.getByRole('button',{name:'Stand prüfen',exact:true}).click();
    check(await page.getByRole('button',{name:'Fortsetzen',exact:true}).count()===1,'no-JS status exposes continuation: '+await page.locator('#error').innerText());
    await page.getByRole('button',{name:'Fortsetzen',exact:true}).click();await page.waitForLoadState('networkidle');check((await page.locator('#progress').innerText()).includes('Bisheriges Paket prüfen'),'real no-JS original verification step works');await withoutJs.close();
    let lost=false,lostFinish=false;
    const context=await browser.newContext({viewport:{width:320,height:850}});await ownRoute(context,async(request,response)=>{if(request.method()!=='POST'||new URLSearchParams(request.postData()).get('action')!=='step')return false;if(!lost){lost=true;return true;}if(!lostFinish&&(await response.json()).complete){lostFinish=true;return true;}return false;});
    const active=await context.newPage();await active.goto('https://upgrade.example/upgrade/');await active.evaluate(()=>document.documentElement.style.fontSize='200%');await active.locator('#upgrade-key').fill(input.token);await active.getByRole('button',{name:'Stand prüfen',exact:true}).click();await active.waitForFunction(()=>document.querySelector('#continue').value==='step'&&!document.querySelector('button[value=status]').disabled);
    await active.getByRole('button',{name:'Fortsetzen',exact:true}).click();await active.waitForFunction(()=>document.querySelector('#error').textContent.length>0);check(lost,'actual accepted original step response can be lost');
    await active.getByRole('button',{name:'Stand prüfen',exact:true}).click();await active.waitForFunction(()=>!document.querySelector('button[value=status]').disabled&&document.querySelector('#error').textContent==='');
    await active.getByRole('button',{name:'Fortsetzen',exact:true}).click();await active.waitForFunction(()=>document.querySelector('#error').textContent.length>0,{},{timeout:180000});check(lostFinish,'actual accepted final upgrade response is lost');await active.locator('#upgrade-key').fill('');
    await active.getByRole('button',{name:'Stand prüfen',exact:true}).click();await active.waitForFunction(()=>document.querySelector('#progress').textContent==='Aktualisierung abgeschlossen',{},{timeout:30000});check(realStepPosts>10,'real mobile JS controller resumes and completes all actual upgrade steps');check(await active.locator('#error').innerText()==='','original authenticated session checks lost final result without duplicate upgrade');await context.close();
  }
  for (const width of [320,390,820]) for (const zoom of [1,2]) for (const js of [true,false]) {
    const context=await browser.newContext({viewport:{width,height:850},javaScriptEnabled:js});await ownRoute(context);
    const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));page.on('console',message=>{if(message.type()==='error')errors.push(message.text());});
    await page.goto('https://upgrade.example/upgrade/');
    if(zoom===2) await page.evaluate(()=>document.documentElement.style.fontSize='200%');
    await page.locator('#upgrade-key').fill(input.token);
    await page.getByRole('button',{name:'Stand prüfen',exact:true}).click();
    if(js) await page.waitForFunction(()=>document.querySelector('#progress').textContent==='Aktualisierung abgeschlossen'&&!document.querySelector('#upgrade-form button[value=status]').disabled);
    else await page.waitForLoadState('networkidle');
    if(zoom===2) await page.evaluate(()=>document.documentElement.style.fontSize='200%');
    check(await page.evaluate(()=>parseFloat(getComputedStyle(document.documentElement).fontSize))===zoom*16,'actual font size after JS/no-JS form navigation');
    check(await page.locator('#progress').innerText()==='Aktualisierung abgeschlossen','actual authenticated status '+width+'/'+zoom+'/'+js);
    const box=await page.locator('#upgrade-key').boundingBox();check(box.height>=44,'password touch size');
    const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1);check(!overflow,'no page overflow '+width+'/'+zoom+'/'+js);
    await page.locator('summary').click();
    const confirmation=await page.locator('.confirmation').boundingBox();check(confirmation.height>=44,'touch confirmation row');
    await page.keyboard.press('Tab');check(await page.evaluate(()=>document.activeElement?.name==='confirm'),'keyboard reaches real confirmation');
    check(errors.length===0,'no browser errors');
    await context.close();
  }
  console.log(JSON.stringify({browser_checks:checks,real_step_posts:realStepPosts,real_status_posts:realStatusPosts,widths:[320,390,820],font_scale:[1,2],javascript:[true,false]}));
} finally {await browser.close();}
