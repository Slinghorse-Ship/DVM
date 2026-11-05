'use strict';
const pw=require('playwright'),fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
(async()=>{
 const f=JSON.parse(fs.readFileSync(process.env.KULTURHUS_MANUAL_PAYMENT_FIXTURE,'utf8')),base=new URL(f.base),out=path.dirname(process.env.KULTURHUS_MANUAL_PAYMENT_FIXTURE);assert(['localhost','127.0.0.1'].includes(base.hostname)&&base.port==='8091'&&f.club.startsWith('manual-payment-'));
 const browser=await pw.chromium.launch({headless:true,channel:'msedge'}),context=await browser.newContext({viewport:{width:1365,height:920}}),checks=[],errors=[];
 await context.addCookies([{name:'KH_VEREIN_DEMO',value:f.session,url:base.origin+'/'}]);await context.route('**/*',r=>new URL(r.request().url()).origin===base.origin?r.continue():r.abort());
 const p=await context.newPage();p.on('pageerror',e=>errors.push(e.message));const good=(v,name)=>{assert(v,name);checks.push(name);console.log('PASS '+name);};
 const url=base.origin+'/?view=finance&tab=receipts&entry='+f.entry+'&manual_payment=1#manual-payment';
 const form=()=>p.locator('#manual-payment form');const post=async()=>{await Promise.all([p.waitForURL(u=>u.searchParams.get('entry')===f.entry&&!u.searchParams.has('manual_payment')),form().locator('button').click()]);};
 try{
  let response=await p.goto(base.origin+'/?view=finance&tab=inbox&inbox_doc='+f.doc);assert.equal(response.headers()['x-kulturhus-local-project'],f.project_hash);
  good(await p.getByRole('link',{name:'Tatsächliche Zahlung manuell erfassen',exact:true}).count()===1,'invoice inbox offers actual payment separately from provisional report');
  await p.getByRole('link',{name:'Tatsächliche Zahlung manuell erfassen',exact:true}).click();good(await p.locator('#manual-payment').getAttribute('open')!==null,'manual payment link opens correct form');
  good(await form().locator('[name=amount]').inputValue()==='100.00','remaining invoice amount prefilled');
  await form().locator('[name=paid_on]').fill('2019-06-05');await form().locator('[name=amount]').fill('');await form().locator('button').click();good(await form().locator('[name=amount]').getAttribute('aria-invalid')==='true','missing payment amount highlighted without losing old date');
  await form().locator('[name=amount]').fill('120');await Promise.all([p.waitForNavigation(),form().locator('button').click()]);good(await form().locator('[name=amount]').inputValue()==='120'&&await form().locator('[name=paid_on]').inputValue()==='2019-06-05'&&await form().locator('[name=amount]').getAttribute('aria-invalid')==='true','excessive server-side amount error preserves entered date and amount');
  const stale=await form().evaluate(el=>Object.fromEntries(new FormData(el)));await form().locator('[name=amount]').fill('40,00');await post();await p.reload();
  good(/40,00/.test(await p.locator('.payment-totals').innerText())&&/60,00/.test(await p.locator('.payment-totals').innerText()),'actual old partial payment persists through POST and reload');
  const staleResponse=await context.request.post(url,{form:{...stale,amount:'40,00'}});good((await staleResponse.text()).includes('inzwischen geändert'),'same stale payment cannot be submitted again');
  await p.goto(url);await p.setViewportSize({width:390,height:844});await form().locator('[name=amount]').fill('60');await form().locator('[name=paid_on]').fill('2019-06-06');await p.locator('#manual-payment').screenshot({path:path.join(out,'manual-payment-mobile.png')});
  good(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'mobile payment form has no horizontal overflow');await post();await p.reload();good(/100,00/.test(await p.locator('.payment-totals').innerText())&&/0,00/.test(await p.locator('.payment-totals').innerText())&&await p.locator('#manual-payment').count()===0,'second partial payment completes invoice and removes further payment form');
  const links=await p.locator('.allocation-history-row a[href*="transaction="]').evaluateAll(a=>a.map(el=>el.href));good(links.length===2,'exactly two actual payment allocations remain');await p.goto(links[0]);good((await p.locator('.transaction-summary').innerText()).includes('Manuell erfasst'),'transaction displays manual provenance');good(errors.length===0,'no browser script errors');
 }finally{fs.writeFileSync(path.join(out,'browser.json'),JSON.stringify({checks,errors},null,2));await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
