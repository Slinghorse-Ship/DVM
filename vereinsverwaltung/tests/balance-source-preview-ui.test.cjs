'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../public/balance-source-preview.js'), 'utf8');
let checks=0;
function check(a,b){assert.equal(a,b);checks++;}
function fixture(loading=false,present=true){
  const buttons=[{disabled:false},{disabled:false}],notice={hidden:true},reviewed={checked:true};
  const form={querySelector:key=>key==='[data-source-stale]'?notice:reviewed,querySelectorAll:()=>buttons};
  const listeners={},ready={};const section={dataset:{},addEventListener:(type,handler)=>(listeners[type]??=[]).push(handler)};
  const document={readyState:loading?'loading':'complete',getElementById:()=>present?section:null,addEventListener:(type,handler)=>ready[type]=handler};
  return {buttons,notice,reviewed,form,listeners,ready,section,document};
}
for(const name of ['recognised_on','private_paid_on','donation_use','classify_purchases','purchase_uses[1]','purchase_uses[50]'])for(const type of ['input','change']){
  const f=fixture();const context=vm.createContext({document:f.document});vm.runInContext(source,context);vm.runInContext(source,context);
  check(f.listeners[type].length,1);
  f.listeners[type][0]({target:{name,closest:()=>f.form}});
  check(f.buttons.every(b=>b.disabled),true);check(f.notice.hidden,false);check(f.reviewed.checked,false);
}
for(const name of ['reviewed','correction_reason','source_hash','balance_source','purchase_uses[0]']){
  const f=fixture();vm.runInNewContext(source,{document:f.document});f.listeners.change[0]({target:{name,closest:()=>f.form}});
  check(f.buttons.some(b=>b.disabled),false);check(f.notice.hidden,true);
}
const f=fixture();vm.runInNewContext(source,{document:f.document});f.listeners.change[0]({target:{name:'recognised_on',closest:()=>null}});check(f.notice.hidden,true);
const later=fixture(true);vm.runInNewContext(source,{document:later.document});check(Object.keys(later.listeners).length,0);later.ready.DOMContentLoaded();check(later.listeners.change.length,1);
const absent=fixture(false,false);vm.runInNewContext(source,{document:absent.document});check(Object.keys(absent.listeners).length,0);
console.log(`${checks} calendar-source UI checks passed; no network or database writes`);
