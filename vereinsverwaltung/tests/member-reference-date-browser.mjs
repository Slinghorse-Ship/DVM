import fs from 'node:fs';
const fixture=JSON.parse(fs.readFileSync(0,'utf8'));
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE);
const browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE,headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
let checks=0,views=0;
const check=(ok,label)=>{if(!ok)throw Error(label);checks++;console.log('PASS '+label);};
const manager=fixture.origin+'/vereinsverwaltung/';
try{
 for(const js of [true,false]){
  const context=await browser.newContext({viewport:{width:390,height:950},javaScriptEnabled:js,acceptDownloads:true,serviceWorkers:'block'});
  await context.route('**/*',r=>new URL(r.request().url()).origin===fixture.origin?r.continue():r.abort());
  const page=await context.newPage();await page.goto(fixture.origin+'/own-test-enter?role=admin');await page.goto(manager+'?view=members&list=current');
  await page.locator('#filters summary').click();await page.getByLabel('Mitgliedschaft am Stichtag').fill('2021-07-01');
  await page.getByRole('button',{name:'Filter anwenden',exact:true}).click();await page.waitForURL(url=>url.searchParams.get('as_of')==='2021-07-01');await page.locator('[data-member-reference-date]').waitFor();
  check(new URL(page.url()).searchParams.get('as_of')==='2021-07-01','real reference-date GET form works '+js+' '+page.url());
  check(await page.locator('[data-member-reference-date]').count()===1,'actual report explains unknown original types '+js);
  const status=page.locator('.contact-table tbody tr').filter({hasText:'Own A exact active'});
  check((await status.innerText()).includes('DATE-A')&&(await status.innerText()).includes('Aktiv')&&!(await status.innerText()).includes('DATE-REJOIN'),'actual historical row preserves the original number and type '+js);
  check(await page.locator('.contact-table tbody tr').filter({hasText:'Own C old former'}).count()===1,'unknown historic type remains a confirmed member '+js);
  for(const width of [320,390,820,1280])for(const zoom of [100,200]){
   await page.setViewportSize({width,height:950});await page.evaluate(size=>document.documentElement.style.fontSize=size+'%',zoom);views++;
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'bounded mobile and large-text report '+js+' '+width+' '+zoom);
   check(await page.getByLabel('Mitgliedschaft am Stichtag').evaluate(el=>el.getBoundingClientRect().height>=43.9),'date control has a touch-sized target '+js+' '+width+' '+zoom);
  }
  await page.evaluate(()=>document.documentElement.style.fontSize='100%');await page.setViewportSize({width:390,height:950});
  await page.getByRole('link',{name:'Nächste Seite',exact:true}).click();await page.waitForURL(url=>url.searchParams.get('page')==='2');await page.waitForFunction(()=>document.querySelector('.pagination')?.textContent.includes('Seite 2'));
  check(new URL(page.url()).searchParams.get('as_of')==='2021-07-01'&&await page.getByLabel('Mitgliedschaft am Stichtag').inputValue()==='2021-07-01','real pagination retains the same date '+js);
  await page.locator('.search-form [name=q]').fill('Own A exact active');
  await page.locator('.search-form button').click();await page.waitForURL(url=>url.searchParams.get('q')==='Own A exact active');await page.waitForFunction(()=>document.querySelectorAll('.contact-table tbody tr').length===1);
  check(await page.locator('.contact-table tbody tr').count()===1&&await page.getByLabel('Mitgliedschaft am Stichtag').inputValue()==='2021-07-01','search retains the fixed date and returns the exact original '+js);
  await page.locator('.contact-table .profile-link').click();await page.locator('[data-member-current-profile]').waitFor();
  check(await page.locator('[data-member-current-profile]').textContent().then(s=>s.includes('aktuellen Stand')),'actual profile clearly distinguishes current data from the dated report '+js);
  await page.getByRole('link',{name:'Zur Mitgliederliste',exact:true}).click();await page.locator('.contact-table .profile-link').waitFor();
  check(await page.getByLabel('Mitgliedschaft am Stichtag').inputValue()==='2021-07-01'&&await page.locator('.search-form [name=q]').inputValue()==='Own A exact active','actual profile return keeps date and search '+js);
  await page.locator('.contact-table [name="selected[]"]').check();
  const rejected=await context.request.post(page.url(),{form:{action:'export',csrf:'invalid-own-original',as_of:'2021-07-01','selected[]':fixture.data.active,'columns[]':'name',export_format:'csv'}});check(rejected.status()===403,'actual original CSRF protects dated export '+js);
  const downloadPromise=page.waitForEvent('download');await page.getByRole('button',{name:'Als CSV',exact:true}).click();const download=await downloadPromise;
  const contents=fs.readFileSync(await download.path(),'utf8');check(contents.includes('DATE-A')&&contents.includes('2021-07-01')&&!contents.includes('DATE-REJOIN'),'real CSV POST downloads original selected period '+js);
  await page.locator('#save-list summary').click();await page.locator('#save-list [name=list_name]').fill('Browser fixed date '+js);
  await Promise.all([page.waitForNavigation(),page.locator('#save-list button').click()]);
  check(new URL(page.url()).searchParams.has('saved')&&await page.getByLabel('Mitgliedschaft am Stichtag').inputValue()==='2021-07-01','conscious real saved-list POST restores the fixed date '+js);
  await page.locator('#save-list summary').click();await page.locator('#save-list [name=list_name]').fill('Browser fixed date '+js);
  await Promise.all([page.waitForNavigation(),page.locator('#save-list button').click()]);
  check(await page.locator('#save-list [name=list_name]').inputValue()==='Browser fixed date '+js&&await page.getByLabel('Mitgliedschaft am Stichtag').inputValue()==='2021-07-01','rejected duplicate retains human name and original date '+js);
  await page.goto(manager+'?view=members&as_of=2021-02-29');
  check(await page.getByRole('alert').filter({hasText:'gültiges Kalenderdatum'}).count()>0,'invalid direct date produces a visible correction '+js);
  await context.close();
 }
 for(const role of ['reader','finance-only']){
  const context=await browser.newContext({viewport:{width:390,height:950},javaScriptEnabled:false});
  await context.route('**/*',r=>new URL(r.request().url()).origin===fixture.origin?r.continue():r.abort());const page=await context.newPage();
  await page.goto(fixture.origin+'/own-test-enter?role='+role);const response=await page.goto(manager+'?view=members&as_of=2021-07-01&list=current');
  check(await page.locator('#export-form [name=export_format]').count()===0,'current '+role+' never gets staff export controls');
  if(role==='reader')check(response.status()===200&&await page.locator('.contact-table tbody tr').count()>0,'current reader may inspect historical membership');
  else check(response.status()===403&&await page.locator('.contact-table tbody tr').count()===0,'finance role cannot inspect member originals');
  await context.close();
 }
 console.log(JSON.stringify({suite:'member-reference-date-browser',checks,views,external_actions:0}));
}finally{await browser.close();}
