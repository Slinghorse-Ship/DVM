/* Native-client component contract in Chromium. Transport is simulated, not a device proof. */
import {readFile} from 'node:fs/promises';
import assert from 'node:assert/strict';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const code=await readFile(new URL('../shared/native-client.js',import.meta.url),'utf8');
const browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE||'/usr/bin/chromium',args:['--no-sandbox']});
let checks=0;function check(value,label){assert.ok(value,label);checks++;console.log('PASS native-client '+label);}
try{
 for(const platform of ['android','ios'])for(const locale of ['de','en']){
  const page=await browser.newPage();await page.route('https://app.example/**',route=>route.fulfill({contentType:'text/html',body:'<!doctype html><html lang="'+locale+'"><body><p data-chat-permission-status></p></body></html>'}));await page.goto('https://app.example/vereinsverwaltung/?view=chat');
  const result=await page.evaluate(async({code,platform})=>{
   const nonce=crypto.randomUUID(),packets=[],chunks=[];let nativeReceive,failChunk=false,holdPermission=false;
   const response=packet=>nativeReceive({nonce,id:packet.id,result:packet.operation==='file-start'?'owned-job':packet.operation==='notification-permission'?'granted':true});
   const request=packet=>{packets.push(packet);if(holdPermission&&packet.operation==='notification-permission')return;if(failChunk&&packet.operation==='file-chunk'){nativeReceive({nonce,id:packet.id,error:'Owned transfer rejected'});return;}if(packet.operation==='file-chunk')chunks.push(packet.data);response(packet);};
   const config={platform,nonce,origin:location.origin,permission:'default',payments:false};
   if(platform==='ios'){window.webkit={messageHandlers:{dvmApp:{postMessage:request}}};nativeReceive=packet=>window.DVMNativeReceive(packet);}
   (0,eval)(`(function(){const DVM_NATIVE_CONFIGURATION=${JSON.stringify(config)};${code}})()`);
   if(platform==='android'){
    const channel=new MessageChannel();channel.port1.onmessage=event=>request(JSON.parse(event.data));nativeReceive=packet=>channel.port1.postMessage(JSON.stringify(packet));window.postMessage(nonce,location.origin,[channel.port2]);await new Promise(resolve=>setTimeout(resolve,20));
   }
   const initial=Notification.permission;await Notification.requestPermission();
   const value='Own HEIC/RAW export ä🙂\n'.repeat(10000),blob=new Blob([value],{type:'application/octet-stream'});
   await DVMNative.save(blob,'Original-datei.heic');
   const arrays=chunks.map(encoded=>Uint8Array.from(atob(encoded),c=>c.charCodeAt(0))),copy=new Blob(arrays),original=await blob.arrayBuffer(),actual=await copy.arrayBuffer();
   let clicked=0;const notification=new Notification('Eigene Nachricht',{tag:'kh-chat-original-thread:7'});notification.onclick=()=>clicked++;nativeReceive({nonce:'wrong',event:'notification-click',notification:notification.id});nativeReceive({nonce,event:'notification-click',notification:notification.id});
   // Android delivery is asynchronous through a real browser MessageChannel.
   await new Promise(resolve=>setTimeout(resolve,20));notification.close();await new Promise(resolve=>setTimeout(resolve,20));
   const result={platform,initial,permission:Notification.permission,label:document.querySelector('[data-chat-permission-status]').textContent,original:Array.from(new Uint8Array(original)),actual:Array.from(new Uint8Array(actual)),packets,clicked,payments:DVMNative.payments};
   failChunk=true;let rejected=false;try{await DVMNative.save(new Blob(['own failed export']),'own-failed.txt');}catch{rejected=true;}await new Promise(resolve=>setTimeout(resolve,20));result.rejected=rejected;result.cancelled=packets.some(packet=>packet.operation==='file-cancel');holdPermission=true;const pending=Notification.requestPermission().then(()=>false,()=>true);DVMNative._dispose();result.disposed=await pending;return result;
  },{code,platform});
  check(result.initial==='default'&&result.permission==='granted',platform+' explicit native permission response');
  check(result.label.includes(locale==='en'?'App notifications allowed':'App-Hinweise erlaubt'),platform+'/'+locale+' permission display follows the native result and current language');
  check(Buffer.from(result.original).equals(Buffer.from(result.actual)),platform+' multiple chunks preserve the exact original HEIC/RAW-labelled bytes');
  check(result.packets.filter(p=>p.operation==='file-chunk').length>1,platform+' real multi-chunk transfer');
  check(result.packets.some(p=>p.operation==='file-finish'),platform+' native finish acknowledged');
  check(result.clicked===1,platform+' stale nonce rejected and confirmed click delivered once');
  check(result.packets.some(p=>p.operation==='notification'&&p.target==='https://app.example/vereinsverwaltung/?view=chat&item=original-thread'),platform+' notification retains its original chat assignment');
  check(result.payments===false,platform+' unconfigured payments never advertised as available');check(result.rejected&&result.cancelled,platform+' rejected transfer propagates error and cancels its own partial export');check(result.disposed,platform+' disposed page rejects its pending native request');await page.close();
 }
 for(const platform of ['android','ios'])for(const locale of ['de','en']){
  const page=await browser.newPage();await page.route('https://app.example/**',route=>route.fulfill({contentType:'text/html',body:'<!doctype html><html lang="'+locale+'"><body></body></html>'}));await page.goto('https://app.example/vereinsverwaltung/');
  const result=await page.evaluate(async({code,platform})=>{
   const nonce=crypto.randomUUID(),packets=[];let receive;const request=packet=>{packets.push(packet);receive({nonce,id:packet.id,result:true});};
   if(platform==='ios'){window.webkit={messageHandlers:{dvmApp:{postMessage:request}}};receive=packet=>window.DVMNativeReceive(packet);}
   (0,eval)('(function(){const DVM_NATIVE_CONFIGURATION='+JSON.stringify({platform,nonce,origin:location.origin,payments:true})+';'+code+'})()');
   if(platform==='android'){const channel=new MessageChannel();channel.port1.onmessage=event=>request(JSON.parse(event.data));receive=packet=>channel.port1.postMessage(JSON.stringify(packet));window.postMessage(nonce,location.origin,[channel.port2]);await new Promise(resolve=>setTimeout(resolve,20));}
   const available=DVMNative.payments,normal=await DVMNative.pay({amount:100}),before=packets.length,blocked=[];
   for(const path of ['/demo','/demo/','/demo/vereinsverwaltung/']){history.pushState(null,'',path);let error='';try{await DVMNative.pay({amount:100});}catch(failure){error=failure.message;}blocked.push({available:DVMNative.payments,error,packets:packets.length});}
   history.pushState(null,'','/democracy');const democracy=DVMNative.payments,after=await DVMNative.pay({amount:100});DVMNative._dispose();return {available,normal,before,blocked,democracy,after,packets:packets.length};
  },{code,platform});
  check(result.available&&result.normal===true,platform+'/'+locale+' ordinary configured payment still reaches simulated transport');
  check(result.blocked.every(item=>item.available===false),platform+'/'+locale+' exact demo root and descendants dynamically disable device payments');
  check(result.blocked.every(item=>item.error===(locale==='en'?'Device payments are disabled in the demo.':'In der Demo sind Gerätezahlungen gesperrt.')),platform+'/'+locale+' demo payment rejection follows current interface language');
  check(result.blocked.every(item=>item.packets===result.before),platform+'/'+locale+' demo payments are rejected before any native packet');
  check(result.democracy&&result.after===true&&result.packets===result.before+1,platform+'/'+locale+' democracy path remains outside exact demo boundary');await page.close();
 }
 console.log(JSON.stringify({suite:'native-client-component',checks,transport:'simulated; real browser MessageChannel on Android branch',device_proof:false,live_transport:false}));
}finally{await browser.close();}
