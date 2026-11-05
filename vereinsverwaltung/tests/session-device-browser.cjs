'use strict';
// Runs only against the isolated session-cookie-router, never a real account.
const {chromium}=require('playwright'),fs=require('node:fs'),path=require('node:path'),os=require('node:os'),cp=require('node:child_process'),assert=require('node:assert/strict');
const root=path.resolve(__dirname,'../..'),base='http://127.0.0.1:8096/';
const directory=fs.mkdtempSync(path.join(root,'.local/session-cookie-test-'));
const php=process.env.KULTURHUS_TEST_PHP||path.join(root,'.local/php-8.5.10/php.exe');
const server=cp.spawn(php,['-S','127.0.0.1:8096',path.join(__dirname,'support/session-cookie-router.php')],{cwd:root,env:{...process.env,VEREIN_MODE:'synthetic',KULTURHUS_COOKIE_TEST:'1',KULTURHUS_COOKIE_DIRECTORY:directory},stdio:'ignore'});
let browser,n=0;
const check=(ok,label)=>{assert(ok,label);n++;console.log('PASS '+label);};
(async()=>{try{
 for(let i=0;i<40;i++){try{if((await fetch(base)).ok)break;}catch{}await new Promise(r=>setTimeout(r,100));}
 browser=await chromium.launch({channel:'msedge',headless:true});
 const context=await browser.newContext({viewport:{width:390,height:844}});
 await context.route('**/*',r=>new URL(r.request().url()).origin===new URL(base).origin?r.continue():r.abort());
 const initial=await (await context.request.get(base)).json();
 const login=await context.request.post(base,{form:{action:'login',csrf:initial.csrf}});check(login.ok(),'isolated native-session fixture login');
 const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.goto(base+'?device_ui=1');
 check(await page.locator('#device-session-mode').inputValue()==='month','default thirty days shown in real form');
 for(const [mode,label] of [['logout','Bis ich mich abmelde'],['day','24 Stunden'],['month','30 Tage']]){
  await page.locator('#device-session-mode').selectOption(mode);
  await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Anmeldedauer speichern',exact:true}).click()]);
  check(await page.locator('#device-session-mode').inputValue()===mode,'selection persists after native POST: '+mode);
  check((await page.getByRole('status').innerText()).includes(label),'confirmed save message: '+mode);
  await page.reload();check(await page.locator('#device-session-mode').inputValue()===mode&&await page.getByRole('status').count()===0,'reload keeps mode without repeating success: '+mode);
 }
 check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'390px page has no horizontal overflow');
 const box=await page.getByRole('button',{name:'Anmeldedauer speichern',exact:true}).boundingBox();check(box.height>=40,'save action has usable touch target');
 await page.screenshot({path:path.join(directory,'device-session-390.png'),fullPage:true});
 assert.deepEqual(errors,[]);check(true,'no JavaScript errors');
 console.log(JSON.stringify({checks:n,output:directory,scope:'isolated synthetic session and real UI/POST; no live login/mail'}));
}finally{if(browser)await browser.close();server.kill();}})().catch(e=>{console.error(e);process.exitCode=1;});
