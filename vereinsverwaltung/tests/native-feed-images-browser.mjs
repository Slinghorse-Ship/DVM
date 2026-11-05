import {kDirectory,kOrigin,kStorage,kConfig,kSource} from './support/k-browser-client.mjs';
import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {readFileSync} from 'node:fs';
import {createHash} from 'node:crypto';
import {execFileSync} from 'node:child_process';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE),root=kSource;
const apps=[true,false].map(standalone=>({origin:kOrigin(standalone),kind:standalone?'standalone':'integrated',config:kConfig(standalone)}));
for(const app of apps)app.fixture=JSON.parse(readFileSync(kDirectory()+'/feed-images-'+app.kind+'.json','utf8'));
const origins=apps.map(app=>app.origin);
const server=createServer((req,res)=>{res.writeHead(200,{'Content-Type':'text/html; charset=utf-8','Content-Security-Policy':"default-src 'none'; connect-src "+origins.join(' ')+'; img-src '+origins.join(' ')+';'});res.end('<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Eigene anonyme Feed-Bildprüfung</title><body></body></html>');});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));const client='http://127.0.0.1:'+server.address().port;
const browser=await chromium.launch({executablePath:'/usr/bin/chromium',args:['--no-sandbox']});let checks=0,writes=0,external=0;const errors=[],preflights=[];
const check=(ok,label)=>{assert(ok,label);++checks;console.log('PASS '+label);};
try{
 for(const width of [390,320]){
  const context=await browser.newContext({viewport:{width,height:820},isMobile:true,hasTouch:true}),page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
  const network=await context.newCDPSession(page);await network.send('Network.enable');network.on('Network.requestWillBeSent',e=>{if(e.request.method==='OPTIONS')preflights.push(e.request.url);});
  await context.route('**/*',route=>{const req=route.request();if(![client,...origins].includes(new URL(req.url()).origin)){++external;return route.abort();}if(!['GET','HEAD','OPTIONS'].includes(req.method())){++writes;return route.abort();}return route.continue();});
  await page.goto(client);
  for(const app of apps){
   const {fixture,origin}=app;
   for(const path of ['/feeds/events.json','/feeds/veranstaltungen/']){
    const response=await page.evaluate(async url=>{const r=await fetch(url,{credentials:'omit',cache:'no-store'});return{status:r.status,etag:r.headers.get('etag'),body:await r.text()};},origin+path);
    const json=JSON.parse(response.body),item=json.events.find(e=>e.id===fixture.id);
    check(response.status===200&&json.version===1&&item?.title===fixture.title&&item.published_version===fixture.published_version,'actual anonymous browser reads the exact published version '+app.kind+' '+width+' '+path);
    const expected=fixture.images.map(({sha256,...image})=>({...image,url:origin+image.url}));
    check(JSON.stringify(item.images)===JSON.stringify(expected)&&JSON.stringify(item.image)===JSON.stringify(expected[0]),'actual JSON retains exact cover, ordered image metadata and alternative text '+app.kind+' '+width+' '+path);
    check(!response.body.includes(fixture.draft_title)&&!response.body.includes(fixture.private_image),'private pending image replacement never leaks into public JSON '+app.kind+' '+width+' '+path);
    check(item.start_utc==='2035-01-13T00:00:00Z'&&item.end_utc==='2035-01-13T03:15:00Z'&&item.timezone==='America/New_York'&&item.status==='sold_out'&&item.price==='12.50'&&item.ticket_url===null,'image-bearing event preserves exact time, status and price '+app.kind+' '+width+' '+path);
    const notModified=await page.evaluate(async({url,tag})=>{const r=await fetch(url,{credentials:'omit',cache:'no-store',headers:{'If-None-Match':'"other", W/'+tag}});return{status:r.status,etag:r.headers.get('etag'),body:await r.text()};},{url:origin+path,tag:response.etag});
    check(notModified.status===304&&notModified.body===''&&notModified.etag===response.etag,'actual conditional browser preflight retains image representation ETag '+app.kind+' '+width+' '+path);
   }
   const rss=await context.request.get(origin+'/feeds/alle/');check(rss.status()===200,'actual public mixed RSS route serves original event '+app.kind+' '+width);
   const parsed=await page.evaluate(({xml,id})=>{const doc=new DOMParser().parseFromString(xml,'application/xml'),item=[...doc.querySelectorAll('item')].find(e=>e.querySelector('guid')?.textContent===id+'@kulturhus-native.invalid');const image=item?.querySelector('enclosure');return{error:!!doc.querySelector('parsererror'),enclosures:item?.querySelectorAll('enclosure').length,url:image?.getAttribute('url'),mime:image?.getAttribute('type'),bytes:Number(image?.getAttribute('length')),description:item?.querySelector('description')?.textContent};},{xml:await rss.text(),id:fixture.id});
   check(!parsed.error&&parsed.enclosures===1&&parsed.url===origin+fixture.images[0].url&&parsed.mime==='image/png'&&parsed.bytes===fixture.images[0].bytes,'browser parses the exact original RSS image enclosure '+app.kind+' '+width);
   check(parsed.description.includes('Uhrzeit: 19:00 Uhr')&&parsed.description.includes('Zeitzone: America/New_York')&&parsed.description.includes('Ende: 12.01.2035 22:15 Uhr')&&parsed.description.includes('Status: Ausverkauft')&&!parsed.description.includes('Tickets:'),'RSS preserves actual original timezone, ending and unavailable tickets '+app.kind+' '+width);
   for(const image of fixture.images){
    const decoded=await page.evaluate(async({image,origin})=>{const img=document.createElement('img');img.alt=image.alt;img.width=image.width;img.height=image.height;const loaded=new Promise(resolve=>{img.onload=()=>resolve({ok:true,width:img.naturalWidth,height:img.naturalHeight,alt:img.alt,url:img.currentSrc});img.onerror=()=>resolve({ok:false});});document.body.replaceChildren(img);img.src=origin+image.url;return loaded;},{image,origin});
    check(decoded.ok&&decoded.width===image.width&&decoded.height===image.height&&decoded.alt===image.alt&&decoded.url===origin+image.url,'actual cross-origin anonymous browser decodes original image pixels '+app.kind+' '+width+' '+image.url);
    const actual=await context.request.get(origin+image.url),head=await context.request.head(origin+image.url);
    check(actual.status()===200&&createHash('sha256').update(await actual.body()).digest('hex')===image.sha256&&actual.headers()['content-type']===image.mime&&(await actual.body()).length===image.bytes,'actual anonymous HTTP returns exact native image SHA and byte length '+app.kind+' '+width+' '+image.url);
    check(head.status()===200&&(await head.body()).length===0&&Number(head.headers()['content-length'])===image.bytes,'actual public image HEAD has exact length and empty body '+app.kind+' '+width+' '+image.url);
   }
   const privateImage=await context.request.get(origin+'/media/'+fixture.private_image+'/large');check(privateImage.status()===404,'unpublished private replacement remains inaccessible '+app.kind+' '+width);
   await page.goto(origin+'/site/'+fixture.slug);const save=page.getByRole('link',{name:'Termin speichern',exact:true});
   check(await save.isVisible()&&(await save.boundingBox()).height>=44,'public original exposes a touch-sized calendar download action '+app.kind+' '+width);
   const [download]=await Promise.all([page.waitForEvent('download'),save.tap()]);const bytes=readFileSync(await download.path());
   check(download.suggestedFilename()==='termin-'+fixture.id+'.ics'&&bytes.toString().includes('UID:'+fixture.id+'@kulturhus-native.invalid')&&bytes.toString().includes('DTSTART:20350113T000000Z')&&bytes.toString().includes('DTEND:20350113T031500Z'),'actual mobile tap downloads the exact selected public original as ICS '+app.kind+' '+width);
   check((bytes.toString().match(/BEGIN:VEVENT\r\n/g)||[]).length===1&&!bytes.toString().includes(fixture.draft_title),'download retains one immutable event without private pending edit '+app.kind+' '+width);
   await page.goto(client);
  }
  await context.close();
 }
 check(preflights.length>=8&&preflights.every(u=>origins.some(o=>u===o+'/feeds/events.json'||u===o+'/feeds/veranstaltungen/')),'actual browser preflights stay limited to anonymous public JSON aliases');
 check(!writes&&!external&&!errors.length,'feed-image browser has no application writes, external actions or script errors');
 for(const app of apps){const proof=execFileSync(process.env.PHP_CLI_BINARY||'php',[root+'vereinsverwaltung/tests/support/feed-images-browser-fixture.php','check'],{encoding:'utf8',env:{...process.env,KH_NATIVE_TEST_CONFIG:app.config}});check(proof.includes('Every exact feed-image original'),'all exact original, private draft, grant, approval, job and image bytes retained '+app.kind);}
 console.log(JSON.stringify({checks,business_writes:writes,external_actions:external,preflights:preflights.length}));
}finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
