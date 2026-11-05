import fs from 'node:fs';
const f=JSON.parse(fs.readFileSync(process.argv[2],'utf8'));
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE);
const browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE,headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
let checks=0,views=0,external=0,posts=0;const errors=[],assets=new Map();
const check=(ok,label)=>{if(!ok)throw Error(label);checks++;console.log('PASS '+label);};
async function layout(page,label){
 for(const [width,size] of [[320,16],[320,32],[390,16],[390,32],[1440,16],[1440,32]]){
  await page.setViewportSize({width,height:1000});await page.evaluate(s=>document.documentElement.style.fontSize=s+'px',size);
  const result=await page.locator('main').evaluate(el=>{
   const controls=[...el.querySelectorAll('a')].filter(x=>x.getClientRects().length);
   const lum=c=>{const rgb=c.match(/[0-9.]+/g).slice(0,3).map(Number).map(v=>{v/=255;return v<=.04045?v/12.92:((v+.055)/1.055)**2.4;});return .2126*rgb[0]+.7152*rgb[1]+.0722*rgb[2];};
   const contrast=[...controls,...el.querySelectorAll('.status-badge')].filter(x=>{let bg='rgba(0, 0, 0, 0)';for(let p=x;p&&bg==='rgba(0, 0, 0, 0)';p=p.parentElement)bg=getComputedStyle(p).backgroundColor;if(bg==='rgba(0, 0, 0, 0)')bg='rgb(255, 255, 255)';const a=lum(getComputedStyle(x).color),b=lum(bg);return(Math.max(a,b)+.05)/(Math.min(a,b)+.05)<4.5;}).map(x=>x.textContent);
   return {overflow:document.documentElement.scrollWidth>innerWidth+2,bad:controls.filter(x=>x.getBoundingClientRect().height<43||x.getBoundingClientRect().width<43).map(x=>x.textContent),contrast};
  });if(result.overflow){result.offenders=await page.locator('main').evaluate(el=>[...el.querySelectorAll('*')].filter(x=>x.getBoundingClientRect().right>innerWidth+2).map(x=>({tag:x.tagName,cls:x.className,text:x.textContent.slice(0,40),right:x.getBoundingClientRect().right})));await page.screenshot({path:f.root+'/public-status-failure.png',fullPage:true});}check(!result.overflow&&!result.bad.length&&!result.contrast.length,'public status layout/touch/contrast '+label+' '+width+'/'+size+' '+JSON.stringify(result));views++;
 }
 await page.setViewportSize({width:390,height:1000});await page.evaluate(()=>document.documentElement.style.fontSize='16px');
}
try{
 for(const js of[true,false]){
  const ctx=await browser.newContext({viewport:{width:390,height:1000},hasTouch:true,javaScriptEnabled:js,serviceWorkers:'block',locale:'en-US'});
  await ctx.route('**/*',async route=>{const req=route.request(),u=new URL(req.url());if(u.origin!==f.origin){external++;return route.abort();}if(req.method()!=='GET'){posts++;return route.abort();}if(/\.(?:css|js|mjs|woff2?|svg|png)$/.test(u.pathname)){let cached=assets.get(u.href);if(!cached){const r=await route.fetch();cached={status:r.status(),headers:r.headers(),body:await r.body()};assets.set(u.href,cached);}return route.fulfill(cached);}return route.continue();});
  const page=await ctx.newPage();page.on('pageerror',e=>errors.push(e.message));
  for(const [query,locale,title,unknown]of[['','de','Systemstatus','Status unbekannt'],['?lang=en','en','System status','Status unknown'],['?lang=en-gb','en-gb','System status','Status unknown'],['?lang=fr','fr','System status','Status unknown']]){
   const r=await page.goto(f.origin+'/status'+query);check(r.status()===200&&await page.locator('html').getAttribute('lang')===locale&&await page.locator('h1').textContent()===title,'anonymous public language '+locale+'/'+js);
   check(await page.locator('.status-row').count()===2&&(await page.locator('.status-badge').allTextContents()).includes(unknown),'only the two selected generic services, no invented green proof '+locale+'/'+js);
   check(await page.locator('nav a[aria-current=page]').count()===1&&await page.locator('nav a').count()===4&&!(await page.locator('body').textContent()).includes('Español'),'language menu has the current and only enabled public languages '+locale+'/'+js);
   check(await page.locator('form,input,script').count()===0,'public status has no forms secrets or scripts '+locale+'/'+js);
   check(await page.locator('main').getAttribute('lang')===(locale==='fr'?'en':locale),'fallback text declares its actual content language '+locale+'/'+js);
   const stamp=await page.locator('time').textContent();check((locale==='de'?/^[0-9]{2}\.[0-9]{2}\.[0-9]{4} /:/^[0-9]{2}\/[0-9]{2}\/[0-9]{4} /).test(stamp),'actual original timestamp follows the installed content language '+locale+'/'+js);
   await layout(page,locale+'/'+js);
  }
  await page.goto(f.origin+'/status');await page.locator('nav a[lang=en]').focus();await page.keyboard.press('Enter');await page.waitForURL('**/status?lang=en');check(await page.locator('h1').textContent()==='System status','keyboard language link opens actual English page '+js);
  await page.locator('footer a').click();check(new URL(page.url()).pathname==='/en/home'&&(await page.locator('body').textContent()).includes('Owned English home'),'return opens the actual published translated home '+js);
  for(const [query,code,label]of[['?lang=es',404,'Diese Website-Sprache ist nicht verfügbar.'],['?lang=xx',404,'Diese Website-Sprache ist nicht verfügbar.'],['?lang[]=en',400,'Ungültige Sprachauswahl.'],['?lang=en&lang=de',400,'Ungültige Sprachauswahl.']]){
   const r=await page.goto(f.origin+'/status'+query);check(r.status()===code&&await page.locator('[role=alert]').textContent()===label&&await page.locator('.status-row').count()===0,'public language error keeps services hidden '+query+'/'+js);await layout(page,'error'+query+'/'+js);
  }
  await page.goto(f.origin+'/en/status');check((await page.locator('body').textContent()).includes('Owned translated status article')&&await page.locator('.status-public').count()===0,'existing CMS translation remains reachable '+js);
  await page.goto(f.origin+'/status?lang=en');await page.screenshot({path:f.root+'/public-status-en-'+(js?'js':'nojs')+'.png',fullPage:true});check((await ctx.cookies()).length===0,'anonymous status choices do not start a session '+js);await ctx.close();
 }
 check(external===0&&posts===0&&errors.length===0,'no external requests writes or script errors '+JSON.stringify({external,posts,errors}));
 console.log(JSON.stringify({suite:'public-status-language-browser',checks,profiles:8,font_view_states:views,external_requests:external,business_posts:posts,script_errors:errors.length}));
}finally{await browser.close();}
