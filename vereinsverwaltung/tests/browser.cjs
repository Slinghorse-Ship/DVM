const {chromium}=require('playwright');
const fs=require('node:fs');
const path=require('node:path');
const assert=require('node:assert/strict');
(async()=>{
 const browser=await chromium.launch({channel:'msedge',headless:true});
 const context=await browser.newContext();
 const page=await context.newPage();
 const failures=[];page.on('pageerror',e=>failures.push(e.message));
 const output=path.resolve(__dirname,'../../.local/vereinsverwaltung/screenshots');fs.mkdirSync(output,{recursive:true});
 const response=await page.goto('http://127.0.0.1:8092/vereinsverwaltung/?view=members&list=current');
 assert.equal(response.status(),200);assert.match(response.headers()['cache-control'],/no-store/);
 await page.getByRole('link',{name:'Mara Muster · Testdaten',exact:true}).click();
 const widths=[360,390,768,1024,1440];
 for(const width of widths){
  await page.setViewportSize({width,height:1000});
  assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`overflow at ${width}`);
  assert(await page.getByRole('button',{name:'Profil speichern',exact:true}).isVisible());
  await page.screenshot({path:path.join(output,`${width}.png`),fullPage:true});
 }
 // CSS zoom checks reflow, but does not replace manual browser zoom/screenreader acceptance.
 await page.evaluate(()=>document.documentElement.style.zoom='2');
 assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'overflow at CSS 200%');
 await page.screenshot({path:path.join(output,'zoom-200.png'),fullPage:true});
 await page.evaluate(()=>document.documentElement.style.zoom='1');
 await page.getByLabel('E-Mail',{exact:true}).fill('invalid');
 assert.equal(await page.getByLabel('E-Mail',{exact:true}).evaluate(el=>el.checkValidity()),false);
 await page.getByLabel('E-Mail',{exact:true}).fill('mara@example.invalid');
 await page.getByRole('textbox',{name:'Name *',exact:true}).fill('Mara Muster · Testdaten');
 await page.getByRole('button',{name:'Profil speichern',exact:true}).click();
 assert.equal(await page.getByRole('textbox',{name:'Name *',exact:true}).inputValue(),'Mara Muster · Testdaten');
 // Stale second tab must retain attempted edits and show a conflict.
 const tab=await context.newPage();await tab.goto(page.url());
 await page.getByRole('button',{name:'Profil speichern',exact:true}).click();
 await tab.getByRole('textbox',{name:'Name *',exact:true}).fill('Konflikt · Testdaten');
 await tab.getByRole('button',{name:'Profil speichern',exact:true}).click();
 assert.match(await tab.getByRole('alert').innerText(),/inzwischen geändert/);
 assert.equal(await tab.getByRole('textbox',{name:'Name *',exact:true}).inputValue(),'Konflikt · Testdaten');
 await tab.close();
 // CSRF is required even for selecting a demo persona.
 const csrf=await context.request.post('http://127.0.0.1:8092/vereinsverwaltung/',{form:{action:'persona',actor:'demo-manager'}});
 assert.equal(csrf.status(),403);
 await page.locator('.demo-access > summary').click();await page.getByLabel('Testrolle').selectOption('demo-editor');await page.getByRole('button',{name:'Testansicht öffnen'}).click();
 assert.match(await page.getByRole('alert').innerText(),/Keine Berechtigung/);
 assert.equal(await page.getByRole('button',{name:'Profil speichern',exact:true}).count(),0);
 await page.locator('.demo-access > summary').click();await page.getByLabel('Testrolle').selectOption('demo-member');await page.getByRole('button',{name:'Testansicht öffnen'}).click();
 assert.equal(await page.getByRole('link',{name:'Kulturfreunde Muster · Testdaten',exact:true}).count(),0);
 await page.goto('http://127.0.0.1:8092/vereinsverwaltung/?view=members&list=all');await page.getByRole('link',{name:'Mara Muster · Testdaten',exact:true}).click();
 assert.equal(await page.getByRole('button',{name:'Profil speichern',exact:true}).count(),0);
 assert.deepEqual(failures,[]);
 await browser.close();console.log('PASS browser: five widths, CSS zoom, save, conflict retention, CSRF, editor denied, own profile');
})().catch(e=>{console.error(e);process.exit(1)});
