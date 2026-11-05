'use strict';
// Execute the actual serviceworker with an isolated browser API. No network delivery.
const vm=require('node:vm'),fs=require('node:fs'),assert=require('node:assert/strict');
const listeners={},shown=[],opened=[];
vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname,'../public/push-worker.js'),'utf8'),{
 URL,self:{registration:{scope:'https://example.invalid/vereinsverwaltung/',showNotification:async(title,options)=>shown.push({title,...options})},addEventListener:(name,fn)=>listeners[name]=fn},
 clients:{openWindow:async url=>opened.push(url)}
});
let checks=0;
const targets={chat:'?view=chat',invoices:'?view=finance&tab=inbox',payments:'?view=finance&tab=bank',events:'/veranstaltungen/',documents:'?view=portal&tab=documents',contributions:'?view=portal&tab=documents',minutes:'?view=minutes',meetings:'?view=calendar'};
(async()=>{
 for(const [type,target] of Object.entries(targets)){
  let promise;listeners.push({data:{json:()=>({type,title:'PRIVATE',body:'SECRET',url:'https://evil.invalid'})},waitUntil:p=>promise=p});await promise;
  const message=shown.at(-1);assert.equal(message.title,'Kulturhus');assert.equal(message.tag,'kulturhus-'+type);assert.doesNotMatch(JSON.stringify(message),/PRIVATE|SECRET|evil/);assert.match(message.icon,/push-icon-192.png$/);checks++;
  listeners.notificationclick({notification:{data:message.data,close(){}},waitUntil:p=>promise=p});await promise;assert(opened.at(-1).endsWith(target));checks++;
 }
 for(const data of [undefined,{json:()=>{throw new Error('malformed');}},{json:()=>({type:'__proto__',url:'https://evil.invalid'})},{json:()=>null}]){
  let promise;listeners.push({data,waitUntil:p=>promise=p});await promise;assert.equal(shown.at(-1).tag,'kulturhus-chat');checks++;
 }
 let promise;listeners.notificationclick({notification:{data:{type:'constructor',url:'https://evil.invalid'},close(){}},waitUntil:p=>promise=p});await promise;assert.equal(opened.at(-1),'/vereinsverwaltung/?view=chat');checks++;
 console.log(JSON.stringify({passed:checks,network:'none',runtime:'actual service worker in isolated browser API'}));
})().catch(e=>{console.error(e);process.exitCode=1;});
