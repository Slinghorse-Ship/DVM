import fs from 'node:fs';
import crypto from 'node:crypto';
const f=JSON.parse(fs.readFileSync(0,'utf8'));
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'/workspace/.setup/browser/node_modules/playwright/index.mjs');
if(process.env.DVM_OUTGOING_INVOICE_CASE!==undefined&&!/^[0-7]$/.test(process.env.DVM_OUTGOING_INVOICE_CASE))throw Error('Choose an owned invoice case from zero to seven.');
const browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE||'/usr/bin/chromium',headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
let checks=0,views=0,cases=0,index=0,clients=0,external=0,stage='start';
const errors=[],originals=[];
const check=(ok,label)=>{if(!ok)throw Error(label);checks++;console.log('PASS '+label);};
const url=(origin,q={})=>origin+'/vereinsverwaltung/?'+new URLSearchParams({view:'finance',tab:'outgoing',...q});
const go=(p,target)=>p.goto(target,{waitUntil:'domcontentloaded'});
async function context(origin,js){
 const c=await browser.newContext({javaScriptEnabled:js,viewport:{width:390,height:1000},hasTouch:true,serviceWorkers:'block',extraHTTPHeaders:{'X-Owned-Outgoing-Invoice-Client':String(++clients)}});
 c.on('page',p=>p.on('pageerror',e=>errors.push(e.message)));
 await c.route('**/*',r=>new URL(r.request().url()).origin===origin?r.continue():(external++,r.abort()));return c;
}
async function login(p,origin,role='admin'){
 await go(p,origin+'/login');await p.locator('[name=email]').fill(f.accounts[role].email);await p.locator('[name=password]').fill(f.password);
 await Promise.all([p.waitForURL(u=>u.pathname==='/vereinsverwaltung/',{waitUntil:'domcontentloaded'}),p.getByRole('button',{name:/^(Anmelden und Vereinsmanager öffnen|Sign in and open club manager)$/}).click()]);
}
async function language(p,origin,locale){
 await go(p,origin+'/account?section=language');const form=p.locator('form:has([name=operation][value=select-language])');await form.locator('[name=interface_locale]').selectOption(locale);
 await Promise.all([p.waitForNavigation({waitUntil:'domcontentloaded'}),form.getByRole('button').click()]);
}
async function submit(p,intent,selector='[data-outgoinginvoice-form]'){
 const [r]=await Promise.all([p.waitForResponse(r=>r.request().method()==='POST'&&r.url().includes('/vereinsverwaltung/')),p.waitForEvent('domcontentloaded'),p.locator(selector).locator('button[name=outgoinginvoice_intent][value="'+intent+'"]').click()]);return r;
}
async function openLink(p,selector,marker,label){
 const target=new URL(await p.locator(selector).getAttribute('href'),p.url());
 const [response]=await Promise.all([p.waitForResponse(r=>r.request().method()==='GET'&&r.url()===target.href),p.locator(selector).click()]);
 await p.locator(marker+', [data-manager-navigation-error]').first().waitFor({state:'attached'});
 await p.waitForURL(target.href);
 check(response.status()===200&&await p.locator(marker).count()===1&&await p.locator('[data-manager-navigation-error]').count()===0&&await p.evaluate(()=>Object.keys(JSON.parse(document.querySelector('meta[name="manager-navigation"]').content).assets).some(name=>name.endsWith('outgoing-invoice-native.css'))),'actual invoice link opens its current native controls with registered styles '+label);
 await p.waitForURL(target.href);return response;
}
async function row(p,value){
 const [r]=await Promise.all([p.waitForResponse(r=>r.request().method()==='POST'&&r.url().includes('/vereinsverwaltung/')),p.waitForEvent('domcontentloaded'),p.locator('button[name=outgoinginvoice_row][value="'+value+'"]').click()]);return r;
}
async function original(p,origin){
 const r=await p.request.get(new URL(await p.locator('[data-outgoinginvoice-download]').getAttribute('href'),origin).href);
 check(r.status()===200&&r.headers()['cache-control'].includes('no-store')&&r.headers()['x-content-type-options']==='nosniff','whole first invoice confirmation stays private');
 const json=await r.text(),body=JSON.parse(json);originals.push({id:body.result.id,key:body.request_key,json});return body;
}
async function fit(p,label){
 const selector='[data-outgoinginvoice-native],[data-outgoinginvoice-heading],[data-outgoinginvoice-summary]';
 // DOM handles preserve each actual computed size across viewport changes.
 const handle=await p.evaluateHandle(selector=>[...new Set([...document.querySelectorAll(selector)].flatMap(el=>[el,...el.querySelectorAll('*')]))].map(el=>({el,size:parseFloat(getComputedStyle(el).fontSize),original:el.style.fontSize})),selector);
 for(const width of[320,820])for(const scale of[100,200]){
  await p.setViewportSize({width,height:1000});await p.evaluate(([rows,scale])=>rows.forEach(row=>row.el.style.fontSize=row.size*scale/100+'px'),[handle,scale]);views++;
  const result=await p.locator(selector).evaluateAll((roots,scale)=>{
   const overflow=roots.map(el=>({width:el.clientWidth,scroll:el.scrollWidth,left:el.getBoundingClientRect().left,right:el.getBoundingClientRect().right})).filter(r=>r.scroll>r.width+1||r.left< -1||r.right>innerWidth+1);
   const small=roots.flatMap(root=>[...root.querySelectorAll('a,summary,input:not([type=hidden]),select:not([aria-hidden=true]),button')]).map(el=>({name:el.name,tag:el.tagName,rect:el.getBoundingClientRect()})).filter(r=>r.rect.width>0&&r.rect.height>0&&(r.rect.width<43.9||r.rect.height<43.9)).map(r=>({name:r.name,tag:r.tag,width:r.rect.width,height:r.rect.height}));
   return{overflow,small};
  },scale);
  const doubled=await p.evaluate(([rows,scale])=>rows.every(r=>!r.el.isConnected||Math.abs(parseFloat(getComputedStyle(r.el).fontSize)-r.size*scale/100)<.15),[handle,scale]);
  check(doubled,'actual computed invoice fonts scale '+label+' '+width+' '+scale);
  if(result.overflow.length||result.small.length)console.log('OWN INVOICE LAYOUT '+JSON.stringify(result));
  check(!result.overflow.length,'whole invoice controls fit '+label+' '+width+' '+scale);
  check(!result.small.length,'invoice controls retain forty-four-pixel targets '+label+' '+width+' '+scale);
 }
 await p.evaluate(rows=>rows.forEach(r=>r.el.style.fontSize=r.original),handle);await handle.dispose();await p.setViewportSize({width:390,height:1000});
}
async function guide(p){
 await p.setViewportSize({width:1120,height:3200});
 const selectors=['[data-outgoinginvoice-editor] > h3','[data-outgoinginvoice-form] > section:nth-of-type(1) h4','[data-outgoinginvoice-form] > section:nth-of-type(2) h4','[data-outgoinginvoice-form] [name="data[invoice_format]"]'];
 const clip=await p.evaluate(selectors=>{
  const root=document.querySelector('[data-outgoinginvoice-editor]'),r=root.getBoundingClientRect(),nodes=selectors.map(s=>document.querySelector(s));if(nodes.some(n=>!n))throw Error('Actual invoice guide controls required.');
  nodes.forEach((node,i)=>{const b=node.getBoundingClientRect(),mark=document.createElement('span');mark.dataset.ownInvoiceGuide='1';mark.textContent=String(i+1);Object.assign(mark.style,{position:'absolute',left:Math.max(0,b.left-26)+scrollX+'px',top:b.top+scrollY+'px',width:'24px',height:'24px',borderRadius:'50%',background:'#075ec8',color:'#fff',font:'bold 15px/24px sans-serif',textAlign:'center',zIndex:'9999',boxShadow:'0 0 0 2px white'});document.body.append(mark);});
  return{x:Math.max(0,Math.floor(r.left-30)),y:Math.max(0,Math.floor(r.top+scrollY)),width:Math.floor(r.width+30),height:Math.ceil(nodes.at(-1).getBoundingClientRect().bottom-r.top+130)};
 },selectors);
 try{await p.setViewportSize({width:1120,height:Math.ceil(clip.y+clip.height+50)});await p.screenshot({path:'/tmp/dvm-feature111-outgoing-invoice-guide.png',clip});fs.writeFileSync('/tmp/dvm-feature111-outgoing-invoice-guide.json',JSON.stringify({width:clip.width,height:clip.height,source:'owned-isolated-native-whole-invoice-editor',privacy:'synthetic-only-four-dom-number-overlays-no-credentials-or-signatures'}));}
 finally{await p.locator('[data-own-invoice-guide]').evaluateAll(nodes=>nodes.forEach(n=>n.remove()));await p.setViewportSize({width:390,height:1000});}
}
try{
 for(const app of['front','direct'])for(const js of[true,false])for(const locale of['de','en']){
  const current=index++;if(process.env.DVM_OUTGOING_INVOICE_CASE!==undefined&&Number(process.env.DVM_OUTGOING_INVOICE_CASE)!==current)continue;
  const origin=f.origins[app],c=await context(origin,js),label=app+' '+locale+' '+js;let p=await c.newPage();stage=label+' login';await login(p,origin);await language(p,origin,locale);await go(p,url(origin));
  let issuedFirst;
  for(const format of['pdf','hybrid-xrechnung']){
   const hybrid=format==='hybrid-xrechnung';stage=label+' '+format+' new';let r=await openLink(p,'[data-outgoinginvoice-heading] a[href*="new_invoice="]','[data-outgoinginvoice-form]',label+' '+format+' new');
   check(r.status()===200&&await p.locator('[data-outgoinginvoice-form]').count()===1,'actual whole invoice editor opens '+label+' '+format);
   check(await p.getByRole('heading',{name:locale==='en'?'Your complete invoice draft':'Eigener ganzer Rechnungsentwurf',exact:true}).count()===1,'whole invoice editor uses selected language '+label);
   let form=p.locator('[data-outgoinginvoice-form]');check(await form.locator('[maxlength]').count()===0&&await form.locator('[name="data[invoice_format]"]').inputValue()==='pdf','next invoice starts explicitly with ordinary PDF without clipping '+label);
   if(current===0&&!hybrid)await guide(p);
   if(!hybrid){
    await form.locator('[name="data[buyer_name]"]').focus();await p.keyboard.press('Tab');check(await p.evaluate(()=>document.activeElement?.name)==='data[buyer_street]','actual invoice keyboard order reaches the next whole field '+label);
    await form.locator('[name="data[buyer_name]"]').fill('🙂'.repeat(201));await form.locator('[name="data[note]"]').fill('\n0\n');
    r=await row(p,'add');check(r.status()===200&&await p.locator('[data-outgoinginvoice-line]').count()===2&&await p.locator('[name="data[buyer_name]"]').inputValue()==='🙂'.repeat(201)&&await p.locator('[name="data[note]"]').inputValue()==='\n0\n','native position addition retains all whole invalid fields '+label);
    await p.locator('[name="data[positions][0][description]"]').fill('Own first position');await p.locator('[name="data[positions][1][description]"]').fill('Own second position');r=await row(p,'up:1');check(r.status()===200&&await p.locator('[name="data[positions][0][description]"]').inputValue()==='Own second position','native position ordering works without a JavaScript dependency '+label);r=await row(p,'remove:1');check(r.status()===200&&await p.locator('[data-outgoinginvoice-line]').count()===1,'native position removal preserves the complete remaining row '+label);
    r=await submit(p,'save');check(r.status()===422&&await p.locator('[name="data[buyer_name]"]').inputValue()==='🙂'.repeat(201)&&await p.locator('[name="data[note]"]').inputValue()==='\n0\n'&&await p.locator('[data-outgoinginvoice-receipt]').count()===0,'whole invalid native draft remains editable with no personal confirmation '+label);await fit(p,'whole error '+label);
   }
   for(const[key,value]of Object.entries({buyer_name:'Own actual buyer '+label+' '+format,buyer_street:'Own buyer street',buyer_postcode:'23456',buyer_city:'Own buyer town',buyer_email:hybrid?'own-buyer@example.invalid':'',buyer_reference:hybrid?'OWN-1':'',seller_contact:hybrid?'Own invoice office':'',description:'Own actual native invoice '+label+' '+format,document_date:'2025-12-01',delivery_date:'2025-12-01',due_date:'2025-12-15',note:'\n0\n'}))await p.locator('[name="data['+key+']"]').fill(value);
   await p.locator('[name="data[invoice_format]"]').selectOption(format);if(hybrid)await p.locator('[name="data[domestic_confirmed]"][type=checkbox]').check();await p.locator('[name="data[category]"]').selectOption('rooms');await p.locator('[name="data[payment_account]"]').selectOption(f.data.account);
   for(const[key,value]of Object.entries({description:'Own actual room 0',quantity:'3',unit_gross:'119'}))await p.locator('[name="data[positions][0]['+key+']"]').fill(value);
   for(const[key,value]of Object.entries({unit:'DAY',tax_rate:'19',sphere:'2'}))await p.locator('[name="data[positions][0]['+key+']"]').selectOption(value);
   stage=label+' '+format+' save';r=await submit(p,'save');check(r.status()===303&&await p.locator('[data-outgoinginvoice-receipt]').count()===1&&await p.locator('[data-outgoinginvoice-editor]').count()===0,'actual invoice save commits a draft without an automatic replacement editor '+label+' '+format);
   const saved=await original(p,origin),id=saved.result.id;
   check(saved.input.data.note===new URLSearchParams(r.request().postData()).get('data[note]')&&saved.normalized.note==='0'&&saved.result.state==='draft'&&saved.result.data.invoice_format===format&&saved.result.data.buyer_email===(hybrid?'own-buyer@example.invalid':''),'actual first retains complete wire values separate normalization and chosen invoice format '+label+' '+format);
   let pdf=await p.request.get(new URL(await p.locator('[data-pdf-preview]').getAttribute('data-pdf-url'),origin).href);check(pdf.status()===200&&pdf.headers()['cache-control'].includes('no-store')&&(await pdf.body()).subarray(0,5).toString()==='%PDF-','actual saved draft PDF is privately readable '+label+' '+format);
   if(js){await p.waitForFunction(()=>document.querySelector('[data-pdf-preview] canvas')?.width>0);check(true,'actual saved draft paints in the existing PDF viewer '+label+' '+format);}
   await openLink(p,'[data-outgoinginvoice-saved] a[href*="outgoinginvoice_edit="]','[data-outgoinginvoice-form]',label+' '+format+' edit');stage=label+' '+format+' review';r=await submit(p,'review');check(r.status()===303,'actual complete saved invoice passes deliberate review '+label+' '+format);const reviewed=await original(p,origin);check(reviewed.input.operation==='review'&&reviewed.result.state==='reviewed'&&reviewed.result.version===2,'actual reviewed original binds the whole saved draft '+label+' '+format);
   await openLink(p,'[data-outgoinginvoice-saved] a[href*="outgoinginvoice_issue="]','[data-outgoinginvoice-issue-form]',label+' '+format+' issue');check(await p.locator('[name=outgoinginvoice_approved]').isChecked()===false,'actual invoice issuance starts with separate unchecked approval '+label+' '+format);
   r=await submit(p,'issue','[data-outgoinginvoice-issue-form]');check(r.status()===422&&await p.locator('[data-outgoinginvoice-issue-form]').count()===1,'unchecked issuance leaves the reviewed draft without a first issued result '+label+' '+format);
   if(!hybrid)await fit(p,'reviewed output '+label);
   await p.locator('[name=outgoinginvoice_approved]').check();stage=label+' '+format+' issue';
   const acceptedKey=await p.locator('[name=outgoinginvoice_key]').inputValue(),acceptedDraft=await p.locator('[name=outgoinginvoice_draft]').inputValue();let dropped=0;
   const lost=async route=>{
    if(route.request().method()==='POST'&&new URLSearchParams(route.request().postData()||'').get('outgoinginvoice_intent')==='issue'){
     const headers=await route.request().allHeaders();if(headers.origin==='null')headers.origin=origin;const response=await route.fetch({maxRedirects:0,headers,timeout:120000});check(response.status()===303,'actual issue commits before the HTTP response is lost '+label+' '+format);dropped++;await route.abort('failed');
    }else await route.continue();
   };
   await p.route('**/vereinsverwaltung/**',lost);await p.locator('[data-outgoinginvoice-issue-form] button[value=issue]').click({timeout:120000}).catch(error=>{if(!dropped)throw error;});await p.unroute('**/vereinsverwaltung/**',lost);check(dropped===1,'one actual invoice response is lost '+label+' '+format);
   await p.close();p=await c.newPage();let posts=0;const listen=request=>{if(request.method()==='POST')posts++;};p.on('request',listen);r=await go(p,url(origin,{invoice:id,outgoinginvoice_draft:acceptedDraft,outgoinginvoice_receipt:acceptedKey}));p.off('request',listen);
   check(r.status()===200&&posts===0&&await p.locator('[data-outgoinginvoice-receipt]').getAttribute('data-outgoinginvoice-receipt')===acceptedKey,'new tab reads the actual issued first with GET and no repeated POST '+label+' '+format);
   issuedFirst=await original(p,origin);check(issuedFirst.input.operation==='issue'&&issuedFirst.approved===true&&issuedFirst.result.state==='issued'&&issuedFirst.result.version===3&&issuedFirst.result.data.invoice_format===format&&issuedFirst.file.sha256.length===64,'actual issued personal first binds the chosen immutable original '+label+' '+format);
   pdf=await p.request.get(url(origin,{invoice:id,outgoinginvoice_pdf:'first',outgoinginvoice_receipt:acceptedKey}));check(pdf.status()===200&&crypto.createHash('sha256').update(await pdf.body()).digest('hex')===issuedFirst.file.sha256,'actual personal first PDF has the exact archived bytes '+label+' '+format);
   check(await p.locator('[data-outgoingmail-native]').count()===1&&await p.locator('[data-outgoingmail-editor]').count()===(hybrid?1:0),'actual chosen original preserves the existing optional mail workflow '+label+' '+format);
   stage=label+' '+format+' earlier first navigation';
   await openLink(p,'[data-outgoinginvoice-history] a[href*="outgoinginvoice_receipt='+saved.request_key+'"]','[data-outgoinginvoice-receipt]',label+' '+format+' saved history');
   check(await p.locator('[data-outgoinginvoice-receipt]').getAttribute('data-outgoinginvoice-receipt')===saved.request_key,'actual issued-page history opens its exact earlier saved first '+label+' '+format);
   await openLink(p,'[data-outgoinginvoice-history] a[href*="outgoinginvoice_receipt='+acceptedKey+'"]','[data-outgoinginvoice-receipt]',label+' '+format+' issued history');
   check(await p.locator('[data-outgoinginvoice-receipt]').getAttribute('data-outgoinginvoice-receipt')===acceptedKey,'actual issued-page history returns to its exact original issuance first '+label+' '+format);
   if(!hybrid)await fit(p,'issued first '+label);
  }
  stage=label+' historical originals';let r=await go(p,url(origin,{invoice:f.data.history_id,outgoinginvoice_history_page:'2'}));
  check(r.status()===200&&await p.locator('[name=outgoinginvoice_history_page]').inputValue()==='2'&&await p.locator('a[href*="outgoinginvoice_receipt='+f.data.history_first+'"]').count()===1,'actual twenty-five-plus-one history opens its genuine earlier first '+label);
  r=await go(p,url(origin,{invoice:f.data.history_id,outgoinginvoice_history_q:'🙂'.repeat(151),outgoinginvoice_history_page:'999999'}));check(r.status()===422&&await p.locator('[name=outgoinginvoice_history_q]').inputValue()==='🙂'.repeat(151)&&await p.locator('[name=outgoinginvoice_history_page]').inputValue()==='999999','whole invalid history query and unavailable page stay literal '+label);
  for(const role of['reader','general','finance','foreign']){
   const other=await context(origin,js),page=await other.newPage();await login(page,origin,role);if(locale==='en')await language(page,origin,locale);
   const response=await page.request.get(url(origin,{invoice:issuedFirst.result.id,outgoinginvoice_original:issuedFirst.request_key}));check(response.status()===(role==='general'?403:404),'known personal invoice original stays private from '+role+' '+label);
   if(role==='finance'){const viewed=await go(page,url(origin,{new_invoice:'1'}));check(viewed.status()===200&&await page.locator('[data-outgoinginvoice-form]').count()===1,'actual Finance-only writer can open the complete invoice editor '+label);await submit(page,'discard');}
   await other.close();
  }
  await language(p,origin,f.original_admin_locale);await c.close();cases++;
 }
 check(errors.length===0,'whole actual invoice matrix has no JavaScript page errors');check(external===0,'whole actual invoice matrix makes no external request');
 fs.writeFileSync(f.root+'/outgoing-invoice-actions.json',JSON.stringify({originals,cases}));
 console.log(JSON.stringify({suite:'native-outgoing-invoice-browser',checks,views,cases,external_actions:external,smtp_connections:0}));
}catch(error){console.log(JSON.stringify({failed_at:stage,message:error.message.split('\n')[0],credentials_logged:false}));process.exitCode=1;}finally{await browser.close();}
