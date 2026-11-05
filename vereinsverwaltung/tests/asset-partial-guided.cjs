'use strict';
// In-memory event/DOM contract. No browser, network or form submission.
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const source=fs.readFileSync(require('node:path').join(__dirname,'../public/asset-guided.js'),'utf8');
let checks=0;
for(const event of ['input','change']){
 const listeners={},submitters={},button={disabled:false},confirmation={type:'checkbox',disabled:false,checked:true};let notice;
 const book={before:n=>notice=n,querySelectorAll:()=>[button,confirmation],addEventListener:(name,fn)=>submitters[name]=fn};
 const edit={addEventListener:(name,fn)=>listeners[name]=fn};
 const panel={querySelector:selector=>({closest:()=>selector.includes('asset-partial-book')?book:edit})};
 const document={getElementById:()=>panel,querySelectorAll:()=>[],querySelector:()=>null,createElement:()=>({setAttribute(name,value){this[name]=value;}})};
 vm.runInNewContext(source,{document});
 assert.equal(notice.hidden,true);assert.equal(button.disabled,false);checks+=2;
 let prevented=false;submitters.submit({preventDefault:()=>prevented=true});assert.equal(prevented,false);checks++;
 listeners[event]();
 assert.equal(notice.hidden,false);assert.equal(notice.role,'status');assert.match(notice.textContent,/neu berechnen/);checks+=3;
 assert.equal(button.disabled,true);assert.equal(confirmation.disabled,true);assert.equal(confirmation.checked,false);checks+=3;
 submitters.submit({preventDefault:()=>prevented=true});assert.equal(prevented,true);checks++;
}
vm.runInNewContext(source,{document:{getElementById:()=>null,querySelectorAll:()=>[],querySelector:()=>null}});checks++;
console.log(checks+' partial-review UI event checks passed; no browser, database or network');
