'use strict';
const {chromium}=require('playwright'),fs=require('fs'),path=require('path'),assert=require('assert/strict'),{spawnSync}=require('child_process');
(async()=>{
  const fixture=process.env.KULTURHUS_MEMBER_PROFILE_FIXTURE,python=process.env.KULTURHUS_TEST_PYTHON;
  assert(fixture&&python);assert.equal(process.env.VEREIN_MODE,'synthetic');assert(!process.env.VEREIN_DATABASE);
  const f=JSON.parse(fs.readFileSync(fixture,'utf8').replace(/^\uFEFF/,'')),base=new URL(f.base),output=path.dirname(fixture);
  assert(['127.0.0.1','localhost'].includes(base.hostname)&&base.port==='8091'&&f.club.startsWith('member-profile-'));
  const browser=await chromium.launch({channel:'msedge',headless:true}),context=await browser.newContext({viewport:{width:1280,height:960}});
  await context.addCookies([{name:'KH_VEREIN_DEMO',value:f.sessions.admin,url:base.origin+'/'}]);
  const localRequests=r=>{
    const req=r.request(),url=new URL(req.url());if(url.origin!==base.origin)return r.abort();
    if(req.method()!=='GET'){
      assert.equal(url.searchParams.get('view'),'settings');
      const raw=req.postData()||'';
      if((req.headers()['content-type']||'').includes('multipart/form-data')){
        assert(/name="action"\r\n\r\nsettings-save\r\n/.test(raw));assert(/name="section"\r\n\r\n(?:club|paper)\r\n/.test(raw));
      }else{const data=new URLSearchParams(raw);assert.equal(data.get('action'),'settings-save');assert(['club','paper'].includes(data.get('section')));}
    }
    return r.continue();
  };await context.route('**/*',localRequests);
  const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));let n=0;
  const check=(v,msg)=>{assert(v,msg);console.log('PASS '+msg);n++;};
  const open=async(query)=>page.goto(new URL(query,base).href);
  const field=(key)=>page.locator(`[name="data[${key}]"]`);
  const sender=()=>page.locator('[data-document-sender]');
  async function save(){const form=page.locator('[data-settings-form]');await Promise.all([page.waitForNavigation(),form.locator('button').filter({hasText:/^Speichern$/}).last().click()]);assert((await page.locator('body').innerText()).includes('Einstellungen gespeichert.'));}
  async function pdf(label,expected){
    const payload=await page.locator('[data-paper-editor]').evaluate(el=>Object.fromEntries([...new FormData(el).entries()].filter(([,v])=>typeof v==='string').concat([['intent','pdf']])));
    const response=await context.request.post(page.url(),{form:payload});
    if(!(response.headers()['content-type']||'').includes('application/pdf'))fs.writeFileSync(path.join(output,'paper-sender-error.html'),await response.body());
    assert.equal(response.status(),200);assert((response.headers()['content-type']||'').includes('application/pdf'));
    const file=path.join(output,'paper-sender-'+label+'.pdf');fs.writeFileSync(file,await response.body());
    const result=spawnSync(python,['-c','import sys;from pypdf import PdfReader;print("\\n".join(p.extract_text() or "" for p in PdfReader(sys.argv[1]).pages))',file],{encoding:'utf8',env:{...process.env,PYTHONIOENCODING:'utf-8'}});assert.equal(result.status,0,result.stderr);
    for(const text of expected)assert(result.stdout.includes(text),label+' PDF missing '+text);
    check(true,label+' actual HTTP PDF contains resolved sender');return file;
  }
  try{
    await open('?view=settings&section=club');
    for(const [k,v] of Object.entries({name:'Synthetic Address Club',street:'Testweg 17a',address2:'Raum B',postcode:'01234',city:'Musterort'}))await field(k).fill(v);
    await save();await open('?view=settings&tab=paper');
    check(await field('sender_source').inputValue()==='club','fresh paper uses club address');
    check(await page.locator('[data-paper-club-sender]').isVisible()&&!await field('sender_street').isVisible(),'central address shown without duplicate address entry');
    check((await sender().innerText()).includes('Testweg 17a')&&(await sender().innerText()).includes('01234 Musterort')&&(await sender().innerText()).includes('Raum B'),'complete central sender rendered');
    check(await page.getByRole('link',{name:'Vereinsdaten bearbeiten'}).count()===1,'one clear link to edit central address');
    const initial=await pdf('initial',['Synthetic Address Club','Testweg 17a','Raum B','01234 Musterort']),initialBytes=fs.readFileSync(initial);
    await field('footer_left').fill('Synthetic preserved footer');await save();
    await open('?view=settings&section=club');await field('street').fill('Neuer Weg 29');await field('postcode').fill('54321');await field('city').fill('Neustadt');await save();
    await open('?view=settings&tab=paper');
    check((await sender().innerText()).includes('Neuer Weg 29')&&(await sender().innerText()).includes('54321 Neustadt'),'changed club address appears in new preview without paper save');
    check(await field('sender_street').inputValue()===''&&await field('footer_left').inputValue()==='Synthetic preserved footer','no duplicated central address stored and unrelated footer preserved');
    await pdf('updated',['Neuer Weg 29','54321 Neustadt']);
    check(fs.readFileSync(initial).equals(initialBytes),'earlier generated PDF unchanged');
    await field('sender_source').selectOption('custom');
    check(await field('sender_street').isVisible()&&!await page.locator('[data-paper-club-sender]').isVisible(),'custom choice opens address fields');
    for(const[k,v]of Object.entries({sender_name:'Synthetic Post Office',sender_street:'Briefweg 44',sender_address2:'Zimmer C',sender_city:'98765 Briefstadt'}))await field(k).fill(v);
    check((await sender().innerText()).includes('Briefweg 44')&&!(await sender().innerText()).includes('Neuer Weg 29'),'unsaved custom choice updates live preview');
    await pdf('custom',['Synthetic Post Office','Briefweg 44','Zimmer C','98765 Briefstadt']);await save();
    check(await field('sender_source').inputValue()==='custom'&&await field('sender_street').inputValue()==='Briefweg 44','custom choice and address survive real save/reload');
    await field('sender_source').selectOption('club');await save();
    check(await field('sender_source').inputValue()==='club'&&await field('sender_street').inputValue()==='Briefweg 44'&&!await field('sender_street').isVisible(),'switching back preserves hidden custom address');
    await field('sender_source').selectOption('custom');
    check(await field('sender_street').inputValue()==='Briefweg 44','custom address can be restored without retyping');
    await field('sender_street').fill('Unsaved Draftweg 55');await page.locator('[name=version]').evaluate(n=>n.value='0');
    await Promise.all([page.waitForNavigation(),page.locator('button[name=intent][value=save]').click()]);
    check(await field('sender_source').inputValue()==='custom'&&await field('sender_street').inputValue()==='Unsaved Draftweg 55'&&await field('sender_street').isVisible(),'failed stale save retains custom source and visible draft');
    await open('?view=settings&tab=paper');
    check(await field('sender_source').inputValue()==='club'&&await field('sender_street').inputValue()==='Briefweg 44','failed stale save changes no saved settings');
    await page.setViewportSize({width:390,height:900});
    check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'390px no horizontal page overflow');
    await page.screenshot({path:path.join(output,'paper-sender-390.png'),fullPage:true,animations:'disabled'});
    await field('sender_source').selectOption('custom');
    check(await field('sender_street').isVisible()&&await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'custom address fields usable on 390px');
    check(errors.length===0,'no JavaScript errors');
    const nojs=await browser.newContext({javaScriptEnabled:false});await nojs.route('**/*',localRequests);await nojs.addCookies([{name:'KH_VEREIN_DEMO',value:f.sessions.admin,url:base.origin+'/'}]);
    const basic=await nojs.newPage();await basic.goto(new URL('?view=settings&tab=paper',base).href);
    await basic.locator('[name="data[sender_source]"]').selectOption('custom');await basic.locator('[data-paper-sender-custom] summary').click();await basic.locator('[name="data[sender_street]"]').fill('Ohne JS Weg 66');
    await Promise.all([basic.waitForNavigation(),basic.locator('button[name=intent][value=save]').click()]);
    check(await basic.locator('[name="data[sender_source]"]').inputValue()==='custom'&&await basic.locator('[name="data[sender_street]"]').inputValue()==='Ohne JS Weg 66','source and custom sender save without JavaScript');
    await nojs.close();console.log(n+' paper sender browser checks passed; isolated synthetic settings only, no mail');
  }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
