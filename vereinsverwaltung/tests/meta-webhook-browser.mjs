import fs from 'node:fs';
import crypto from 'node:crypto';
const f=JSON.parse(fs.readFileSync(0,'utf8'));
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE);
const browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE,headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
let checks=0,posts=0;
const check=(ok,label)=>{if(!ok)throw new Error(label);checks++;};
const url=(mode,extra={})=>f.origin+(mode==='integrated'?'/vereinsverwaltung/':'/')+'?'+new URLSearchParams(mode==='integrated'?{view:'website',tab:'integrations',...extra}:{view:'settings',...extra});
const callback=(mode)=>f.origin+(mode==='integrated'?'/own-public-integrated':'')+'/meta-webhook/'+f.accounts[mode].callback;
const form=p=>p.locator('#meta-webhook-settings form').filter({has:p.locator('[name=operation][value=meta-webhook-save]')});
async function client(mode,js){
  const c=await browser.newContext({viewport:{width:390,height:1000},hasTouch:true,javaScriptEnabled:js});
  await c.route('**/*',r=>new URL(r.request().url()).origin===f.origin?r.continue():r.abort('blockedbyclient'));
  const p=await c.newPage();p.on('request',r=>{if(r.method()==='POST')posts++;});
  p.on('dialog',d=>d.type()==='beforeunload'?d.accept():d.dismiss());
  await p.goto(f.origin+'/own-test-enter?mode='+mode);return {c,p};
}
async function opened(p){
  const panel=p.locator('#meta-webhook-settings');await panel.waitFor({state:'attached'});
  if(!await panel.getAttribute('open').then(v=>v!==null))await panel.locator(':scope > summary').tap();
  await form(p).waitFor();return panel;
}
async function saved(p,version){
  try{await p.waitForFunction(v=>Number(document.querySelector('#meta-webhook-settings [name=version]')?.value)===v,version);}
  catch(e){await p.screenshot({path:'/tmp/meta-webhook-save-failure.png',fullPage:true});console.log('Own save failure: '+await p.locator('[role=alert],#notice').allTextContents());throw e;}
  await opened(p);
}
async function fit(p,label){
  await p.waitForLoadState('load');
  await p.waitForTimeout(150);
  const ok=await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1);
  if(!ok)await p.screenshot({path:'/tmp/meta-webhook-overflow.png',fullPage:true});
  check(ok,'actual webhook settings fit '+label);
}
try{
  // No manager cookies, form CSRF or trusted JSON text can stand in for HMAC.
  const anonymous=await browser.newContext();
  for(const mode of ['native','integrated']){
    const a=f.accounts[mode],base=callback(mode);
    const challenge=await anonymous.request.get(base,{params:{'hub.mode':'subscribe','hub.verify_token':a.verify,'hub.challenge':'12345678'}});
    check(challenge.status()===200&&await challenge.text()==='12345678'&&challenge.headers()['cache-control']==='no-store','actual anonymous '+mode+' callback returns exact valid Meta challenge');
    check((await anonymous.request.get(base,{params:{'hub.mode':'subscribe','hub.verify_token':'wrong','hub.challenge':'1'}})).status()===403,'actual '+mode+' wrong verification token never succeeds');
    check((await anonymous.request.head(base)).status()===405,'actual '+mode+' callback rejects HEAD without a challenge or receipt');
    check((await anonymous.request.get(base+'/wrong.path')).status()===404,'actual '+mode+' malformed callback is owned and returns 404');
    const bytes=JSON.stringify({object:'page',entry:[{id:'123',messaging:[{sender:{id:'789'},recipient:{id:'123'},timestamp:1750000999999,message:{mid:'own_actual_http_message',text:'Untrusted notification never becomes a sent reply'}}]}]});
    const signature='sha256='+crypto.createHmac('sha256',a.secret).update(bytes).digest('hex');
    const send=()=>anonymous.request.post(base,{data:bytes,headers:{'content-type':'application/json','x-hub-signature-256':signature}});
    check((await anonymous.request.post(base,{data:bytes})).status()===403,'actual '+mode+' missing HMAC is rejected before enqueue');
    const accepted=await send();check(accepted.status()===200&&(await accepted.json()).accepted===1,'actual '+mode+' signed HTTP body creates one own original receipt');
    const repeat=await send();check(repeat.status()===200&&(await repeat.json()).accepted===0,'actual '+mode+' provider retry acknowledges without a duplicate');
    check((await anonymous.request.post(base,{data:bytes+' ',headers:{'x-hub-signature-256':signature}})).status()===403,'actual '+mode+' signature binds original body bytes');
    check((await anonymous.request.post(base,{data:'x'.repeat(1024*1024+1),headers:{'x-hub-signature-256':signature}})).status()===413,'actual '+mode+' bounded callback rejects oversized transport');
    const foreign=callback(mode==='native'?'integrated':'native').split('/').pop();
    check((await anonymous.request.get(base.replace(a.callback,foreign),{params:{'hub.mode':'subscribe','hub.verify_token':a.verify,'hub.challenge':'1'}})).status()===403,'actual '+mode+' callback cannot cross its club');
  }
  await anonymous.close();
  for(const mode of ['native','integrated'])for(const js of [true,false]){
    console.log('Checking actual webhook settings '+mode+'/'+js);
    const {c,p}=await client(mode,js),errors=[];p.on('pageerror',e=>errors.push(e.message));
    await p.goto(url(mode));const panel=await opened(p);
    check(!(await panel.locator(':scope > summary').innerText()).includes('bereit'),'configuration alone does not claim verified provider availability');
    check(await form(p).locator('[name=app_secret]').inputValue()===''&&await form(p).locator('[name=verify_token]').inputValue()==='','real settings never echo stored app keys');
    const initial=Number(await form(p).locator('[name=version]').inputValue());
    await form(p).locator('[name=app_secret]').fill('invalid-secret');
    await form(p).getByRole('button',{name:'Empfang speichern',exact:true}).tap();
    await p.waitForFunction(()=>!!document.querySelector('[role=alert],.error,.alert'));
    check(Number(await form(p).locator('[name=version]').inputValue())===initial,'real invalid save retains exact original concurrency version');
    check(!(await p.content()).includes('value="invalid-secret"'),'real error response never renders a submitted secret');
    await form(p).locator('[name=app_secret]').fill('');
    await form(p).getByRole('button',{name:'Empfang speichern',exact:true}).tap();await saved(p,initial+1);
    check(await form(p).locator('[name=app_secret]').inputValue()==='','real accepted key retention creates one version with blank form secrets');
    // A stale form must not silently accept a second configuration.
    await form(p).locator('[name=version]').evaluate((el,v)=>el.value=String(v),initial);
    await form(p).getByRole('button',{name:'Empfang speichern',exact:true}).tap();
    await p.waitForFunction(()=>document.body.innerText.includes('inzwischen geändert'));
    check(Number(await form(p).locator('[name=version]').inputValue())===initial,'real rejected stale save preserves the version the user submitted');
    await p.goto(url(mode,{webhook_page:3}));await opened(p);
    check(await p.locator('#meta-webhook-settings article').count()===2,'actual direct selection reaches oldest of fourteen own original receipts');
    check(await p.locator('#meta-webhook-settings').getAttribute('open')!==null,'actual page selection keeps receiver panel open');
    const pageForm=p.locator('#meta-webhook-settings form[method=get]');
    await pageForm.locator('[name=webhook_page]').fill('2');await pageForm.getByRole('button',{name:'Seite öffnen',exact:true}).tap();
    await p.waitForURL(/webhook_page=2/);await opened(p);
    check(await p.locator('#meta-webhook-settings article').count()===6,'actual direct numeric page form opens six different summaries');
    check(!(await p.locator('#meta-webhook-settings').innerText()).includes('Never use raw notification'),'bounded receipt summary excludes private notification content');
    await p.goto(url(mode,{webhook_focus:f.accounts[mode].paused}));await opened(p);
    const target=p.locator('#webhook-'+f.accounts[mode].paused);
    if(await target.locator('[name=operation][value=meta-webhook-resume]').count()){
      await target.getByRole('button',{name:'Abgleich erneut prüfen',exact:true}).tap();
      await p.waitForFunction(id=>document.querySelector('#webhook-'+id)?.innerText.includes('Abgleich steht aus'),f.accounts[mode].paused);
      check(true,'actual own resume returns focus to exact original without a provider write');
    }
    if(js){
      await p.goto(url(mode));await opened(p);await form(p).locator('[name=enabled]').uncheck();
      await form(p).getByRole('button',{name:'Empfang speichern',exact:true}).tap();await saved(p,initial+2);
      check((await c.request.get(callback(mode),{params:{'hub.mode':'subscribe','hub.verify_token':f.accounts[mode].verify,'hub.challenge':'1'}})).status()===403,'actual optional switch immediately disables anonymous receiver');
      await form(p).locator('[name=enabled]').check();await form(p).getByRole('button',{name:'Empfang speichern',exact:true}).tap();await saved(p,initial+3);
      check((await c.request.get(callback(mode),{params:{'hub.mode':'subscribe','hub.verify_token':f.accounts[mode].verify,'hub.challenge':'1'}})).status()===200,'actual optional receiver can be enabled again with retained private keys');
    }
    const fields=await form(p).evaluate(el=>Object.fromEntries(new FormData(el)));
    const rejected=await c.request.post(url(mode),{form:{...fields,csrf:'wrong-own-test-csrf'}});
    check((await rejected.text()).includes('Formular abgelaufen'),'actual '+mode+' manager mutation still requires its own form CSRF');
    const foreign=f.accounts[mode==='native'?'integrated':'native'].paused;
    const foreignResume=await c.request.post(url(mode),{form:{...fields,operation:'meta-webhook-resume',receipt_id:foreign}});
    check((await foreignResume.text()).includes('Eigenen offenen Eingang auswählen'),'actual '+mode+' manager cannot resume another club receipt');
    for(const width of [320,390,820])for(const scale of [1,2]){
      await p.setViewportSize({width,height:1000});await p.goto(url(mode,{webhook_page:2}));await opened(p);
      if(scale===2)await p.evaluate(()=>document.documentElement.style.fontSize='200%');
      await fit(p,mode+'/'+js+'/'+width+'/'+scale);
      check((await form(p).getByRole('button',{name:'Empfang speichern',exact:true}).boundingBox()).height>=44,'actual receiver touch save remains at least 44 pixels');
    }
    check(errors.length===0,'actual '+mode+' webhook settings have no script error');await c.close();
  }
  console.log(JSON.stringify({browser_checks:checks,actual_posts:posts,viewport_states:24,external_actions:0}));
}finally{await browser.close();}
