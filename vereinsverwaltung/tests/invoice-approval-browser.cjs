const {chromium}=require('playwright');const fs=require('node:fs');const assert=require('node:assert/strict');
(async()=>{
 const fixture=JSON.parse(fs.readFileSync(process.env.KULTURHUS_APPROVAL_FIXTURE,'utf8').replace(/^\uFEFF/,''));
 const browser=await chromium.launch({headless:true,channel:'msedge'});try{
  const context=await browser.newContext({viewport:{width:1280,height:1000}});await context.addCookies([{name:'KH_VEREIN_DEMO',value:fixture.session,url:'http://127.0.0.1:8091/'}]);const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto('http://127.0.0.1:8091/?view=finance&tab=receipts&entry='+fixture.entry);
  const section=page.locator('section').filter({has:page.getByRole('heading',{name:'Rechnung prüfen und freigeben',exact:true})});await section.getByText('Noch nicht freigegeben',{exact:true}).waitFor();
  await section.locator('[name=reviewed]').check();await section.getByRole('button',{name:'Rechnung freigeben',exact:true}).click();await section.getByText('Freigegeben',{exact:true}).first().waitFor();await page.reload();await section.getByText('Freigegeben',{exact:true}).first().waitFor();
  for(const width of [1280,390]){await page.setViewportSize({width,height:1000});assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'overflow '+width);assert(await section.isVisible());}
  await section.getByText('Freigabe zurücknehmen',{exact:true}).click();await section.locator('[name=reason]').fill('Fiktive Rückfrage zur Rechnung');await section.getByRole('button',{name:'Freigabe mit Grund zurücknehmen',exact:true}).click();await section.getByText('Freigabe zurückgenommen',{exact:true}).waitFor();await page.reload();await section.getByText('Freigabe zurückgenommen',{exact:true}).waitFor();
  await section.getByText('Freigabeverlauf · 2 Entscheidungen',{exact:true}).click();assert(await section.getByText('Fiktive Rückfrage zur Rechnung',{exact:true}).isVisible());await section.getByText('Geprüften Stand anzeigen',{exact:true}).first().click();assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'expanded history overflow');assert.deepEqual(errors,[]);console.log('PASS explicit approval/reload/withdraw/reload/history, 1280/390px without overflow and no browser errors');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
