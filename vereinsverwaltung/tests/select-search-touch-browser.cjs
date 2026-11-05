'use strict';
const {webkit,chromium,devices}=require('playwright');
const assert=require('node:assert/strict'),fs=require('node:fs');
const cases=[['WebKit touch',webkit,devices['iPhone 13']],['Chromium touch',chromium,devices['Pixel 7']],['WebKit mouse',webkit,{viewport:{width:1280,height:900}}],['Chromium mouse',chromium,{viewport:{width:1280,height:900}}]];
async function activate(locator,touch){return touch?locator.tap():locator.click();}
(async()=>{for(const [name,engine,device] of cases){
 const browser=await engine.launch({headless:true});try{
  const context=await browser.newContext(device),page=await context.newPage(),touch=!!device.hasTouch;
  const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.setContent('<meta name="viewport" content="width=device-width,initial-scale=1"><form><label>Mitglied / Person<select name="person" required><option value="">Bitte Person auswählen</option>'+Array.from({length:31},(_,i)=>'<option value="person-'+i+'">Person '+String(i).padStart(2,'0')+'</option>').join('')+'</select></label><button>Daten übernehmen</button></form><div id="unrelated"></div>');
  await page.addStyleTag({path:'vereinsverwaltung/public/select-search.css'});await page.addScriptTag({path:'vereinsverwaltung/public/select-search.js'});
  await page.evaluate(()=>{window.changes=0;document.querySelector('select').addEventListener('change',()=>window.changes++);document.querySelector('form').addEventListener('submit',e=>{e.preventDefault();window.selected=new FormData(e.target).get('person');});});
  const input=page.getByRole('combobox');
  await activate(input,touch);await activate(page.getByRole('option',{name:'Person 01',exact:true}),touch);
  assert.equal(await page.locator('select').inputValue(),'person-1',name+' tap must update submitted select');
  assert.equal(await input.inputValue(),'Person 01');assert.equal(await page.evaluate(()=>changes),1,'exactly one change');
  await activate(page.getByRole('button',{name:'Daten übernehmen'}),touch);assert.equal(await page.evaluate(()=>selected),'person-1');
  // A status/counter elsewhere in the form must not detach the pressed option.
  await activate(input,touch);const pressed=await page.getByRole('option',{name:'Person 02',exact:true}).boundingBox();
  await page.mouse.move(pressed.x+pressed.width/2,pressed.y+pressed.height/2);await page.mouse.down();
  await page.evaluate(async()=>{document.querySelector('#unrelated').append(document.createElement('span'));await new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)));});
  await page.mouse.up();assert.equal(await page.locator('select').inputValue(),'person-2',name+' unrelated DOM update during press');
  await activate(input,touch);await page.evaluate(()=>{document.querySelector('select').add(new Option('Person 32 added','person-32'));});await input.fill('32 added');await activate(page.getByRole('option',{name:'Person 32 added',exact:true}),touch);assert.equal(await page.locator('select').inputValue(),'person-32','real option changes remain visible');
  await activate(input,touch);await input.fill('30');await activate(page.getByRole('option',{name:'Person 30',exact:true}),touch);assert.equal(await page.locator('select').inputValue(),'person-30');
  await activate(input,touch);await input.fill('missing');await page.getByRole('button').focus();assert.equal(await input.inputValue(),'Person 30','blur retains previous confirmed value');
  await input.focus();await input.fill('07');await input.press('Enter');assert.equal(await page.locator('select').inputValue(),'person-7','keyboard still works');
  await page.evaluate(()=>{document.querySelector('form').reset();});await page.waitForFunction(()=>document.querySelector('select').value===''&&document.querySelector('[role=combobox]').value==='Bitte Person auswählen');
  // The other two suggestion lists used the same touch-cancelling listener.
  await page.setContent('<meta name="viewport" content="width=device-width,initial-scale=1"><form><label>Kategorie<input list="inventory-categories"></label><datalist id="inventory-categories"><option value="Bühnentechnik"><option value="Lichttechnik"></datalist><button>Fertig</button></form>');
  await page.addStyleTag({path:'vereinsverwaltung/public/select-search.css'});await page.addScriptTag({path:'vereinsverwaltung/public/inventory-category-hints.js'});
  await activate(page.getByRole('combobox'),touch);await activate(page.getByRole('option',{name:'Lichttechnik',exact:true}),touch);assert.equal(await page.getByRole('combobox').inputValue(),'Lichttechnik');
  await page.setContent('<meta name="viewport" content="width=device-width,initial-scale=1"><form><div data-position-editor><div><input data-field="description" value="Bühnenpodest"></div></div><div data-invoice-items><input data-invoice-item-search><p data-invoice-item-status></p><div data-invoice-item-row data-search="bühne"><input type="checkbox" checked><label data-invoice-item-position>Position<input></label></div></div></form>');
  await page.addStyleTag({path:'vereinsverwaltung/public/select-search.css'});await page.addScriptTag({path:'vereinsverwaltung/public/invoice-items.js'});
  await activate(page.getByRole('combobox'),touch);await activate(page.getByRole('option',{name:'Position 1 – Bühnenpodest',exact:true}),touch);assert.equal(await page.getByRole('combobox').inputValue(),'Position 1 – Bühnenpodest');
  assert.deepEqual(errors,[]);console.log('PASS '+name+': person selection/submission/search/keyboard/reset, category and invoice position');
  if(touch&&process.env.KULTURHUS_MEMBER_PROFILE_FIXTURE){
   const fixture=JSON.parse(fs.readFileSync(process.env.KULTURHUS_MEMBER_PROFILE_FIXTURE,'utf8'));const base=new URL(fixture.base);assert.equal(base.origin,'http://127.0.0.1:8091');assert(fixture.club.startsWith('member-profile-')&&fixture.touch);
   await context.addCookies([{name:'KH_VEREIN_DEMO',value:fixture.sessions.admin,url:base.origin+'/'}]);await context.route('**/*',route=>new URL(route.request().url()).origin===base.origin?route.continue():route.abort());
   await page.goto(base.origin+'/?view=portal&tab=safety&volunteer=1&volunteer_kind=general');assert.equal(await page.locator('select[name=person] option').count(),32);
   const person=page.getByRole('combobox',{name:'Mitglied / Person',exact:true});await person.tap();await person.fill(fixture.touch.name);await page.getByRole('option',{name:fixture.touch.name,exact:true}).tap();assert.equal(await person.inputValue(),fixture.touch.name);
   await page.getByRole('button',{name:'Daten übernehmen',exact:true}).tap();await page.locator('[name=activity]').waitFor();assert.equal(new URL(page.url()).searchParams.get('person'),fixture.touch.id);assert.equal(await page.locator('select[name=person]').inputValue(),fixture.touch.id);assert.equal(await person.inputValue(),fixture.touch.name);assert.equal(await page.locator('[name=contact]').inputValue(),fixture.touch.id);
   assert.match(await page.locator('[name=activity]').inputValue(),/Unterstützt den Verein aktiv/);assert.equal(await page.getByText('Bestätigung für das Kalenderjahr '+new Date().getFullYear(),{exact:true}).count(),1);assert.deepEqual(errors,[]);
   console.log('PASS '+name+': actual admin annual certificate, 31 members, selection survives GET reload');
  }
 }finally{await browser.close();}
}})().catch(e=>{console.error(e);process.exit(1)});
