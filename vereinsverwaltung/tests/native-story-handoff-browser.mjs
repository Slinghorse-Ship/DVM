import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const root=fileURLToPath(new URL('../../',import.meta.url));
const origin='http://127.0.0.1:8111';
const credentials=JSON.parse(await readFile(root+'.local/native-cms-integration/integrated-manager-runtime/browser-credentials.json','utf8')).admin;
const fixture=JSON.parse(await readFile(root+'.local/browser-tests/story-handoff-fixture.json','utf8'));
const browser=await chromium.launch({executablePath:process.env.CHROMIUM_EXECUTABLE||'/usr/bin/chromium',args:['--no-sandbox']});
try{
 const context=await browser.newContext({viewport:{width:1440,height:1050}});const page=await context.newPage();const errors=[];
 page.on('pageerror',e=>errors.push(e.message));
 await context.route('**/*',r=>r.request().url().startsWith(origin+'/')?r.continue():r.abort());
 await page.goto(origin+'/login/');
 await page.getByLabel('E-Mail-Adresse oder persönlicher Benutzername',{exact:true}).fill(credentials.email);
 await page.getByLabel('Passwort',{exact:true}).fill(credentials.password);
 await Promise.all([page.waitForURL('**/vereinsverwaltung/**'),page.getByRole('button',{name:'Anmelden und Vereinsmanager öffnen'}).click()]);
 await page.goto(origin+'/vereinsverwaltung/?view=social&native_item='+fixture.id);
 await page.getByRole('button',{name:'App-Übergabe vorbereiten'}).click();
 const handoff=page.locator('.story-handoff');
 try { await handoff.locator('[data-handoff]').waitFor({timeout:5000}); }
 catch(e){ console.log(await page.locator('[role=alert], .error, .notice, #notice, .toast').allTextContents()); await page.screenshot({path:root+'.local/browser-tests/story-debug.png',fullPage:true}); throw e; }
 assert.equal(await handoff.getByLabel('Zieladresse zum Kopieren').inputValue(),'https://example.invalid/kulturabend');
 const url=await handoff.getByRole('link',{name:'Folie 1 herunterladen'}).getAttribute('href');
 assert.match(url,/social-image/);const image=await context.request.get(new URL(url,origin).href);assert.equal(image.status(),200);assert.match(image.headers()['content-type'],/image\/png/);
 for(const width of [1440,820,390]){
  await page.setViewportSize({width,height:1050});await handoff.scrollIntoViewIfNeeded();
  const measure=await page.evaluate(()=>({width:innerWidth,scroll:document.documentElement.scrollWidth}));
  assert(measure.scroll<=width+2,'viewport overflow at '+width+': '+JSON.stringify(measure));
  const button=handoff.getByRole('button',{name:'Manuelle Veröffentlichung bestätigen'});await button.focus();assert(await button.evaluate(e=>e===document.activeElement));
 }
 await handoff.getByLabel('Ich habe die freigegebenen Folien geprüft.').check();
 await handoff.getByLabel('Ich habe den Link-Sticker mit dieser Zieladresse eingesetzt und geprüft.').check();
 await handoff.getByLabel('Ich habe die Story im gewählten Kanal veröffentlicht und geöffnet.').check();
 await handoff.getByRole('button',{name:'Manuelle Veröffentlichung bestätigen'}).click();
 await page.locator('.story-handoff [role=status]').filter({hasText:'Manuell bestätigt'}).waitFor();
 await page.screenshot({path:root+'.local/browser-tests/story-handoff-mobile.png',fullPage:true});
 assert.equal(errors.length,0,JSON.stringify(errors));
 console.log('Story UI passed: native login, guided app handoff, protected actual PNG download, keyboard focus, 1440/820/390 widths and stored manual confirmation.');
}finally{await browser.close();}
