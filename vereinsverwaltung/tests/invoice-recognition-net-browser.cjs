'use strict';
// Synthetic recognition responses in the real isolated local invoice form.
// The original five PDFs are tested separately on the real hosting runtime.
const {chromium}=require('playwright');
const fs=require('node:fs'),assert=require('node:assert/strict');
(async()=>{
 const fixture=JSON.parse(fs.readFileSync(process.env.KULTURHUS_INVOICE_PURPOSE_FIXTURE,'utf8').replace(/^\uFEFF/,''));
 const base=new URL(fixture.base);assert(['127.0.0.1','localhost','[::1]'].includes(base.hostname));
 const browser=await chromium.launch({headless:true,channel:'msedge'}),checks=[];
 try{
  const context=await browser.newContext({viewport:{width:1280,height:900}});
  await context.addCookies([{name:'KH_VEREIN_DEMO',value:fixture.session,url:base.origin+'/'}]);
  const page=await context.newPage(),errors=[],writes=[];
  page.on('pageerror',e=>errors.push(e.message));
  page.on('request',r=>{if(r.method()==='POST'&&!r.url().includes('invoice_recognition=1'))writes.push(r.url());});
  let seed;
  await page.route('**/*invoice_recognition=1*',route=>route.fulfill({contentType:'application/json',body:JSON.stringify({result:seed})}));
  const originalLine='1 Synthetic print 1 Stück 39,33 netto / 46,80 brutto';
  const cases=[
   {label:'Net line compared with gross invoice on gross basis',item:{net_amount:'39.33',gross_amount:'',line_amount:'39.33',price_basis:'net',ambiguous:false},difference:false,amount:'46,80'},
   {label:'Explicit net and gross amounts agree',item:{net_amount:'39.33',gross_amount:'46.80',line_amount:'39.33',price_basis:'net',ambiguous:false},difference:false,amount:'46,80'},
   {label:'Unresolved price basis remains visibly unresolved',item:{net_amount:'',gross_amount:'',line_amount:'39.33',price_basis:'',ambiguous:true},difference:true,amount:'39,33'},
   {label:'Real gross mismatch is still reported',item:{net_amount:'',gross_amount:'40.00',line_amount:'40.00',price_basis:'gross',ambiguous:false},difference:true,amount:'40,00'}
  ];
  for(const test of cases){
   seed={state:'ready',fields:{Währung:'EUR',Belegart:'Rechnung',Rechnungsdatum:'2025-07-22','Rechnungsbetrag brutto':'46.80',Umsatzsteuerbetrag:'7.47',Zahlungsart:'PayPal'},items:[{number:'1',name:'Synthetic print',quantity:'1',unit:'Stück',unit_price:'39.33',tax_rate:'19',original_line:originalLine,...test.item}],warnings:[]};
   await page.goto(base.origin+'/?view=finance&tab=inbox&inbox_doc='+fixture.doc);
   const form=page.locator('[data-invoice-review-form]');await form.waitFor();
   assert.equal(await form.locator('[data-position-editor]>fieldset').count(),0,'Use fixture without prior recognition');
   await form.locator('[data-recognition-run]').click();
   await form.locator('[data-position-editor]>fieldset').waitFor();
   assert.equal(await form.locator('[data-field=amount]').inputValue(),test.amount);
   assert.equal(await form.locator('[data-original-difference]').count(),test.difference?1:0);
   assert.match(await form.locator('[data-recognition-fields]').innerText(),/PayPal.*kein Zahlungsnachweis/);
   assert.equal(await form.locator('[data-positions-reviewed]').isChecked(),false);
   assert.match(await form.locator('[data-invoice-document-year]').innerText(),/2025/);
   checks.push(test.label);
  }
  await page.setViewportSize({width:390,height:844});
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
  assert.deepEqual(errors,[]);assert.deepEqual(writes,[]);
  checks.push('Payment method is a hint, review stays manual, original year2025, mobile no overflow, no writes.');
  console.log(JSON.stringify({passed:checks.length,checks},null,2));
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
