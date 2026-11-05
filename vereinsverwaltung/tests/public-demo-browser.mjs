import {readFile,mkdir} from 'node:fs/promises';import assert from 'node:assert/strict';
const fixture=JSON.parse((await import('node:fs')).readFileSync(0,'utf8')),origin='https://demo.localhost';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE);
const {default:https}=await import('node:https'),{default:http}=await import('node:http'),{spawnSync}=await import('node:child_process'),{X509Certificate,createHash}=await import('node:crypto');
const certPath=fixture.root+'/test-cert.pem',keyPath=fixture.root+'/test-cert.key';
const generated=spawnSync('openssl',['req','-x509','-newkey','rsa:2048','-nodes','-keyout',keyPath,'-out',certPath,'-subj','/CN=demo.localhost','-addext','subjectAltName=DNS:demo.localhost','-days','1'],{encoding:'utf8'});if(generated.status!==0)throw Error('Owned test TLS certificate unavailable');
const cert=await readFile(certPath),pin=createHash('sha256').update(new X509Certificate(cert).publicKey.export({format:'der',type:'spki'})).digest('base64');
const proxy=https.createServer({key:await readFile(keyPath),cert},(request,response)=>{const upstream=http.request({host:'127.0.0.1',port:fixture.backend,method:request.method,path:request.url,headers:request.headers},reply=>{response.writeHead(reply.statusCode,reply.headers);reply.pipe(response)});upstream.on('error',()=>{response.writeHead(502);response.end()});request.pipe(upstream)});
await new Promise(resolve=>proxy.listen(0,'127.0.0.1',resolve));const port=proxy.address().port;
const browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE,args:['--proxy-bypass-list=demo.localhost','--host-resolver-rules=MAP demo.localhost 127.0.0.1:'+port,'--ignore-certificate-errors-spki-list='+pin]});
let stage='',views=0,checks=0,active;const artifacts=fixture.evidence;await mkdir(artifacts,{recursive:true});
try{
for(const js of (fixture.case?[]:[false,true]))for(const locale of ['de','en'])for(const role of ['members','treasurer','editor','member']){
 stage=[role,locale,js?'js':'nojs'].join('-');const errors=[],leaks=[];const context=await browser.newContext({javaScriptEnabled:js,viewport:{width:390,height:1000},hasTouch:true});
 await context.route('**/*',route=>{const url=new URL(route.request().url());if(url.origin!==origin||!/^\/demo(?:\/|$)/.test(url.pathname)){leaks.push(url.origin+url.pathname);return route.abort()}return route.continue()});
 const page=await context.newPage();active=page;page.on('pageerror',error=>errors.push(error.message));
 await page.goto(origin+'/demo/',{waitUntil:'load'});
 if(locale==='en')await Promise.all([page.waitForNavigation({waitUntil:'load'}),page.locator('button[name=locale][value=en]').click()]);
 await Promise.all([page.waitForNavigation({waitUntil:'load'}),page.locator('button[name=role][value="'+role+'"]').click()]);
 await page.locator('.dvm-demo-banner').waitFor();await page.locator('main').first().waitFor();assert.equal(await page.locator('html').getAttribute('lang'),locale);checks++;
 const roleURL=page.url();const headings=await page.locator('h1,h2').allTextContents();assert.ok(headings.length);checks++;
 for(const width of [320,390,820,1440]){
  await page.setViewportSize({width,height:1000});await page.waitForTimeout(80);
  const geometry=await page.evaluate(()=>({width:innerWidth,scroll:document.documentElement.scrollWidth,links:[...document.querySelectorAll('a[href],form[action]')].map(node=>new URL(node.getAttribute('href')||node.getAttribute('action')||location.href,location.href)).filter(url=>url.origin===location.origin&&!/^\/demo(?:\/|$)/.test(url.pathname)).map(url=>url.pathname)}));
  assert.ok(geometry.scroll<=geometry.width+1,stage+' overflow '+width+' '+JSON.stringify(geometry));assert.deepEqual(geometry.links,[]);checks+=2;views++;
  if(width===320||width===820)await page.screenshot({path:artifacts+'/'+stage+'-'+width+'.png',fullPage:false});
 }
 assert.deepEqual(errors,[]);assert.deepEqual(leaks,[]);checks+=2;console.log(JSON.stringify({stage,headings,url:roleURL,views,checks}));await context.close();
}
stage='browser-storage-and-workers';
if(fixture.case!=='flows'){
 const context=await browser.newContext({viewport:{width:390,height:1000}}),normal=await context.newPage();active=normal;await normal.goto(origin+'/normal');
 await normal.evaluate(async()=>{localStorage.setItem('same','normal');sessionStorage.setItem('same','normal-session');await caches.open('same');await new Promise((resolve,reject)=>{let q=indexedDB.open('same',1);q.onsuccess=()=>{q.result.close();resolve()};q.onerror=()=>reject(q.error)});await navigator.serviceWorker.register('/normal-worker.js',{scope:'/normal-scope/'})});
 const demo=await context.newPage();active=demo;demo.on('console',message=>{if(message.type()==='error')console.error('Owned demo console',message.text())});await demo.goto(origin+'/demo/');await Promise.all([demo.waitForNavigation({waitUntil:'load'}),demo.locator('button[name=role][value=treasurer]').click()]);await demo.locator('.dvm-demo-banner').waitFor();
 const result=await demo.evaluate(async()=>{
  const before={local:localStorage.getItem('same'),session:sessionStorage.getItem('same'),caches:await caches.keys(),databases:await indexedDB.databases()};
  localStorage.setItem('same','demo');localStorage.other='test';sessionStorage.setItem('same','demo-session');await caches.open('same');await new Promise((resolve,reject)=>{const q=indexedDB.open('same',1);q.onsuccess=()=>{q.result.close();resolve()};q.onerror=()=>reject(q.error)});
  const during={local:localStorage.getItem('same'),property:localStorage.other,keys:Object.keys(localStorage),caches:await caches.keys(),databases:(await indexedDB.databases()).map(x=>x.name)};
  localStorage.clear();sessionStorage.clear();await caches.delete('same');await new Promise((resolve,reject)=>{const q=indexedDB.deleteDatabase('same');q.onsuccess=resolve;q.onerror=()=>reject(q.error)});
  return {before,during,after:localStorage.getItem('same')};
 });
 assert.equal(result.before.local,null);assert.equal(result.before.session,null);assert.ok(!result.before.caches.includes('same'));assert.ok(!result.before.databases.some(x=>x.name==='same'));assert.equal(result.during.local,'demo');assert.equal(result.during.property,'test');assert.ok(result.during.keys.includes('same'));assert.ok(result.during.caches.includes('same'));assert.ok(result.during.databases.includes('same'));assert.equal(result.after,null);checks+=10;
 const workers=await demo.evaluate(async()=>{
  const ask=(worker,port=worker)=>new Promise((resolve,reject)=>{const timer=setTimeout(()=>reject(Error('Owned worker response timed out')),10000);port.onmessage=e=>{clearTimeout(timer);resolve(e.data)};worker.onerror=e=>{clearTimeout(timer);reject(Error((globalThis.ownWorkerStage||'worker')+': '+(e.message||e.type)))};port.start?.();port.postMessage('own-test')});
  const results=[];
  for(const [url,options] of [['/_owned-tests/worker.js',{}],['/_owned-tests/worker-module.mjs',{type:'module'}]]){globalThis.ownWorkerStage=url;const worker=new Worker(url,options);results.push(await ask(worker));worker.terminate()}
  for(const type of ['classic','module']){globalThis.ownWorkerStage='blob-'+type;const blob=URL.createObjectURL(new Blob(['self.onmessage=()=>self.postMessage({mounted:globalThis.DVMDemoMounted===true,namespace:globalThis.DVM_DEMO_CONFIGURATION?.namespace})'],{type:'text/javascript'}));const worker=new Worker(blob,{type});results.push(await ask(worker));worker.terminate();URL.revokeObjectURL(blob)}
  globalThis.ownWorkerStage='shared';const shared=new SharedWorker('/_owned-tests/shared-worker.js',{name:'same'});results.push(await ask(shared,shared.port));shared.port.close();
  const reg=await navigator.serviceWorker.register('/_owned-tests/service-worker.js',{scope:'/demo/_owned-tests/'});const target=reg.installing||reg.waiting||reg.active;
  if(target.state!=='activated')await new Promise((resolve,reject)=>{const timer=setTimeout(()=>reject(Error('Owned service worker activation timed out')),10000);target.addEventListener('statechange',()=>{if(target.state==='activated'){clearTimeout(timer);resolve()}})});
  const channel=new MessageChannel();const response=new Promise((resolve,reject)=>{const timer=setTimeout(()=>reject(Error('Owned service worker reply timed out')),10000);channel.port1.onmessage=e=>{clearTimeout(timer);resolve(e.data)}});reg.active.postMessage('own-test',[channel.port2]);results.push(await response);
  const registrations=(await navigator.serviceWorker.getRegistrations()).map(r=>new URL(r.scope).pathname);await reg.unregister();return {results,registrations};
 });
 assert.equal(workers.results.length,6);assert.ok(workers.results.every(x=>x.mounted&&!x.error&&x.namespace.startsWith('dvm-demo-')));assert.equal(workers.results[1].label,'owned-module');assert.ok(workers.results[5].peers.every(p=>p.startsWith('/demo/')));assert.ok(workers.registrations.every(p=>p.startsWith('/demo/')));checks+=5;
 const preserved=await normal.evaluate(async()=>({local:localStorage.getItem('same'),session:sessionStorage.getItem('same'),caches:await caches.keys(),databases:(await indexedDB.databases()).map(x=>x.name),registrations:(await navigator.serviceWorker.getRegistrations()).map(r=>new URL(r.scope).pathname)}));
 assert.equal(preserved.local,'normal');assert.equal(preserved.session,'normal-session');assert.ok(preserved.caches.includes('same'));assert.ok(preserved.databases.includes('same'));assert.ok(!preserved.caches.includes('worker-same'));assert.ok(preserved.registrations.includes('/normal-scope/'));assert.ok((await context.cookies(origin+'/normal')).some(x=>x.name==='KH_NATIVE_MANAGER'&&x.value==='owned-normal-session'));checks+=7;
 const received=await normal.evaluate(()=>{globalThis.ownMessages=[];globalThis.ownChannel=new BroadcastChannel('same');ownChannel.onmessage=e=>ownMessages.push(e.data);return true});assert.ok(received);
 await demo.evaluate(()=>{const channel=new BroadcastChannel('same');channel.postMessage('demo-message');setTimeout(()=>channel.close(),100)});await normal.waitForTimeout(250);assert.deepEqual(await normal.evaluate(()=>ownMessages),[]);checks++;
 console.log(JSON.stringify({stage,checks,workers:6,normal_originals:'preserved'}));await context.close();
}
if(fixture.case!=='extras'){stage='functional-flows';checks+=await (await import('./support/public-demo-browser-flows.mjs')).flows(browser,origin,fixture)}
console.log(JSON.stringify({complete:true,views,checks,external_actions:0}));
}catch(error){console.error('STAGE',stage);if(active&&!active.isClosed()){console.error(JSON.stringify({url:active.url(),headings:await active.locator('h1,h2,h3').allTextContents(),alerts:await active.locator('[role=alert]').allTextContents()}));await active.screenshot({path:artifacts+'/failed.png',fullPage:false})}throw error}finally{await browser.close();await new Promise(resolve=>proxy.close(resolve))}
