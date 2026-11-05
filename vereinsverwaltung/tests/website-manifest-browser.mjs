import fs from 'node:fs';
import assert from 'node:assert/strict';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE);
const f=JSON.parse(fs.readFileSync(process.env.DVM_WEBSITE_MANIFEST_FIXTURE,'utf8'));
const browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE,headless:true,args:['--no-sandbox']});
let checks=0;const check=(value,label)=>{assert.ok(value,label);checks++;};
try{
 for(const width of [390,820])for(const javaScriptEnabled of [true,false]){
  const context=await browser.newContext({viewport:{width,height:900},javaScriptEnabled});
  const page=await context.newPage();const outside=[],errors=[];
  page.on('request',request=>{if(!request.url().startsWith(f.origin+'/'))outside.push(request.url());});
  page.on('console',message=>{if(message.type()==='error')errors.push(message.text());});
  await page.goto(f.origin+'/',{waitUntil:'networkidle'});
  const session=await context.newCDPSession(page);
  const manifest=await session.send('Page.getAppManifest');
  check(manifest.url===f.origin+'/site.webmanifest'&&manifest.errors.length===0,'Chromium discovers valid original website manifest '+width+'/'+javaScriptEnabled);
  const data=JSON.parse(manifest.data);
  check(data.name==='Ähren & Freunde <Verein>'&&data.start_url==='/'&&data.scope==='/','actual browser shortcut opens own public website');
  check(await page.locator('meta[name="theme-color"]').getAttribute('content')===data.theme_color,'browser chrome and manifest retain the same selected theme');
  for(const icon of data.icons){const response=await context.request.get(new URL(icon.src,f.origin).href);check(response.ok()&&response.headers()['content-type']==='image/png'&&(await response.body()).subarray(0,8).equals(Buffer.from([137,80,78,71,13,10,26,10])),'actual browser retrieves real local PNG shortcut icon '+icon.sizes);}
  const apple=await page.locator('link[rel="apple-touch-icon"]').getAttribute('href');check((await context.request.get(new URL(apple,f.origin).href)).ok(),'actual Apple icon is available');
  check(outside.length===0&&errors.length===0,'actual page has no foreign requests or CSP/manifest errors '+JSON.stringify(errors));
  await context.close();
 }
 console.log(JSON.stringify({browser_checks:checks,external_actions:0}));
}finally{await browser.close();}
