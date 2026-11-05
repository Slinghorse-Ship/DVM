import {kDirectory,kOrigin,kStorage,kConfig,kSource} from './support/k-browser-client.mjs';
import {readFile} from 'node:fs/promises';
import {execFileSync} from 'node:child_process';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE);
const root=kSource,origin=kOrigin(false);
const fixture=JSON.parse(await readFile(kDirectory()+'/website-footer.json','utf8'));
const storage=kStorage(false);
const browser=await chromium.launch({executablePath:'/usr/bin/chromium',headless:true,args:['--no-sandbox']});
let checks=0,writes=0,external=0;const errors=[];
const check=(ok,label)=>{if(!ok)throw Error(label);++checks;console.log('PASS '+label);};
const fits=page=>page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1);
try{
 for(const javaScriptEnabled of [true,false]){
  const context=await browser.newContext({storageState:storage,javaScriptEnabled,viewport:{width:390,height:844},hasTouch:true,isMobile:true});
  await context.route('**/*',route=>{const request=route.request();if(new URL(request.url()).origin!==origin){++external;return route.abort();}if(request.method()!=='GET'&&request.method()!=='HEAD')++writes;return route.continue();});
  const page=await context.newPage();page.on('pageerror',error=>errors.push(error.message));
  for(const width of [1440,820,390,320]){
   await page.setViewportSize({width,height:844});
   for(const template of ['kulturhus','klar','magazin','aktiv','natur']){
    const response=await page.goto(origin+'/vereinsverwaltung/?native_endpoint=design-preview&template='+template);
    check(response.ok()&&response.headers()['cache-control'].includes('no-store'),`actual private ${template} preview ${width} JS=${javaScriptEnabled}`);
    check(await fits(page),`${template} footer fits ${width} JS=${javaScriptEnabled}`);
    for(const [slug,legal]of Object.entries(fixture.legal)){
     const link=page.locator('.site-footer a').filter({hasText:slug==='datenloeschung'?'Datenlöschung':'Nutzungsbedingungen'});
     check(await link.count()===1&&new URL(await link.getAttribute('href'),origin).pathname.replace(/\/$/,'').endsWith('/'+slug),`exact original legal footer ${slug} ${template} ${width} JS=${javaScriptEnabled}`);
     check(await link.evaluate(node=>{const r=node.getBoundingClientRect();return r.height>=44&&r.width>=44&&r.right<=innerWidth+1;}),`actual legal touch target ${slug} ${template} ${width} JS=${javaScriptEnabled}`);
     if(legal.hidden)check(await page.locator('#nav a').filter({hasText:legal.title}).count()===0,`hidden legal page stays outside main menu ${slug} ${template}`);
    }
   }
  }
  await page.setViewportSize({width:390,height:844});
  for(const route of ['/','/search?q=Footertest','/mitglied-werden/']){const response=await page.goto(origin+route);check(response.ok()&&await page.locator('.site-footer a').filter({hasText:'Datenlöschung'}).count()===1&&await page.locator('.site-footer a').filter({hasText:'Nutzungsbedingungen'}).count()===1,'actual shared legal footer '+route+' JS='+javaScriptEnabled);}
  if(javaScriptEnabled){await page.evaluate(()=>document.documentElement.style.zoom='2');check(await fits(page),'actual legal footer fits at 200 percent zoom');}
  for(const[slug,legal]of Object.entries(fixture.legal)){
   await page.goto(origin+'/');const link=page.locator('.site-footer a').filter({hasText:slug==='datenloeschung'?'Datenlöschung':'Nutzungsbedingungen'});await link.focus();await Promise.all([page.waitForURL('**/'+slug+'/'),link.press('Enter')]);check((await page.locator('h1').innerText())===legal.title,'keyboard opens exact published legal original '+slug+' JS='+javaScriptEnabled);check(await page.locator('.site-footer a[aria-current=page]').count()===1,'actual footer marks current legal original '+slug+' JS='+javaScriptEnabled);
  }
  await page.goto(origin+'/');await Promise.all([page.waitForURL('**/datenloeschung/'),page.locator('.site-footer a').filter({hasText:'Datenlöschung'}).tap()]);check((await page.locator('h1').innerText())===fixture.legal.datenloeschung.title,'touch opens exact published legal original JS='+javaScriptEnabled);
  await context.close();
 }
 check(errors.length===0&&writes===0&&external===0,'actual browser has no script errors, content writes or external actions');
 const proof=execFileSync(process.env.PHP_CLI_BINARY||'php',[root+'vereinsverwaltung/tests/support/website-footer-browser-fixture.php','check'],{env:{...process.env,KH_NATIVE_TEST_CONFIG:kConfig(false)},encoding:'utf8'});check(proof.includes('All original bytes retained'),'all exact original bytes retained after actual footer browsing');
 console.log(JSON.stringify({checks,business_writes:writes,external_actions:external}));
}finally{await browser.close();}
