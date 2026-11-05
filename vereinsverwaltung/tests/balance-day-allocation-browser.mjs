import fs from 'node:fs';
const fixture=JSON.parse(fs.readFileSync(0,'utf8'));
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE);
const browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE,headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
let checks=0,views=0,external=0;const errors=[];
const check=(ok,label)=>{if(!ok)throw Error(label);checks++;console.log('PASS '+label);};
const path='/vereinsverwaltung/?view=finance&tab=tax&balance=1&periods=1';
try{
 for(const [app,js,start,end]of[['front',true,'2025-01-01','2025-06-15'],['direct',false,'2025-06-16','2025-12-31']]){
  const origin=fixture.origins[app];const context=await browser.newContext({javaScriptEnabled:js,viewport:{width:390,height:1000},hasTouch:true,acceptDownloads:true,serviceWorkers:'block'});
  await context.route('**/*',route=>{if(new URL(route.request().url()).origin===origin)return route.continue();external++;return route.abort();});
  const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));page.on('response',r=>{if(r.status()>=400)console.log('HTTP '+r.status()+' '+new URL(r.url()).pathname);});
  await page.goto(origin+'/login');await page.locator('[name=email]').fill(fixture.email);await page.locator('[name=password]').fill(fixture.password);
  await Promise.all([page.waitForURL(url=>url.pathname==='/vereinsverwaltung/'),page.getByRole('button',{name:'Anmelden und Vereinsmanager öffnen',exact:true}).click()]);
  const response=await page.goto(origin+path);
  check(response.status()===200&&response.headers()['x-owned-period-mode']==='production'&&response.headers()['x-owned-period-dispatcher']===app,'actual '+app+' production controller uses personal native login');
  check((await context.cookies()).some(c=>c.name==='KH_NATIVE_MANAGER'&&c.secure),'actual '+app+' production session retains its Secure cookie');
  const formFor=action=>page.locator('form').filter({has:page.locator('input[name=action][value="'+action+'"]')});
  const openCreate=async()=>{const summary=page.getByText('Neuen Zeitraum anlegen',{exact:true});if(!await summary.evaluate(el=>el.closest('details').open))await summary.click();};await openCreate();
  let form=formFor('balance-period-create');const evidence='Eigener Teilmonat '+app+' ÄÖ {{Original}} <&>';
  await form.locator('[name=starts_on]').fill(start);await form.locator('[name=ends_on]').fill(end);await form.locator('[name=evidence]').fill(evidence);await form.locator('[name=reviewed]').check();
  const refused=await context.request.post(origin+path,{form:{action:'balance-period-create',csrf:'invalid-owned-day-csrf',starts_on:start,ends_on:end,evidence,reviewed:'1',day_allocation_reviewed:'1'},headers:{Origin:origin}});
  check(refused.status()>=400,'actual '+app+' day creation rejects forged CSRF');
  await Promise.all([page.waitForResponse(r=>r.request().method()==='POST'&&new URL(r.url()).pathname==='/vereinsverwaltung/'),form.getByRole('button',{name:'Zeitraum anlegen',exact:true}).click()]);
  await page.getByRole('alert').filter({hasText:'interne tageweise'}).waitFor();
  form=formFor('balance-period-create');
  const correction={alerts:await page.getByRole('alert').allTextContents(),start:await form.locator('[name=starts_on]').inputValue(),end:await form.locator('[name=ends_on]').inputValue(),evidence:await form.locator('[name=evidence]').inputValue()};
  check(correction.alerts.length>0&&correction.start===start&&correction.end===end&&correction.evidence===evidence,'actual '+app+' missing day review retains exact dates and evidence for correction');
  check(await page.locator('[name=period_id] option').count()=== (app==='front'?1:2),'actual '+app+' refused confirmation creates no period');
  await openCreate();await form.locator('[name=reviewed]').check();await form.locator('[name=day_allocation_reviewed]').check();
  for(const width of[320,390,820])for(const scale of[100,200]){
   await page.setViewportSize({width,height:1000});await page.evaluate(value=>document.documentElement.style.fontSize=value+'%',scale);views++;
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'day confirmation fits touch and enlarged text '+app+' '+width+' '+scale);
   check(await form.locator('[name=day_allocation_reviewed]').evaluate(el=>el.closest('label').getBoundingClientRect().height>=44),'day review has a touch-sized label '+app+' '+width+' '+scale);
  }
  await page.setViewportSize({width:390,height:1000});await page.evaluate(()=>document.documentElement.style.fontSize='100%');await form.locator('[name=day_allocation_reviewed]').focus();check(await form.locator('[name=day_allocation_reviewed]').evaluate(el=>el===document.activeElement),'day review is keyboard focusable '+app);
  await Promise.all([page.waitForNavigation(),form.getByRole('button',{name:'Zeitraum anlegen',exact:true}).click()]);
  const id=start.replaceAll('-','')+'-'+end.replaceAll('-','');await page.waitForURL(url=>url.searchParams.get('period_id')===id);await page.locator('[data-balance-day-policy]').waitFor();
  check((await page.locator('[data-balance-day-policy]').innerText()).includes('keine steuerliche Jahres-AfA'),'actual '+app+' journal shows the retained internal qualification boundary');
  const opening=formFor('balance-period-opening');if(await opening.count()){
   const amounts=opening.locator('[name^="opening_amounts["]');for(let i=0;i<await amounts.count();i++)await amounts.nth(i).fill('0,00');
   await opening.locator('[name=evidence]').fill('Eigene geprüfte Anfangsbestände');await opening.locator('[name=reviewed]').check();
   await Promise.all([page.waitForNavigation(),opening.getByRole('button',{name:'Anfangsbestände übernehmen',exact:true}).click()]);
  }
  await page.getByText('3. Abschluss bestätigen',{exact:true}).click();const closing=formFor('balance-period-close');
  for(const field of['complete','valuation','cutoff','tax'])await closing.locator('[name='+field+']').check();await closing.locator('[name=evidence]').fill('Eigene Prüfung interner Monatsoriginale '+app);
  await Promise.all([page.waitForNavigation(),closing.getByRole('button',{name:'Abschluss bestätigen',exact:true}).click()]);
  check(await page.getByText('Buchungsstand festgeschrieben',{exact:true}).count()===1&&await formFor('tax-filing-balance-create').count()===0&&await page.getByRole('link',{name:'Steuerwerte prüfen',exact:true}).count()===0,'actual '+app+' closing retains its original and offers no unqualified tax draft');
  await page.reload();check(await page.locator('[data-balance-day-policy]').count()===1&&await page.getByText('Buchungsstand festgeschrieben',{exact:true}).count()===1,'actual '+app+' closed internal original resumes after a new request');
  const downloadPromise=page.waitForEvent('download');await page.getByRole('link',{name:'Buchungsunterlagen herunterladen',exact:true}).click();const download=await downloadPromise;const bytes=fs.readFileSync(await download.path());check(bytes[0]===0x50&&bytes[1]===0x4b,'actual '+app+' protected original ZIP downloads with '+(js?'JavaScript':'no JavaScript'));
  await context.close();
 }
 check(errors.length===0,'both actual day workflows have no application JavaScript error');check(external===0,'both actual day workflows make no external request');
 console.log(JSON.stringify({suite:'balance-day-allocation-browser',checks,views,external_actions:0}));
}finally{await browser.close();}
