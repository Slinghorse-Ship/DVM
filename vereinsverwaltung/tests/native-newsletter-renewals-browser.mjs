import assert from 'node:assert/strict';
import {readFile,writeFile} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const root=fileURLToPath(new URL('../../',import.meta.url)),origin='http://127.0.0.1:8111';
const credentials=JSON.parse(await readFile(root+'.local/native-cms-integration/integrated-manager-runtime/browser-credentials.json','utf8'));
const fixture=JSON.parse(await readFile(root+'.local/browser-tests/newsletter-renewals.json','utf8'));
const browser=await chromium.launch({executablePath:process.env.CHROMIUM_EXECUTABLE||'/usr/bin/chromium'});
const errors=[];
const login=async(role)=>{
  const context=await browser.newContext();
  await context.route('**/*',r=>new URL(r.request().url()).origin===origin?r.continue():r.abort());
  const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
  await page.goto(origin+'/login/');
  await page.getByLabel('E-Mail-Adresse oder persönlicher Benutzername',{exact:true}).fill(credentials[role].email);
  await page.getByLabel('Passwort',{exact:true}).fill(credentials[role].password);
  await Promise.all([page.waitForURL('**/vereinsverwaltung/**'),page.getByRole('button',{name:'Anmelden und Vereinsmanager öffnen'}).click()]);
  return {page,context};
};
const url=origin+'/vereinsverwaltung/?view=newsletter&section=recipients&q=Newsletter-Erneuerung';
const jobForms=page=>page.locator('form').filter({has:page.locator('[name=operation][value=newsletter-renew-confirmation]')});
try{
  const {page,context}=await login('admin');await page.goto(url);
  let target=jobForms(page).filter({has:page.locator('[name=subscriber_id][value="'+fixture.target+'"]')});
  if(await target.count()){
    const form=Object.fromEntries(await target.evaluate(f=>[...new FormData(f).entries()]));
    let response=await context.request.post(url,{headers:{Accept:'application/json'},form});
    assert.equal(response.status(),422,'explicit confirmation required on server');
    response=await context.request.post(url,{headers:{Accept:'application/json'},form:{...form,renewal_confirmed:'1',csrf:'wrong'}});
    assert.equal(response.status(),422,'manager CSRF required');
    response=await context.request.post(url,{headers:{Accept:'application/json'},form:{...form,renewal_confirmed:'1',preview_hash:'0'.repeat(64)}});
    assert.equal(response.status(),422,'outdated selection rejected');
    await target.locator('..').locator('summary').click();
    assert.equal(await target.locator('[name=renewal_confirmed]').isChecked(),false);
    await target.locator('[name=renewal_confirmed]').check();
    await target.getByRole('button').click();
    await page.waitForFunction(()=>document.body?.innerText.includes('1 erneute Bestätigungsanfrage(n) eingeplant.'));
    assert.equal(new URL(page.url()).searchParams.get('section'),'recipients');
    response=await context.request.post(url,{headers:{Accept:'application/json'},form:{...form,renewal_confirmed:'1'}});
    assert.equal(response.status(),200,'identical click returns original job');
  }else{
    assert(fixture.repeat,'existing queued fixture retains its original batch');
    const csrf=await page.locator('input[name=csrf]').first().inputValue();
    const repeat=await context.request.post(url,{headers:{Accept:'application/json'},form:{operation:'newsletter-renew-confirmation',csrf,subscriber_id:fixture.target,request_id:fixture.repeat.id,preview_hash:fixture.repeat.preview_hash,renewal_confirmed:'1'}});
    assert.equal(repeat.status(),200,'repeated verification returns the original job');
  }
  const checks=[];
  for(const width of [1440,820,390]){
    await page.setViewportSize({width,height:1050});await page.goto(url);
    const bulk=page.locator('.confirmation-renewal').first();
    await bulk.locator('summary').click();
    const bulkForm=jobForms(page).filter({has:page.locator('[name=subscriber_id][value=""]')});
    assert.equal(await bulkForm.count(),1);
    assert.equal(await bulkForm.locator('[name=renewal_confirmed]').isChecked(),false);
    assert.equal(await bulkForm.locator('[name=renewal_confirmed]').getAttribute('required'),'');
    assert.match(await bulk.innerText(),/unabhängig von Suche und aktueller Seite/);
    const article=page.locator('article').filter({has:page.getByRole('heading',{name:'Newsletter-Erneuerung Browser',exact:true})});
    const details=article.locator('.confirmation-renewal');
    await details.locator('summary').click();
    assert.match(await details.innerText(),/Bisherige erneute Anfragen/);
    assert.equal(await details.locator('li').count(),1,'one original queued job despite duplicate click');
    assert.match(await details.locator('li').innerText(),/Eingeplant/);
    assert.equal(await article.locator('[name=renewal_confirmed]').count(),0);
    await bulkForm.getByRole('button').focus();assert(await bulkForm.getByRole('button').evaluate(e=>e===document.activeElement));
    assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'recipient manager fits '+width);
    assert(!/Fatal error:|Warning:|Notice:/.test(await page.locator('body').innerText()));
    await page.screenshot({path:root+'.local/browser-tests/newsletter-renewals-'+width+'.png',fullPage:true});
    checks.push({width,unchecked_required_confirmation:true,queued_original_history:true,keyboard:true,overflow:false});
  }
  for(const role of ['member']){
    const client=await login(role);const response=await client.page.goto(url);
    assert.equal(response.status(),403);
    assert.equal(await client.page.locator('[name=renewal_confirmed]').count(),0);
    const denied=await client.context.request.post(url,{form:{operation:'newsletter-renew-confirmation',subscriber_id:fixture.target,renewal_confirmed:'1'}});
    assert.equal(denied.status(),403);
    await client.context.close();
  }
  const editor=await login('editor');const editorResponse=await editor.page.goto(url);
  assert.equal(editorResponse.status(),200,'existing manager newsletter writer retains recipient management');
  assert(await editor.page.locator('[name=renewal_confirmed]').count());
  assert.equal(await editor.page.locator('[name=renewal_confirmed]').first().isChecked(),false);
  await editor.context.close();
  assert.deepEqual(errors,[]);
  await writeFile(root+'.local/browser-tests/newsletter-renewals-result.json',JSON.stringify({status:'passed',checks,member_rejected:true,existing_newsletter_writer_allowed:true,live_transport:false},null,2));
  console.log('Newsletter renewal browser passed: explicit confirmation, original queued history, duplicate click, stale selection, live role checks and 1440/820/390 widths; no mail transport.');
}finally{await browser.close();}
