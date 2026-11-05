'use strict';
const {chromium}=require('playwright'),fs=require('node:fs'),assert=require('node:assert/strict');
(async()=>{
 const fixture=JSON.parse(fs.readFileSync(process.env.KULTURHUS_INPUT_TAX_FIXTURE,'utf8')),base=new URL(fixture.base);
 assert.equal(process.env.VEREIN_MODE,'synthetic');assert(['127.0.0.1','localhost'].includes(base.hostname)&&base.port==='8091'&&fixture.club.startsWith('member-profile-'),'Fresh synthetic local fixture only');
 const browser=await chromium.launch({headless:true,channel:'msedge'}),checks=[];
 try{
  const context=await browser.newContext({viewport:{width:1280,height:1000}});await context.addCookies([{name:'KH_VEREIN_DEMO',value:fixture.session,url:base.origin+'/'}]);
  await context.route('**/*',r=>new URL(r.request().url()).origin===base.origin?r.continue():r.abort());const page=await context.newPage(),errors=[],writes=[];page.on('pageerror',e=>errors.push(e.message));page.on('request',r=>{if(r.method()==='POST'&&!r.url().includes('invoice_accounting_suggestions='))writes.push(r.url());});
  const response=await page.goto(base.origin+'/?view=finance&tab=inbox&inbox_doc='+fixture.doc);assert.equal(response.headers()['x-kulturhus-local-project'],fixture.project_hash);
  const form=page.locator('[data-invoice-review-form]');await form.waitFor();
  await form.locator('[name=document_date]').fill('2026-01-03');await form.locator('[name=document_date]').dispatchEvent('change');
  await form.locator('[data-position-add]').click();
  const row=form.locator('[data-position-editor]>fieldset').first(),vat=row.locator('[data-field=input_tax]'),rate=row.locator('[data-field=tax_rate]'),key=row.locator('[data-field=booking_key]');
  assert.equal(await vat.inputValue(),'none');checks.push('Explicit club default is visible in a new position.');
  await rate.selectOption('19');await row.locator('[data-field=amount]').fill('119,00');await vat.selectOption('deductible');assert.equal(await rate.inputValue(),'19');assert.equal(await row.locator('[data-field=amount]').inputValue(),'119,00');
  await vat.selectOption('none');assert.equal(await rate.inputValue(),'19');assert.equal(await key.inputValue(),'');checks.push('Changing deduction never changes original VAT, gross amount or auto-selects BU.');
  await key.selectOption('9');assert.match(await key.evaluate(e=>e.validationMessage),/Kein Vorsteuerabzug/);assert.equal(await rate.inputValue(),'19');await key.selectOption('');checks.push('Conflicting BU is visibly invalid and never silently erased.');
  await vat.selectOption('unknown');await form.locator('[name=document_date]').fill('2025-07-22');await form.locator('[name=document_date]').dispatchEvent('change');assert.equal(await vat.inputValue(),'unknown');assert.equal(await rate.inputValue(),'19');checks.push('Year switch retains explicit deduction choice and original VAT.');
  assert.equal(await form.locator('[data-positions-reviewed]').isChecked(),false);checks.push('Review remains a manual decision.');
  for(const width of [1280,390]){await page.setViewportSize({width,height:900});await vat.scrollIntoViewIfNeeded();assert((await vat.boundingBox()).height>=44);assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);}
  checks.push('Desktop/mobile invoice controls at least44px with no overflow.');
  await page.goto(base.origin+'/?view=finance&tab=receipts&new_entry=1&kind=expense');
  const generic=page.locator('#finance-form');await generic.waitFor();await generic.locator('.manual-position-tax > summary').click();const genericVat=generic.locator('[name="position_input_tax[]"]'),genericRate=generic.locator('[data-position-tax]'),genericKey=generic.locator('[data-position-key]');
  assert.equal(await genericVat.inputValue(),'none');await genericRate.selectOption('7');await genericKey.selectOption('9');assert.equal(await genericRate.inputValue(),'7');assert.match(await genericKey.evaluate(e=>e.validationMessage),/Kein Vorsteuerabzug/);await genericKey.selectOption('');
  await generic.locator('[name=document_date]').fill('2025-01-03');await generic.locator('[name=document_date]').dispatchEvent('change');assert.equal(await generic.locator('[data-position-account] option[value="06300"]').count(),1);assert.match(await generic.locator('[data-position-year-status]').innerText(),/2025/);
  await generic.locator('[name=document_date]').fill('2022-01-03');await generic.locator('[name=document_date]').dispatchEvent('change');assert.equal(await generic.locator('[data-position-account] option').count(),1);assert.equal(await genericVat.inputValue(),'none');assert.equal(await genericRate.inputValue(),'7');checks.push('Generic position editor retains VAT and deduction; BU never changes original rate; year-specific account choices are correct.');
  assert.deepEqual(errors,[]);assert.deepEqual(writes,[]);checks.push('No JS errors or business writes.');
  console.log(JSON.stringify({passed:checks.length,checks},null,2));
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
