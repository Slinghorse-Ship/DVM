import {kDirectory,kOrigin,kStorage,kConfig,kSource} from './support/k-browser-client.mjs';
import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {execFileSync} from 'node:child_process';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE),root=kSource;
const origins=[kOrigin(false),kOrigin(true)];
const server=createServer((req,res)=>{res.writeHead(200,{'Content-Type':'text/html; charset=utf-8','Content-Security-Policy':"default-src 'none'; connect-src "+origins.join(' ')+';'});res.end('<!doctype html><html lang="de"><meta charset="utf-8"><title>Eigene unabhängige Feed-Prüfung</title></html>');});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));const client='http://127.0.0.1:'+server.address().port;
const browser=await chromium.launch({executablePath:'/usr/bin/chromium',args:['--no-sandbox']});let checks=0,writes=0,external=0;const errors=[],preflights=[];
const check=(ok,label)=>{assert(ok,label);++checks;console.log('PASS '+label);};
try{
 const context=await browser.newContext(),page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
 const network=await context.newCDPSession(page);await network.send('Network.enable');network.on('Network.requestWillBeSent',e=>{if(e.request.method==='OPTIONS')preflights.push(e.request.url);});
 await context.route('**/*',r=>{const req=r.request();if(![client,...origins].includes(new URL(req.url()).origin)){++external;return r.abort();}if(!['GET','HEAD','OPTIONS'].includes(req.method())){++writes;return r.abort();}return r.continue();});
 await page.goto(client);const request=async(url,method='GET',tag=null)=>page.evaluate(async({url,method,tag})=>{try{const response=await fetch(url,{method,credentials:'omit',cache:'no-store',headers:tag?{'If-None-Match':tag}:{}});return {ok:true,status:response.status,etag:response.headers.get('etag'),cache:response.headers.get('cache-control'),type:response.headers.get('content-type'),body:await response.text()};}catch(e){return {ok:false,name:e.name};}},{url,method,tag});
 for(const origin of origins){
  for(const route of ['/feeds/events.json','/feeds/veranstaltungen/']){
   const first=await request(origin+route);check(first.ok&&first.status===200&&JSON.parse(first.body).version===1&&Array.isArray(JSON.parse(first.body).events),'actual anonymous cross-origin browser reads versioned native JSON '+origin+route);
   check(/^"[a-f0-9]{64}"$/.test(first.etag)&&first.type.startsWith('application/json')&&first.cache==='public, max-age=0, must-revalidate','browser can inspect exposed exact validator and fresh public policy '+origin+route);
   const notModified=await request(origin+route,'GET','"unrelated", W/'+first.etag);check(notModified.ok&&notModified.status===304&&notModified.body===''&&notModified.etag===first.etag,'actual conditional cross-origin fetch passes browser preflight and reads exact 304 '+origin+route);
   const head=await request(origin+route,'HEAD');check(head.ok&&head.status===200&&head.body===''&&head.etag===first.etag,'actual cross-origin HEAD has the same representation and no body '+origin+route);
   const same=await request(origin+route);check(same.body===first.body&&same.etag===first.etag,'repeated anonymous browser read retains exact original bytes '+origin+route);
  }
  const privateRead=await request(origin+'/media-preview/'+('a'.repeat(32))+'/thumb');check(!privateRead.ok&&privateRead.name==='TypeError','browser CORS remains closed for private preview '+origin);
 }
 check(preflights.length>=4&&preflights.every(u=>origins.some(o=>u===o+'/feeds/events.json'||u===o+'/feeds/veranstaltungen/')),'actual browser emits conditional preflights only to public JSON feed paths');
 check(!writes&&!external&&!errors.length,'cross-origin browser has no business writes, external transport or script errors');
 const proof=execFileSync(process.env.PHP_CLI_BINARY||'php',[root+'vereinsverwaltung/tests/support/feed-http-browser-fixture.php','check'],{encoding:'utf8',env:{...process.env,KH_NATIVE_TEST_CONFIG:kConfig(false)}});check(proof.includes('Every exact original database byte retained'),'originals, accounts, documents, approvals, media and jobs remain byte-identical');console.log(JSON.stringify({checks,business_writes:writes,external_actions:external,preflights:preflights.length}));
}finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
