// Run only with the synthetic local server and fixture IDs supplied by the audit setup.
const {chromium}=require('playwright');
const assert=require('node:assert/strict');
(async()=>{
 const base=process.env.KULTURHUS_TEST_BASE||'http://127.0.0.1:8091/';
 assert(['127.0.0.1','localhost','[::1]'].includes(new URL(base).hostname),'loopback only');
 const doc=process.env.KULTURHUS_TEST_DOCUMENT,person=process.env.KULTURHUS_TEST_PERSON;
 assert(doc&&person,'isolated shared-document fixture is required');
 const browser=await chromium.launch({headless:true,channel:'msedge'});
 try{
  const page=await browser.newPage({viewport:{width:1280,height:960}}),errors=[];
  page.on('pageerror',e=>errors.push(e.message));
  await page.goto(base+'?view=documents&doc='+encodeURIComponent(doc));
  const section=page.locator('section').filter({has:page.getByRole('heading',{name:'Persönliche Freigabe im Mitgliederportal',exact:true})});
  await section.getByText('Aktuelle Version freigegeben',{exact:false}).waitFor();
  const form=section.locator('form');assert.equal(await form.locator('[name=contact]').inputValue(),'');
  await form.locator('[name=contact]').selectOption(person);await form.locator('[name=enabled]').selectOption('0');
  await form.getByRole('button',{name:'Freigabe speichern'}).click();
  await section.getByText('Dieses Dokument ist noch für keine Person persönlich freigegeben.',{exact:true}).waitFor();
  await page.reload();await section.getByText('Dieses Dokument ist noch für keine Person persönlich freigegeben.',{exact:true}).waitFor();
  await form.locator('[name=contact]').selectOption(person);await form.locator('[name=enabled]').selectOption('1');
  await form.getByRole('button',{name:'Freigabe speichern'}).click();await section.getByText('Aktuelle Version freigegeben',{exact:false}).waitFor();
  for(const width of [1280,390]){await page.setViewportSize({width,height:900});assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'document overflow '+width);assert(await section.isVisible());}
  console.log('PASS document grant revoke/reopen/grant, deliberate recipient and desktop/mobile reflow');
  await page.goto(base+'?view=finance&tab=export');
  const exportForm=page.locator('form').filter({has:page.locator('[name=export_form][value=finance]')});
  for(const action of ['finance-export','finance-bundle']){
   await exportForm.locator('[name=from]').fill('2026-08-01');await exportForm.locator('[name=to]').fill('2026-02-01');await exportForm.locator('[name=kind]').selectOption('expense');await exportForm.locator('[name=status]').selectOption('partial');
   await exportForm.locator('[name=action][value='+action+']').click();await page.getByRole('alert').waitFor();
   assert.equal(await exportForm.locator('[name=from]').inputValue(),'2026-08-01');assert.equal(await exportForm.locator('[name=to]').inputValue(),'2026-02-01');assert.equal(await exportForm.locator('[name=kind]').inputValue(),'expense');assert.equal(await exportForm.locator('[name=status]').inputValue(),'partial');
  }
  assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'export mobile overflow');
  assert.deepEqual(errors,[]);console.log('PASS CSV/ZIP validation retains dates/type/status, mobile reflow and no browser errors');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
