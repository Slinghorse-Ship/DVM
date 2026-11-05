const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
const code=fs.readFileSync(path.join(__dirname,'../public/event-vouchers.js'),'utf8');
function element(extra={}){return Object.assign({events:{},textContent:'',value:'',checked:false,hidden:false,addEventListener(n,f){this.events[n]=f;}},extra);}
const filter=element(),selected=element(),all=element(),none=element(),scan=element(),stopButton=element(),msg=element(),input=element();
const people=['Mara','Mika'].map(name=>{const checkbox=element();return element({textContent:name,querySelector:()=>checkbox});});
let stopped=0,submitted=0;
const video=element({readyState:2,videoWidth:640,videoHeight:480,play:async()=>{}});
const camera=element({hidden:true,querySelector:()=>video});
const form=element({dataset:{voucherEvent:'a'.repeat(32)},querySelector:s=>({'[data-voucher-scan]':scan,'[data-voucher-camera]':camera,'[data-voucher-scan-status]':msg,'[data-voucher-stop]':stopButton,'[name=q]':input}[s]),requestSubmit:()=>{++submitted;}});
const window=element({jsQR:()=>({data:'KH:1:'+'A'.repeat(32)+':0123456789ABCDEF'})});
const fields={'[data-voucher-member-search]':filter,'[data-voucher-selected]':selected,'[data-voucher-select-all]':all,'[data-voucher-select-none]':none,'[data-voucher-search-form]':form};
const document=element({querySelector:s=>fields[s]||null,querySelectorAll:s=>s==='[data-voucher-member]'?people:[],getElementById:()=>null,createElement:()=>({getContext:()=>({drawImage(){},getImageData:()=>({data:new Uint8ClampedArray(4),width:1,height:1})})})});
const navigator={mediaDevices:{getUserMedia:async()=>({getTracks:()=>[{stop(){++stopped;}}]})}};
vm.runInNewContext(code,{document,window,navigator,location:{hash:''},HTMLDetailsElement:class{},setTimeout:()=>1,clearTimeout(){}});
(async()=>{
  filter.value='Mara';filter.events.input();assert.equal(people[1].hidden,true);
  all.events.click();assert(people.every(p=>p.querySelector().checked&&!p.hidden));assert.equal(selected.textContent,'2 Mitglieder ausgewählt');
  none.events.click();assert(people.every(p=>!p.querySelector().checked));
  input.value='KH:1:'+'B'.repeat(32)+':0123456789ABCDEF';let prevented=false;form.events.submit({preventDefault(){prevented=true;}});assert(prevented);assert.match(msg.textContent,/anderen Veranstaltung/);
  input.value='KH:1:'+'A'.repeat(32)+':0123456789ABCDEF';prevented=false;form.events.submit({preventDefault(){prevented=true;}});assert(!prevented);assert.equal(input.value,'0123456789ABCDEF');
  await scan.events.click();assert.equal(submitted,1);assert.equal(stopped,1);assert(camera.hidden);assert.equal(input.value,'0123456789ABCDEF');
  // No redemption is possible: the scanner submits only the read-only search form.
  navigator.mediaDevices.getUserMedia=async()=>{throw Object.assign(new Error(),{name:'NotAllowedError'});};await scan.events.click();assert.match(msg.textContent,/Kamerazugriff nicht erlaubt/);assert.equal(submitted,1);
  navigator.mediaDevices.getUserMedia=()=>new Promise(resolve=>{navigator.finish=resolve;});const pending=scan.events.click();stopButton.events.click();navigator.finish({getTracks:()=>[{stop(){++stopped;}}]});await pending;assert(camera.hidden);assert.equal(stopped,2);assert.equal(submitted,1);
  console.log('PASS select all/none, USB QR input, wrong-event rejection, camera lookup only, close tracks, denied permission and cancelled permission race');
})().catch(e=>{console.error(e);process.exitCode=1;});
