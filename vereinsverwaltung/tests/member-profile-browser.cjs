'use strict';
// Real local application roundtrips; fresh synthetic tenant required. No production or mail actions.
const {chromium}=require('playwright');
const fs=require('node:fs'), assert=require('node:assert/strict'), path=require('node:path');
const {spawnSync}=require('node:child_process');
(async()=>{
 const file=process.env.KULTURHUS_MEMBER_PROFILE_FIXTURE,php=process.env.KULTURHUS_TEST_PHP;
 assert(file&&php,'Explicit fixture and PHP paths required.');
 const fixture=JSON.parse(fs.readFileSync(file,'utf8').replace(/^\uFEFF/,'')),base=new URL(fixture.base);
 assert(['localhost','127.0.0.1','[::1]'].includes(base.hostname)&&base.port==='8091'&&fixture.club.startsWith('member-profile-'),'Loopback synthetic tenant only.');
 assert.equal(process.env.VEREIN_MODE,'synthetic');assert(!process.env.VEREIN_DATABASE);assert.notEqual(process.env.VEREIN_AUTH,'processwire');
 const read=()=>{const result=spawnSync(php,[fixture.readback,file],{encoding:'utf8',env:{...process.env,KULTURHUS_DISABLE_MAIL:'1'}});assert.equal(result.status,0,result.stderr);return JSON.parse(result.stdout);};
 const contact=(state,id)=>state.contacts.find(row=>row.id===id);
 const member=(state,id)=>state.memberships.filter(row=>row.contact_id===id);
 const browser=await chromium.launch({headless:true,channel:process.env.KULTURHUS_BROWSER_CHANNEL||'msedge'}),checks=[],failures=[],errors=[];
 const context=await browser.newContext({viewport:{width:1280,height:960}});
 context.setDefaultTimeout(10000);
 await context.addCookies([{name:'KH_VEREIN_DEMO',value:fixture.sessions.admin,url:base.origin+'/'}]);
 await context.route('**/*',route=>new URL(route.request().url()).origin===base.origin?route.continue():route.abort());
 const page=await context.newPage();page.on('pageerror',error=>errors.push(error.message));let posts=0;
 page.on('request',request=>{if(request.method()==='POST')posts++;});
 const profile=()=>page.locator('#profile-form');
 const field=(form,name)=>form.locator('[name="'+name+'"]');
 const form=action=>page.locator('form').filter({has:page.locator('input[name=action][value="'+action+'"]')});
 const panel=name=>page.locator('[data-member-panel="'+name+'"]');
 const openAncestors=async locator=>{const indexes=await locator.evaluate(node=>{const all=[...document.querySelectorAll('details')],result=[];for(let parent=node.parentElement;parent;parent=parent.parentElement)if(parent.tagName==='DETAILS'&&!parent.open)result.push(all.indexOf(parent));return result.reverse();});for(const index of indexes)await page.locator('details').nth(index).locator(':scope > summary').click();};
 const set=async(f,name,value)=>{const el=field(f,name);await openAncestors(el);const type=await el.evaluate(node=>node.tagName==='SELECT'?'select':node.type);if(type==='select')await el.selectOption(value,{force:true});else if(type==='checkbox')await el.setChecked(Boolean(value));else await el.fill(value);};
 const closeExtra=async()=>{if(await panel('extra').evaluate(node=>node.open))await panel('extra').locator(':scope > summary').click();};
 const visit=async query=>{const response=await page.goto(base.origin+'/?'+query);assert.equal(response.headers()['x-kulturhus-local-project'],fixture.project_hash,'This checkout must serve every test page.');assert.equal(response.status(),200);await page.waitForFunction(()=>!!window.KulturhusFormValidation);};
 const open=async id=>{await visit('view=members&id='+id);await profile().waitFor();};
 const errorText=async()=>(await page.locator('.error[role=alert]').allTextContents()).join('\n');
 const submitProfile=async()=>{const button=page.locator('button[form="profile-form"]').last();await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),button.click()]);};
 const submit=async(action,label)=>{const button=form(action).getByRole('button',{name:label,exact:true});await openAncestors(button);await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),button.click()]);};
 const run=async(name,callback)=>{try{await callback();checks.push(name);console.log('PASS '+name);}catch(error){failures.push({name,error:error.message});console.error('FAIL '+name+': '+error.message);}};
 const initial=read();let createdMember='',createdContact='';
 try{
  await run('Bestehendes Mitglied: Stammdaten und eingeklappte Zusatzfelder speichern und neu laden',async()=>{
   await open(fixture.member);assert.equal(await panel('extra').count(),1);assert.equal(await panel('membership').count(),1);
   const values={name:'Synthetic Updated Member',email:'updated@example.invalid',phone:'01234 222333',address:'Synthetic Straße 18\n54321 Musterort',legacy_member_number:'SYN-OLD-UPDATED',birthday:'1960-05-06',notes:'Synthetic saved hidden detail note',honorary:true,['custom_fields['+fixture.custom+']']:'Synthetic saved extra reference'};
   for(const[name,value]of Object.entries(values))await set(profile(),name,value);await closeExtra();
   const before=read();await submitProfile();assert.equal(await errorText(),'');await open(fixture.member);const after=read(),saved=contact(after,fixture.member);
   for(const[name,value]of Object.entries(values)){if(name.startsWith('custom_fields'))assert.equal(JSON.parse(saved.custom_fields)[fixture.custom].value,value);else assert.equal(String(saved[name]).replace(/\r\n/g,'\n'),name==='honorary'?'1':String(value));const el=field(profile(),name);assert.equal(await el.evaluate(node=>node.type==='checkbox'?node.checked:node.value),value,name+' reloaded');}
   assert.equal(saved.version,contact(before,fixture.member).version+1);assert.deepEqual(after.memberships,before.memberships);assert.deepEqual(after.member_families,before.member_families);assert.deepEqual(after.manual_member_accounts,before.manual_member_accounts);
  });
  await run('Name fehlt: klar markiert und fokussiert, keine Speicherung oder Änderung',async()=>{
   await open(fixture.member);await set(profile(),'name','');const before=read(),count=posts;await page.locator('button[form="profile-form"]').last().click();await page.locator('#profile-form .form-validation-summary').waitFor();
   assert.equal(posts,count);assert.equal(await field(profile(),'name').getAttribute('aria-invalid'),'true');assert(await field(profile(),'name').evaluate(node=>node===document.activeElement));assert.deepEqual(read(),before);
  });
  await run('Pflichtfeld im geschlossenen Zusatzabschnitt öffnet sich und behält alle Eingaben',async()=>{
   await open(fixture.member);await set(profile(),'custom_fields['+fixture.custom+']','');await set(profile(),'notes','Synthetic unsaved closed-panel note');await closeExtra();assert.equal(await panel('extra').evaluate(node=>node.open),false);
   const before=read(),count=posts;await page.locator('button[form="profile-form"]').last().click();const el=field(profile(),'custom_fields['+fixture.custom+']');await el.waitFor({state:'visible'});
   assert.equal(await panel('extra').evaluate(node=>node.open),true);assert.equal(await el.getAttribute('aria-invalid'),'true');assert(await el.evaluate(node=>node===document.activeElement));assert.equal(await field(profile(),'notes').inputValue(),'Synthetic unsaved closed-panel note');assert.equal(posts,count);assert.deepEqual(read(),before);
  });
  await run('Ungültiges Datum im eingeklappten Abschnitt wird sichtbar und verhindert POST',async()=>{
   await open(fixture.member);const future=String(new Date().getFullYear()+1)+'-01-01';await set(profile(),'birthday',future);await closeExtra();const before=read(),count=posts;await page.locator('button[form="profile-form"]').last().click();await field(profile(),'birthday').waitFor({state:'visible'});assert.equal(await field(profile(),'birthday').getAttribute('aria-invalid'),'true');assert.equal(posts,count);assert.deepEqual(read(),before);
  });
  await run('Veralteter echter POST erhält den vollständigen Entwurf und überschreibt nichts',async()=>{
   await open(fixture.member);await set(profile(),'name','Synthetic stale pending name');await set(profile(),'notes','Synthetic stale pending note');await set(profile(),'custom_fields['+fixture.custom+']','Synthetic stale pending reference');await closeExtra();await field(profile(),'version').evaluate(node=>node.value='0');
   const before=read();await submitProfile();assert.match(await errorText(),/inzwischen geändert/);assert.equal(await field(profile(),'name').inputValue(),'Synthetic stale pending name');assert.equal(await field(profile(),'notes').inputValue(),'Synthetic stale pending note');assert.equal(await field(profile(),'custom_fields['+fixture.custom+']').inputValue(),'Synthetic stale pending reference');assert.equal(await field(profile(),'version').inputValue(),'0');assert.deepEqual(read(),before);
  });
  await run('Firmenkontakt: leere optionale E-Mail und Zusatzdaten speichern ohne Mitgliedschaft',async()=>{
   await open(fixture.organisation);const before=read();await set(profile(),'name','Synthetic Updated Supplier');await set(profile(),'email','');await set(profile(),'phone','01234 999888');await set(profile(),'notes','Synthetic supplier note');await closeExtra();await submitProfile();assert.equal(await errorText(),'');await open(fixture.organisation);const state=read();assert.equal(contact(state,fixture.organisation).kind,'organisation');assert.equal(contact(state,fixture.organisation).email,'');assert.equal(contact(state,fixture.organisation).notes,'Synthetic supplier note');assert.equal(member(state,fixture.organisation).length,0);assert.deepEqual(state.memberships,before.memberships);
  });
  await run('Neuer Firmenkontakt: Anlegen ohne Mitgliedschaft und ohne Zugangsmail',async()=>{
   await visit('view=members&new=1&create_as=contact&kind=organisation');await profile().waitFor();assert.equal(await field(profile(),'create_as').inputValue(),'contact');assert.equal(await field(profile(),'kind').inputValue(),'organisation');
   await set(profile(),'name','Synthetic Newly Created Organisation');await set(profile(),'email','newcontact@example.invalid');await set(profile(),'address','Synthetic new contact address');await set(profile(),'notes','Synthetic new contact note');await set(profile(),'custom_fields['+fixture.custom+']','Synthetic new contact reference');await closeExtra();const before=read();await submitProfile();assert.equal(await errorText(),'');createdContact=new URL(page.url()).searchParams.get('id');assert(createdContact);await open(createdContact);const after=read();assert.equal(after.contacts.length,before.contacts.length+1);assert.equal(contact(after,createdContact).name,'Synthetic Newly Created Organisation');assert.equal(contact(after,createdContact).kind,'organisation');assert.equal(member(after,createdContact).length,0);assert.deepEqual(after.manual_member_accounts,before.manual_member_accounts);
  });
  await run('Wechsel Kontakt/Mitglied blendet Felder passend ein und erhält den vollständigen Entwurf',async()=>{
   await visit('view=members&new=1&create_as=member');const before=read();for(const[name,value]of Object.entries({name:'Synthetic toggled draft',number:'SYN-PENDING-77',start_precision:'year',start_year:'2020',family:'new',family_name:'Synthetic toggled family',notes:'Synthetic toggled note'}))await set(profile(),name,value);
   await set(profile(),'create_as','contact');assert.equal(await profile().locator('[data-new-membership]').isVisible(),false);assert(await field(profile(),'number').isDisabled());await set(profile(),'create_as','member');assert.equal(await profile().locator('[data-new-membership]').isVisible(),true);assert.equal(await field(profile(),'number').inputValue(),'SYN-PENDING-77');assert.equal(await field(profile(),'start_year').inputValue(),'2020');assert.equal(await field(profile(),'family_name').inputValue(),'Synthetic toggled family');assert.equal(await field(profile(),'notes').inputValue(),'Synthetic toggled note');assert.equal(await field(profile(),'start_year').isDisabled(),false);assert.equal(await field(profile(),'start').isDisabled(),true);assert.deepEqual(read(),before);
  });
  await run('Fehlgeschlagene Neuanlage erhält Mitgliedschaft und Familie ohne verwaisten Kontakt',async()=>{
   await visit('view=members&new=1&create_as=member');const values={name:'Synthetic failed duplicate member',email:'failed@example.invalid',number:'SYN-44',status:'passiv',start_precision:'year',start_year:'2018',family:'new',family_name:'Synthetic retained duplicate family',notes:'Synthetic retained new member note',['custom_fields['+fixture.custom+']']:'Synthetic retained new reference'};for(const[name,value]of Object.entries(values))await set(profile(),name,value);await closeExtra();const before=read();await submitProfile();assert.match(await errorText(),/Mitgliedsnummer.*bereits vergeben/);for(const[name,value]of Object.entries(values))assert.equal(await field(profile(),name).inputValue(),value,name+' new draft retained');assert.equal(await field(profile(),'access_mail').inputValue(),'0');assert.deepEqual(read(),before);
  });
  await run('Neues Mitglied: Angaben, Datum, neue Familie und eingeklappte Notizen gemeinsam gespeichert',async()=>{
   await visit('view=members&new=1&create_as=member');await profile().waitFor();const values={name:'Synthetic Newly Created Member',email:'newmember@example.invalid',address:'Synthetic new member address',number:'SYN-NEW-55',status:'passiv',start_precision:'day',start:'2024-03-02',family:'new',family_name:'Synthetic Newly Created Family',notes:'Synthetic new member note',['custom_fields['+fixture.custom+']']:'Synthetic new member reference'};
   for(const[name,value]of Object.entries(values))await set(profile(),name,value);assert.equal(await field(profile(),'access_mail').inputValue(),'0');await closeExtra();const before=read();await submitProfile();assert.equal(await errorText(),'');createdMember=new URL(page.url()).searchParams.get('id');assert(createdMember);await open(createdMember);const after=read(),membership=member(after,createdMember)[0];assert.equal(after.contacts.length,before.contacts.length+1);assert.equal(membership.member_number,'SYN-NEW-55');assert.equal(membership.starts_on,'2024-03-02');assert.equal(membership.status,'passiv');assert.equal(contact(after,createdMember).notes,'Synthetic new member note');assert(after.member_families.some(row=>row.name==='Synthetic Newly Created Family'&&row.payer_contact_id===createdMember));const access=after.manual_member_accounts.find(row=>row.contact_id===createdMember);assert.equal(access.send_mail,0);assert.equal(access.mail_status,'disabled');
  });
  await run('Vorhandenen Kontakt als Mitglied übernehmen: Eintrittsjahr und bestehende Familie erhalten',async()=>{
   await open(fixture.contact);const join=form('join');await set(join,'number','SYN-JOIN-66');await set(join,'start_precision','year');await set(join,'start_year','2019');await set(join,'family',fixture.family);assert.equal(await field(join,'access_mail').inputValue(),'0');const before=read();await submit('join','Mitgliedschaft anlegen');assert.equal(await errorText(),'');await open(fixture.contact);const after=read(),membership=member(after,fixture.contact)[0];assert.equal(after.contacts.length,before.contacts.length);assert.equal(membership.member_number,'SYN-JOIN-66');assert.equal(membership.starts_on,'2019-01-01');assert.equal(membership.starts_precision,'year');assert(after.member_family_people.some(row=>row.contact_id===fixture.contact&&row.family_id===fixture.family));
  });
  await run('Eintritt eines bestehenden Mitglieds bearbeiten und nach Neuladen richtig anzeigen',async()=>{
   await open(fixture.member);const start=form('membership-start');await set(start,'start_precision','day');await set(start,'start','2014-07-08');const before=read();await submit('membership-start','Eintritt speichern');assert.equal(await errorText(),'');await open(fixture.member);const after=read();assert.equal(member(after,fixture.member)[0].starts_on,'2014-07-08');assert.equal(member(after,fixture.member)[0].member_number,member(before,fixture.member)[0].member_number);assert.equal(await field(form('membership-start'),'start').inputValue(),'2014-07-08');
  });
  await run('Familienname und Beitragszahler im eigenen Formular tatsächlich speichern',async()=>{
   await open(fixture.member);const family=form('save-family');await set(family,'family_name','Synthetic Updated Family');await set(family,'payer',fixture.relative);const before=read();await submit('save-family','Familie speichern');assert.equal(await errorText(),'');await open(fixture.member);const after=read(),saved=after.member_families.find(row=>row.id===fixture.family);assert.equal(saved.name,'Synthetic Updated Family');assert.equal(saved.payer_contact_id,fixture.relative);assert.deepEqual(after.contacts,before.contacts);assert.deepEqual(after.memberships,before.memberships);assert.equal(await field(form('save-family'),'family_name').inputValue(),'Synthetic Updated Family');assert.equal(await field(form('save-family'),'payer').inputValue(),fixture.relative);
  });
  await run('Veraltete Familienänderung öffnet den richtigen Abschnitt und erhält Namen und Zahler',async()=>{
   await open(fixture.member);const family=form('save-family');await set(family,'family_name','Synthetic Pending Family');await set(family,'payer',fixture.member);await field(family,'version').evaluate(node=>node.value='0');const before=read();await submit('save-family','Familie speichern');assert.match(await errorText(),/inzwischen geändert/);assert.equal(await panel('membership').evaluate(node=>node.open),true);assert.equal(await field(form('save-family'),'family_name').inputValue(),'Synthetic Pending Family');assert.equal(await field(form('save-family'),'payer').inputValue(),fixture.member);assert.deepEqual(read(),before);
  });
  await run('Mobil 390px: Abschnitte bedienbar, Daten beim Ein-/Ausklappen erhalten, kein Seitenüberlauf',async()=>{
   await page.setViewportSize({width:390,height:844});await open(fixture.member);await set(profile(),'notes','Synthetic mobile unsaved note');await closeExtra();assert.equal(await field(profile(),'notes').inputValue(),'Synthetic mobile unsaved note');await panel('extra').locator(':scope > summary').click();assert.equal(await field(profile(),'notes').inputValue(),'Synthetic mobile unsaved note');
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'No horizontal page overflow.');assert.equal(await page.locator('form form').count(),0,'Do not nest independent forms.');assert.equal(await field(profile(),'notes').evaluate(node=>node.form.getAttribute('id')),'profile-form');
   const summaries=await page.locator('[data-member-panel] > summary').evaluateAll(nodes=>nodes.map(node=>({height:node.getBoundingClientRect().height,width:node.getBoundingClientRect().width})));assert(summaries.length>=2);assert(summaries.every(box=>box.height>=44&&box.width>200),'Large usable disclosure targets.');await page.screenshot({path:path.join(path.dirname(file),'mobile-member-profile.png'),fullPage:true});
  });
  await run('Mitglied mit eigenen Leserechten erhält keine Bearbeitungs- oder fremden Datenfelder',async()=>{
   const own=await browser.newContext({viewport:{width:1280,height:960}});await own.addCookies([{name:'KH_VEREIN_DEMO',value:fixture.sessions.own,url:base.origin+'/'}]);await own.route('**/*',route=>new URL(route.request().url()).origin===base.origin?route.continue():route.abort());try{const ownPage=await own.newPage();const response=await ownPage.goto(base.origin+'/?view=members&id='+fixture.member);assert.equal(response.headers()['x-kulturhus-local-project'],fixture.project_hash);assert.equal(await ownPage.locator('#profile-form').count(),0);assert.equal(await ownPage.locator('input[name=action][value=save-family]').count(),0);assert.match(await ownPage.locator('main').innerText(),/Synthetic Updated Member/);}finally{await own.close();}
  });
  await run('Keine versehentliche Finanzbuchung, Beleganlage oder aktivierte Zugangsmail',async()=>{
   const after=read();for(const key of ['finance_entries','finance_transactions','documents','document_links'])assert.deepEqual(after[key],initial[key],key+' unchanged');assert(after.manual_member_accounts.every(row=>row.send_mail===0&&row.mail_status==='disabled'&&!row.sent_at));assert.deepEqual(errors,[]);
   await page.setViewportSize({width:1280,height:960});await open(fixture.member);await page.screenshot({path:path.join(path.dirname(file),'desktop-member-profile.png'),fullPage:true});
  });
  const result={checks,failures,createdMember,createdContact,scope:'Unique synthetic local tenant; actual browser POST, fresh GET and query-only DB readback. No production, bank action or mail.'};fs.writeFileSync(path.join(path.dirname(file),'result.json'),JSON.stringify(result,null,2));console.log(JSON.stringify(result,null,2));if(failures.length)process.exitCode=1;
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
