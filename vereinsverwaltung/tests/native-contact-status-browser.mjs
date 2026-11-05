import assert from 'node:assert/strict';
import {readFile,writeFile} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
import {spawnSync} from 'node:child_process';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const root=fileURLToPath(new URL('../../',import.meta.url)),origin='http://127.0.0.1:8111';
const fixture=JSON.parse(await readFile(root+'.local/browser-tests/contact-status.json','utf8'));
const run=mode=>{const result=spawnSync(process.env.PHP_BINARY||'php',[root+'vereinsverwaltung/tests/support/contact-status-browser-fixture.php',mode],{cwd:root,env:{...process.env,KH_NATIVE_TEST_CONFIG:root+'.local/native-cms-integration/integrated-manager.json'},encoding:'utf8'});assert.equal(result.status,0,'owned fixture action '+mode+' succeeded');};
const browser=await chromium.launch({executablePath:process.env.CHROMIUM_EXECUTABLE||'/usr/bin/chromium',args:['--no-sandbox']});
let checks=0;const check=(ok,label)=>{assert(ok,label);checks++;console.log('PASS '+label);};
const errors=[],external=[];
try {
 const context=await browser.newContext(process.env.CONTACT_STATUS_BROWSER_STORAGE?{storageState:process.env.CONTACT_STATUS_BROWSER_STORAGE,hasTouch:true}:{hasTouch:true});
 await context.route('**/*',r=>new URL(r.request().url()).origin===origin?r.continue():(external.push(r.request().url()),r.abort()));
 const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
 if(!process.env.CONTACT_STATUS_BROWSER_STORAGE){const auth=JSON.parse(await readFile(root+'.local/native-cms-integration/integrated-manager-runtime/browser-credentials.json','utf8')).admin;await page.goto(origin+'/login/');await page.locator('[name=email]').fill(auth.email);await page.locator('[name=password]').fill(auth.password);await Promise.all([page.waitForURL('**/vereinsverwaltung/**'),page.getByRole('button',{name:'Anmelden und Vereinsmanager öffnen',exact:true}).click()]);}
 const panelNow=async()=>{const disclosure=page.locator('[data-member-panel=access]');if(await disclosure.getAttribute('open')===null){const summary=disclosure.locator(':scope > summary');await summary.focus();await summary.press('Enter');}return disclosure.locator('section.card').filter({has:page.getByRole('heading',{name:'Newsletter & Presse',exact:true})});};
 const profile=origin+'/vereinsverwaltung/?view=members&id='+fixture.contact;
 for(const width of [1440,820,390]){
  await page.setViewportSize({width,height:950});const response=await page.goto(profile);
  check(response.ok(),'actual authenticated member profile '+width);
  const panel=await panelNow();
  check(await panel.count()===1,'native communication panel '+width);
  check((await panel.innerText()).includes('Bestätigung ausstehend'),'actual pending native consent '+width);
  check((await panel.innerText()).includes('Eigener <Browser-Verteiler> (inaktiv)'),'actual inactive press entry '+width);
  check(!(await panel.innerText()).includes('nicht erreichbar'),'no obsolete website transport error '+width);
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'profile fits viewport '+width);
 }
 run('confirm');await page.reload();check((await (await panelNow()).innerText()).includes('Bestätigt'),'new native confirmation visible on next actual HTTP request');
 run('unsubscribe');await page.reload();check((await (await panelNow()).innerText()).includes('Abgemeldet'),'native withdrawal visible without obsolete cache');
 await page.evaluate(()=>document.documentElement.style.zoom='2');check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'actual member profile fits 200 percent zoom');
 check(errors.length===0,'no browser script errors');check(external.length===0,'no external calls or deliveries');run('check');
 await writeFile(root+'.local/browser-tests/contact-status-result.json',JSON.stringify({suite:'native-contact-status-browser',checks,widths:[1440,820,390],external_actions:0})+'\n',{mode:0o600});console.log(JSON.stringify({checks,external_actions:0}));
}finally{await browser.close();}
