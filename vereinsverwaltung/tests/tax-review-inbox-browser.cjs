'use strict';
const {chromium}=require('playwright'),fs=require('fs'),assert=require('assert/strict'),path=require('path');
const fixturePath=process.argv[2];if(!fixturePath)throw new Error('Pass a local synthetic fixture JSON');
const fixture=JSON.parse(fs.readFileSync(fixturePath,'utf8').replace(/^\uFEFF/,''));
const base=new URL(fixture.base);
assert.equal(base.protocol,'http:');assert(['localhost','127.0.0.1'].includes(base.hostname));assert(fixture.club.startsWith('tax-inbox-ui-'));
(async()=>{
 const browser=await chromium.launch({channel:'msedge',headless:true});
 try{
  for(const width of [1280,390]){
   const context=await browser.newContext({viewport:{width,height:900}}),errors=[],writes=[];
   await context.addCookies([{name:'KH_VEREIN_DEMO',value:fixture.session,url:base.origin+'/'}]);
   await context.route('**/*',route=>{const request=route.request(),url=new URL(request.url());if(url.origin!==base.origin)return route.abort();if(!['GET','HEAD'].includes(request.method())){writes.push(request.method());return route.abort();}return route.continue();});
   const page=await context.newPage();page.on('pageerror',error=>errors.push(error.message));
   const queue=base.origin+'/?view=finance&tab=tax&tax_review=1&year=2025';
   await page.goto(queue);await page.getByRole('heading',{name:'Kassenprüfung – Nacharbeiten'}).waitFor();
   assert.equal(await page.locator('article').count(),2);
   await page.getByRole('heading',{name:'Synthetische Jahresrechnung',exact:true}).waitFor();
   await page.getByRole('heading',{name:'Rechnungsjahr noch prüfen',exact:true}).waitFor();
   assert.match(await page.locator('#tax-inbox-undated').innerText(),/keinem Steuerjahr zugeordnet/);
   const unknown=page.locator('article').filter({has:page.getByRole('heading',{name:'Synthetische Rechnung Jahr ungeklärt',exact:true})});
   assert.match(await unknown.innerText(),/Dokumentdatum 2026-09-08.*Betrag noch prüfen/);
   assert.equal(await page.locator('.tax-review-queue').evaluate(element=>element.scrollWidth<=innerWidth),true);
   assert.equal(await page.locator('.tax-review-queue input,.tax-review-queue select,.tax-review-queue button,.tax-review-queue .button').evaluateAll(elements=>elements.filter(element=>element.getClientRects().length).every(element=>element.getBoundingClientRect().height>=44)),true);
   assert.equal(await page.getByRole('link',{name:'Vorgang ansehen',exact:true}).count(),2);
   assert.equal(await page.getByRole('link',{name:'Rechnung prüfen und erfassen',exact:true}).count(),0);
   await page.screenshot({path:path.join(path.dirname(fixturePath),'tax-review-inbox-'+width+'.png'),fullPage:true});
   await page.getByRole('link',{name:'Vorgang ansehen',exact:true}).first().click();
   await page.getByRole('heading',{name:'Rechnung prüfen · Synthetische Jahresrechnung',exact:true}).waitFor();
   assert.equal(new URL(page.url()).searchParams.get('inbox_doc'),fixture.known);
   await page.goBack();await page.locator('input[name=q]').fill('ungeklärt');await page.getByRole('button',{name:'Liste anzeigen',exact:true}).click();
   await page.getByRole('heading',{name:'Keine Treffer im gewählten Jahr',exact:true}).waitFor();
   assert.equal(await page.locator('article').count(),1);assert.equal(new URL(page.url()).searchParams.get('year'),'2025');
   assert.equal(await page.locator('input[name=q]').inputValue(),'ungeklärt');
   await page.locator('select[name=year]').selectOption('2026');await page.getByRole('button',{name:'Liste anzeigen',exact:true}).click();
   await page.getByRole('heading',{name:'Rechnungsjahr noch prüfen',exact:true}).waitFor();
   assert.equal(new URL(page.url()).searchParams.get('year'),'2026');assert.equal(await page.locator('article').count(),1);
   assert.deepEqual(writes,[]);assert.deepEqual(errors,[]);
   console.log('PASS '+width+'px: dated + unknown invoices, safe amount/date, permissions, direct link, shared search/year, touch targets, no overflow or writes');
   await context.close();
  }
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
