/* Only the separate router harness on 8194; no real manager/review fixture. */
const fs=require('node:fs'), path=require('node:path'), assert=require('node:assert/strict');
const origin='http://127.0.0.1:8194';
// Consume every response before opening the next request, including binary/HEAD responses.
const fetch=(url,options={})=>new Promise((resolve,reject)=>{
 const target=new URL(url);assert.equal(target.origin,origin,'only the isolated loopback harness');
 const request=require('node:http').request(target,{method:options.method||'GET',headers:options.headers||{},agent:false},response=>{
  const chunks=[];response.on('data',chunk=>chunks.push(chunk));response.on('end',()=>resolve({status:response.statusCode,headers:{get:name=>{const value=response.headers[name.toLowerCase()];return Array.isArray(value)?value.join(', '):(value??null)}},text:async()=>Buffer.concat(chunks).toString('utf8')}));response.on('error',reject);
 });request.on('error',reject);request.end(options.body);
});
const state=JSON.parse(fs.readFileSync(path.resolve(__dirname,'../../.local/native-public-router-tests/current.json'),'utf8'));
const checks=[];const check=(ok,label)=>{assert.ok(ok,label);checks.push(label)};
(async()=>{
 let response=await fetch(origin+'/');let html=await response.text();
 check(response.status===200&&/>(?:Ö|&Ouml;)ffentliches Kulturhus<\/h1>/.test(html),'HTTP root published page '+JSON.stringify({status:response.status,title:html.match(/<title>(.*?)<\/title>/)?.[1],heading:html.match(/<h1[^>]*>(.*?)<\/h1>/s)?.[1],body:html.slice(0,300)}));
 check(html.includes('href="/vereinsverwaltung/"'),'HTTP manager entry');
 const etag=response.headers.get('etag');check(/^"[a-f0-9]{64}"$/.test(etag),'HTML ETag');
 check(response.headers.get('content-security-policy').includes("form-action 'self'"),'public CSP');
 response=await fetch(origin+'/',{headers:{'If-None-Match':etag}});check(response.status===304&&(await response.text())==='','conditional response has no body');
 response=await fetch(origin+'/',{method:'HEAD'});check(response.status===200&&(await response.text())===''&&response.headers.get('etag')===etag,'HTTP HEAD HTML semantics');
 response=await fetch(origin+'/assets/kulturhus-site.css');check(response.status===200&&response.headers.get('content-type').startsWith('text/css'),'registered original project CSS');
 response=await fetch(origin+'/assets/saxophone.webp',{method:'HEAD'});check(response.status===200&&response.headers.get('content-type')==='image/webp'&&(await response.text())==='','project image HEAD/mime');
 response=await fetch(origin+'/assets/public.js',{method:'POST'});check(response.status===405&&response.headers.get('allow')==='GET, HEAD','asset rejects POST');
 response=await fetch(origin+'/media/'+state.public_media+'/thumb');check(response.status===200&&response.headers.get('content-type')==='image/png','public referenced media HTTP');
 response=await fetch(origin+'/media/'+state.draft_media+'/thumb');check(response.status===404,'draft media HTTP denied');
 response=await fetch(origin+'/site/geheim');check(response.status===404,'draft page HTTP denied');
 response=await fetch(origin+'/mitglied-werden/');html=await response.text();
 check(response.status===200&&response.headers.get('cache-control')==='no-store'&&html.includes('name="csrf"'),'membership uncached real form');
 const cookie=(response.headers.get('set-cookie')||'').split(';')[0];
 response=await fetch(origin+'/mitglied-werden/',{method:'POST',headers:{Cookie:cookie,Origin:origin,'Content-Type':'application/x-www-form-urlencoded'},body:'csrf=wrong'});
 check(response.status===403&&response.headers.get('cache-control')==='no-store','membership CSRF denied without caching');
 response=await fetch(origin+'/mitglied-werden/',{method:'POST',headers:{Origin:'https://external.invalid','Content-Type':'application/x-www-form-urlencoded'},body:'csrf=wrong'});check(response.status===403,'caller origin guard');
 response=await fetch(origin+'/feeds/alle/');check(response.status===200&&response.headers.get('content-type').startsWith('application/rss+xml'),'legacy RSS route');
 response=await fetch(origin+'/sitemap.xml');html=await response.text();check(response.status===200&&html.includes('/aktuelles/testbeitrag/')&&!html.includes('/site/'),'original sitemap URLs');
 response=await fetch(origin+'/veranstaltungen/legacy---event--2050/?utm_source=instagram%2Ffeed');const eventTarget=response.headers.get('location');check(response.status===308&&eventTarget==='/veranstaltungen/legacy-event-2050/?utm_source=instagram%2Ffeed'&&(await response.text())==='','earlier published social link has an exact local redirect retaining query bytes');
 response=await fetch(origin+eventTarget);html=await response.text();check(response.status===200&&html.includes('Published earlier social event'),'earlier published social link reaches its actual event');
 response=await fetch(origin+'/veranstaltungen/legacy---event--2050/',{method:'HEAD'});check(response.status===308&&(await response.text())==='','earlier event HEAD has no response body');
 response=await fetch(origin+'/vereinsverwaltung/native-manager.js?v='+state.native_asset_hash);const nativeEtag=response.headers.get('etag');check(response.status===200&&response.headers.get('cache-control')==='public, max-age=31536000, immutable'&&(await response.text()).includes('native-manager'),'only exact versioned public script receives immutable caching');
 response=await fetch(origin+'/vereinsverwaltung/native-manager.js?v='+state.native_asset_hash,{headers:{'If-None-Match':nativeEtag}});check(response.status===304&&(await response.text())==='','versioned script conditional request returns no bytes');
 response=await fetch(origin+'/vereinsverwaltung/native-manager.js?v=outdated',{method:'HEAD'});check(response.status===200&&response.headers.get('cache-control')==='public, max-age=0, must-revalidate'&&(await response.text())==='','wrong or stale version never acquires immutable cache semantics');
 response=await fetch(origin+'/vereinsverwaltung/native-manager.js',{method:'POST'});check(response.status===405,'asset caching never changes method authorization');
 const result={suite:'native-public-router-http',passed:checks.length,checks};
 fs.writeFileSync(path.resolve(__dirname,'../../.local/native-public-router-tests/http-result.json'),JSON.stringify(result,null,2));console.log(JSON.stringify(result,null,2));
})().catch(error=>{console.error(error);process.exitCode=1});
