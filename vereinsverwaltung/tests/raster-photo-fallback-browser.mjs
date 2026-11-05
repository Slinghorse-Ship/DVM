import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import http from 'node:http';
import crypto from 'node:crypto';
import {once} from 'node:events';
const f=JSON.parse(fs.readFileSync(0,'utf8'));
if(new URL(f.origin).hostname!=='127.0.0.1'||!path.isAbsolute(f.root))throw Error('Own local raster fixture required');
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE);
const browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE,headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
let checks=0,posts=0,views=0,external=0;const errors=[];let activePage=null;
const check=(yes,label)=>{if(!yes)throw Error(label);checks++;console.log('PASS '+label);};
const digest=b=>crypto.createHash('sha256').update(b).digest('hex');
const sourceRoot=fs.realpathSync(f.raster_source_root);assert.equal(sourceRoot,f.raster_source_root);assert.match(path.basename(sourceRoot),/^dvm-raster-fixtures-kh_native_[a-f0-9]{24}$/);assert.ok(!fs.lstatSync(sourceRoot).isSymbolicLink());assert.ok(Object.values(f.raster_sources).every(name=>typeof name==='string'&&path.basename(name)===name));
const file=type=>path.join(sourceRoot,f.raster_sources[type]);
const originals=Object.fromEntries(Object.values(f.raster_sources).map(name=>[name,digest(fs.readFileSync(path.join(sourceRoot,name)))]));
const url=(mode,view,extras={})=>f.origin+(mode==='integrated'?'/vereinsverwaltung/':'/')+'?'+new URLSearchParams({view:mode==='integrated'&&view==='media'?'website':view,...(mode==='integrated'&&view==='media'?{tab:'gallery'}:{}),section:view==='media'?'library':'compose',...extras});
const form=p=>p.locator('form').filter({has:p.locator('[name=operation][value=communication-save]')});
const image=(mode,id,media)=>f.origin+(mode==='integrated'?'/vereinsverwaltung/?native_endpoint=edition-image&':'/edition-image?')+new URLSearchParams({module:'press',item:id,revision:1,media,attachment:1});
async function streamedReplay(target,cookie,fields,files){
 const u=new URL(target);if(u.origin!==f.origin)throw Error('Owned replay endpoint required');const boundary='OwnRasterBoundary20261018';const esc=s=>{if(/[\r\n\0]/.test(s))throw Error('Invalid owned field');return s.replaceAll('\\','\\\\').replaceAll('"','\\"');};const parts=[];
 for(const [name,value]of fields)parts.push({head:Buffer.from('--'+boundary+'\r\nContent-Disposition: form-data; name="'+esc(name)+'"\r\n\r\n'+value+'\r\n')});
 for(const file of files){const local=path.join(sourceRoot,file.name);if(!Object.values(f.raster_sources).includes(file.name)||path.dirname(local)!==sourceRoot||fs.lstatSync(local).isSymbolicLink())throw Error('Own source required');parts.push({head:Buffer.from('--'+boundary+'\r\nContent-Disposition: form-data; name="'+esc(file.field)+'"; filename="'+esc(file.name)+'"\r\nContent-Type: '+(file.type||'application/octet-stream')+'\r\n\r\n'),file:local,size:fs.statSync(local).size});}
 const end=Buffer.from('--'+boundary+'--\r\n'),length=parts.reduce((n,p)=>n+p.head.length+(p.file?p.size+2:0),end.length);let request;
 const response=new Promise((resolve,reject)=>{request=http.request(u,{method:'POST',headers:{Cookie:cookie,'Content-Type':'multipart/form-data; boundary='+boundary,'Content-Length':length}},r=>{const chunks=[];let bytes=0;r.on('data',b=>{bytes+=b.length;if(bytes>2*1024*1024){r.destroy();reject(Error('Owned replay response too large'));}else chunks.push(b);});r.on('end',()=>resolve({status:r.statusCode,location:r.headers.location,body:Buffer.concat(chunks).toString()}));r.on('error',reject);});request.on('error',reject);});
 try{for(const part of parts){if(!request.write(part.head))await once(request,'drain');if(part.file){for await(const chunk of fs.createReadStream(part.file))if(!request.write(chunk))await once(request,'drain');if(!request.write('\r\n'))await once(request,'drain');}}request.end(end);return await response;}catch(error){request.destroy();throw error;}
}
try{
 const runtimeContext=await browser.newContext();const initial=await(await runtimeContext.request.get(f.origin+'/own-raster-runtime')).json();await runtimeContext.close();check(initial.memory_limit==='64M','actual two-app HTTP server uses PHP64M');
 for(const mode of ['integrated','native'])for(const js of [false,true]){
  const variant=js?'unsupported-worker':'nojs';const c=await browser.newContext({viewport:{width:390,height:950},javaScriptEnabled:js,serviceWorkers:'block'});
  if(js)await c.addInitScript(()=>{window.Worker=undefined;window.OffscreenCanvas=undefined;});
  await c.route('**/*',route=>{const u=new URL(route.request().url());if(['http:','https:'].includes(u.protocol)&&u.origin!==f.origin){external++;return route.abort();}return route.continue();});
  c.on('request',r=>{if(r.method()==='POST')posts++;});const p=await c.newPage();activePage=p;p.on('response',r=>{if(r.request().method()==='POST')console.log(JSON.stringify({raster_post_response:{mode,variant,status:r.status(),path:new URL(r.url()).pathname}}));});p.on('pageerror',e=>errors.push(e.message));
  await p.goto(f.origin+'/own-test-enter?mode='+mode+'&module=press&role=reader');await p.goto(url(mode,'press'));await form(p).waitFor();
  const title='Own exact raster retry '+mode+'/'+variant;const human='Own retained human wording Σ ä🙂';
  await form(p).locator('[name=title]').fill(title);if(js)await form(p).locator('[contenteditable=true]').fill(human);else await form(p).locator('textarea[name=body_html]').fill(human);
  await p.locator('.press-photo-attachments summary').click();const input=p.locator('#native-press-module-photos');
  await input.setInputFiles([file('jpeg'),file('png'),file('invalid')]);if(js)await p.waitForFunction(()=>!document.querySelector('#native-press-module-photos').disabled,null,{timeout:120000});
  await form(p).getByRole('button',{name:'Entwurf speichern',exact:true}).first().click({timeout:150000});await form(p).waitFor();
  check(await form(p).locator('[name=title]').inputValue()===title,'corrupt mixed source preserves the actual human title '+mode+'/'+variant);
  check((js?await form(p).locator('[contenteditable=true]').innerText():await form(p).locator('textarea[name=body_html]').inputValue()).includes(human),'corrupt mixed source preserves actual human text '+mode+'/'+variant);
  check(!(new URL(p.url()).searchParams.get(mode==='integrated'?'native_item':'item')),'failed atomic press batch does not create a partial edition '+mode+'/'+variant);
  const details=p.locator('.press-photo-attachments');if(await details.getAttribute('open')===null)await details.locator(':scope > summary').click();
  await input.setInputFiles([file('jpeg'),file('png'),file('webp')]);if(js)await p.waitForFunction(()=>!document.querySelector('#native-press-module-photos').disabled,null,{timeout:120000});
  const save=form(p).getByRole('button',{name:'Entwurf speichern',exact:true}).first();
  const original=await save.evaluate(button=>{const fields=[],files=[];for(const[field,value]of new FormData(button.form,button)){if(value instanceof File){if(value.name)files.push({field,name:value.name,type:value.type,size:value.size});}else fields.push([field,value]);}return{fields,files,action:button.form.getAttribute('action')};});
  check(original.files.length===3&&original.files.some(file=>file.size>180000000),'actual192MB original reaches native multipart without client conversion '+mode+'/'+variant);
  const target=new URL(original.action||p.url(),p.url()).href;
  const invalidControls=await save.evaluate(button=>[...button.form.elements].filter(el=>el.willValidate&&!el.validity.valid).map(el=>({name:el.name,tag:el.tagName,message:el.validationMessage})));console.log(JSON.stringify({raster_before_save:{mode,variant,invalid:invalidControls}}));fs.writeFileSync(path.join(f.root,'raster-before-save-'+mode+'-'+variant+'.html'),await p.content(),{mode:0o600});
  // Three real image workers have their own45s watchdog each; keep navigation bounded accordingly.
  await Promise.all([p.waitForURL(u=>(u.searchParams.get(mode==='integrated'?'native_item':'item'))?.length===32,{timeout:150000}),save.click({noWaitAfter:true})]);await form(p).waitFor();const id=new URL(p.url()).searchParams.get(mode==='integrated'?'native_item':'item');
  const ids=await p.locator('.press-photo-attachments [name="attachment_media_ids[]"]').evaluateAll(nodes=>nodes.map(n=>n.value));check(ids.length===3&&new Set(ids).size===3,'actual corrected multipart associates exactly three new originals '+mode+'/'+variant);
  const hashes=[];for(let n=0;n<ids.length;n++){const response=await c.request.get(image(mode,id,ids[n]));const bytes=await response.body();hashes.push(digest(bytes));check(response.status()===200&&response.headers()['content-type']===['image/jpeg','image/png','image/webp'][n]&&bytes.length>0,'actual private attachment retains its expected format '+mode+'/'+variant+'/'+n);}
  check((await c.request.get(image(mode,id,f.photos[mode].unrelated))).status()>=400,'unassociated private original remains unavailable '+mode+'/'+variant);
  const cookies=(await c.cookies(target)).map(v=>v.name+'='+v.value).join('; ');posts++;const replay=await streamedReplay(target,cookies,original.fields,original.files);check(replay.status>=300&&replay.status<400&&new URL(replay.location,target).searchParams.get(mode==='integrated'?'native_item':'item')===id,'exact streamed multipart replay returns the original saved edition '+mode+'/'+variant);
  for(let n=0;n<ids.length;n++)check(digest(await(await c.request.get(image(mode,id,ids[n]))).body())===hashes[n],'replay retains the exact earlier stored pixels '+mode+'/'+variant+'/'+n);
  for(const width of [320,820])for(const font of [100,200]){await p.setViewportSize({width,height:950});await p.evaluate(value=>document.documentElement.style.fontSize=value+'%',font);check(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'actual photo form fits touch/enlarged text '+mode+'/'+variant+'/'+width+'/'+font);views++;}
  await p.goto(f.origin+'/own-test-enter?mode='+mode+'&module=press');await p.goto(url(mode,'media'));
  const upload=p.locator('form[data-media-upload-form]');await upload.waitFor({state:'attached'});const disclosure=p.locator('details.media-upload');if(await disclosure.getAttribute('open')===null)await disclosure.locator(':scope > summary').click();await upload.waitFor();
  await upload.locator('[name="image[]"]').setInputFiles([file('png'),file('invalid'),file('webp')]);
  if(js){await p.waitForFunction(()=>document.querySelector('[data-upload-progress]')?.textContent.includes('2 Bilder gespeichert.'),null,{timeout:120000});check((await upload.locator('[data-upload-progress]').innerText()).includes('1 fehlgeschlagen')&&await upload.locator('[data-library-uploaded]').count()===2,'actual unsupported-worker library reports two saved originals and failed filename '+mode);}
  else{await upload.getByRole('button',{name:'Hochladen',exact:true}).click({timeout:120000});await p.waitForLoadState('domcontentloaded');const notice=p.locator('.notice[role=status]').filter({hasText:'2 Bilder hochgeladen.'});await notice.waitFor();check((await notice.innerText()).includes('1 fehlgeschlagen')&&(await notice.innerText()).includes(f.raster_sources.invalid),'actual global notice reports accepted noJS originals and failed filename '+mode);}
  await c.close();
 }
 check(external===0&&errors.length===0,'all real browser paths remain local and free from runtime errors');for(const[name,hash]of Object.entries(originals))check(digest(fs.readFileSync(path.join(sourceRoot,name)))===hash,'selected original source bytes remain exact '+name);
 const probe=await browser.newContext();const runtime=await(await probe.request.get(f.origin+'/own-raster-runtime')).json();await probe.close();
 console.log(JSON.stringify({suite:'raster-photo-fallback-browser',checks,actual_posts:posts,views,external_actions:external,php_request_limit:runtime.memory_limit,php_native_resident_peak_kib:runtime.resident_peak_kib,errors}));
}catch(error){console.error(error);if(activePage&&!activePage.isClosed()){await activePage.content().then(html=>fs.writeFileSync(path.join(f.root,'raster-browser-failure.html'),html,{mode:0o600})).catch(()=>{});await activePage.screenshot({path:path.join(f.root,'raster-browser-failure.png'),fullPage:true}).catch(()=>{});}throw error;}finally{await browser.close();}
