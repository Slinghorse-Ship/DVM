import assert from 'node:assert/strict';
import {readFile, writeFile} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root=fileURLToPath(new URL('../../',import.meta.url));
const origin='http://127.0.0.1:8111';
const fixture=JSON.parse(await readFile(root+'.local/browser-tests/calendar-workflows.json','utf8'));
const credentials=JSON.parse(await readFile(root+'.local/native-cms-integration/integrated-manager-runtime/browser-credentials.json','utf8'));
const browser=await chromium.launch({executablePath:process.env.CHROMIUM_EXECUTABLE || '/usr/bin/chromium'});
const errors=[];
const client=async(role)=>{
  const context=await browser.newContext();
  await context.route('**/*',r=>new URL(r.request().url()).origin===origin?r.continue():r.abort());
  const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
  await page.goto(origin+'/login/');
  await page.getByLabel('E-Mail-Adresse oder persönlicher Benutzername',{exact:true}).fill(credentials[role].email);
  await page.getByLabel('Passwort',{exact:true}).fill(credentials[role].password);
  await Promise.all([page.waitForURL('**/vereinsverwaltung/**'),page.getByRole('button',{name:'Anmelden und Vereinsmanager öffnen'}).click()]);
  return {context,page};
};
try {
  const {page}=await client('admin');
  for (const width of [1440,820,390]) {
    await page.setViewportSize({width,height:1050});
    const response=await page.goto(origin+'/vereinsverwaltung/?view=calendar&mode=agenda&date='+fixture.date+'&calendar_filter=workflows');
    assert.equal(response.status(),200);
    assert.equal(await page.locator('select[name=calendar_filter]').inputValue(),'workflows');
    const link=page.locator('.calendar-agenda a').filter({hasText:'Kalender-Bühnenbeurteilung'});
    assert.equal(await link.count(),1);
    assert.match(await link.innerText(),/Nächster Prüftermin \/ Termin für die Klärung/);
    const url=new URL(await link.getAttribute('href'),page.url());
    assert.equal(url.searchParams.get('wf_doc'),fixture.document);
    assert.equal(url.searchParams.get('wf_revision'),String(fixture.revision));
    await link.focus();assert(await link.evaluate(e=>document.activeElement===e));
    await page.keyboard.press('Enter');
    await page.getByRole('heading',{name:'Kalender-Bühnenbeurteilung',exact:true}).waitFor();
    assert.equal(await page.locator('[name="wf_values[review_date]"]').inputValue(),fixture.date);
    assert.equal(await page.locator('[name="wf_values[review_date]"]').isDisabled(),true);
    assert.match(await page.locator('.dwf-meta').first().innerText(),/Freigegeben.*Version 4/s);
    assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'original document fits '+width);
    await page.screenshot({path:root+'.local/browser-tests/calendar-workflows-'+width+'.png',fullPage:true});
  }
  const member=await client('member');
  let response=await member.page.goto(origin+'/vereinsverwaltung/?view=calendar&mode=agenda&date='+fixture.date);
  assert.equal(response.status(),200);
  assert.equal(await member.page.locator('select[name=calendar_filter] option[value=workflows]').count(),0);
  assert.equal(await member.page.getByText('Kalender-Bühnenbeurteilung',{exact:false}).count(),0);
  response=await member.context.request.get(origin+'/vereinsverwaltung/?view=documents&tab=workflows&wf_doc='+fixture.document+'&wf_revision=4');
  assert.equal(response.status(),403);
  assert.deepEqual(errors,[]);
  await writeFile(root+'.local/browser-tests/calendar-workflows-result.json',JSON.stringify({status:'passed',widths:[1440,820,390],original_revision:fixture.revision,member_access_rejected:true},null,2));
  console.log('Workflow calendar browser passed: original approved document, semantic dates, keyboard activation, live member permissions and 1440/820/390 widths.');
} finally { await browser.close(); }
