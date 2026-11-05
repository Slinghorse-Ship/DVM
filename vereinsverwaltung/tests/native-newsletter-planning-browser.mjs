import assert from 'node:assert/strict';
import {readFile,writeFile,mkdir} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const root=fileURLToPath(new URL('../../',import.meta.url)),origin='http://127.0.0.1:8111';
const credentials=JSON.parse(await readFile(root+'.local/native-cms-integration/integrated-manager-runtime/browser-credentials.json','utf8'));
const browser=await chromium.launch({executablePath:process.env.CHROMIUM_EXECUTABLE||'/usr/bin/chromium'});
const checks=[];
try{
  const context=await browser.newContext();await context.route('**/*',route=>new URL(route.request().url()).origin===origin?route.continue():route.abort());
  const page=await context.newPage(),errors=[];page.on('pageerror',error=>errors.push(error.message));
  const login=async(account)=>{await page.goto(origin+'/login/');await page.getByLabel('E-Mail-Adresse oder persönlicher Benutzername',{exact:true}).fill(account.email);await page.getByLabel('Passwort',{exact:true}).fill(account.password);await Promise.all([page.waitForURL('**/vereinsverwaltung/**'),page.getByRole('button',{name:'Anmelden und Vereinsmanager öffnen'}).click()]);};
  await login(credentials.admin);await page.goto(origin+'/vereinsverwaltung/?view=newsletter&section=planning');
  const plan=()=>page.locator('form').filter({has:page.locator('input[name=operation][value=newsletter-digest]')}).filter({has:page.locator('input[name=period][value=calendar]')});
  const version=Number(await plan().locator('[name=plan_version]').inputValue());
  const priorPaused=await plan().locator('[name=paused]').isChecked();
  const stale=Object.fromEntries(await plan().evaluate(form=>[...new FormData(form).entries()]));
  await plan().locator('[name=paused]').check();await plan().getByRole('button',{name:'Periodenplan speichern'}).click();
  await page.waitForFunction(expected=>Number(document.querySelector('form input[name=period][value=calendar]')?.form.querySelector('[name=plan_version]')?.value)===expected,version+1);
  assert.equal(new URL(page.url()).searchParams.get('section'),'planning');assert(await plan().locator('[name=paused]').isChecked());
  const rejected=await context.request.post(origin+'/vereinsverwaltung/?view=newsletter&section=planning',{headers:{Accept:'application/json'},form:stale});assert.equal(rejected.status(),422);
  await page.reload();assert.equal(Number(await plan().locator('[name=plan_version]').inputValue()),version+1);
  const period=()=>page.locator('form').filter({has:page.locator('input[name=operation][value=newsletter-period-pause]')}).filter({has:page.locator('input[name=period][value=calendar]')});
  const periodVersion=Number(await period().locator('[name=period_version]').inputValue());
  await period().getByRole('button').click();
  await page.waitForFunction(expected=>Number(document.querySelector('form input[name=operation][value=newsletter-period-pause]')?.form.querySelector('[name=period_version]')?.value)===expected,periodVersion+1);
  await period().getByRole('button').click();
  await page.waitForFunction(expected=>Number(document.querySelector('form input[name=operation][value=newsletter-period-pause]')?.form.querySelector('[name=period_version]')?.value)===expected,periodVersion+2);
  for(const width of [1440,820,390]){
    await page.setViewportSize({width,height:1050});await page.reload();
    assert(await plan().locator('[name=paused]').isChecked());assert(await page.getByText('Newsletter nach Kalenderperioden',{exact:true}).count());
    await plan().getByRole('button',{name:'Periodenplan speichern'}).focus();assert(await plan().getByRole('button',{name:'Periodenplan speichern'}).evaluate(element=>element===document.activeElement));
    assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2));
    assert(!/Fatal error:|Warning:|Notice:|Parse error:/.test(await page.locator('body').innerText()));
    await page.getByRole('link',{name:'Planung im gemeinsamen Kalender'}).click();await page.waitForURL('**view=calendar*');
    assert((await page.locator('body').innerText()).includes('Ausgabe vorbereiten:'));
    assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2));
    await page.goto(origin+'/vereinsverwaltung/?view=newsletter&section=planning');
    checks.push({width,saved_pause:true,keyboard:true,common_calendar:true,overflow:false});
  }
  if(version>0&&!priorPaused){await plan().locator('[name=paused]').uncheck();const current=Number(await plan().locator('[name=plan_version]').inputValue());await plan().getByRole('button',{name:'Periodenplan speichern'}).click();await page.waitForFunction(expected=>Number(document.querySelector('form input[name=period][value=calendar]')?.form.querySelector('[name=plan_version]')?.value)===expected,current+1);}
  await context.close();
  const memberContext=await browser.newContext();await memberContext.route('**/*',route=>new URL(route.request().url()).origin===origin?route.continue():route.abort());const member=await memberContext.newPage();
  await member.goto(origin+'/login/');await member.getByLabel('E-Mail-Adresse oder persönlicher Benutzername',{exact:true}).fill(credentials.member.email);await member.getByLabel('Passwort',{exact:true}).fill(credentials.member.password);
  await Promise.all([member.waitForURL('**/vereinsverwaltung/**'),member.getByRole('button',{name:'Anmelden und Vereinsmanager öffnen'}).click()]);
  const denied=await member.goto(origin+'/vereinsverwaltung/?view=newsletter&section=planning');assert.equal(denied.status(),403);assert.equal(await member.locator('input[name=plan_version],input[name=period_version]').count(),0);
  assert.deepEqual(errors,[]);await mkdir(root+'.local/browser-tests',{recursive:true,mode:0o700});await writeFile(root+'.local/browser-tests/newsletter-planning-result.json',JSON.stringify({checks,member_denied:true,stale_rejected:true,period_pause_roundtrip:true,live_transport:false},null,2),{mode:0o600});
  console.log('Newsletter planning: saved global/single-period pauses, stale-form rejection, retained planning tab, member denial, original calendar links and keyboard at 1440/820/390 px passed.');
}finally{await browser.close();}
