'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
const script=fs.readFileSync(path.join(__dirname,'../public/workflows.js'),'utf8');
for(const initiallyChecked of [false,true]){
 const input={disabled:false,required:false},date={hidden:false,querySelector:()=>input},note={hidden:true};
 const fields={'[data-transfer-date]':date,'[data-transfer-instant-note]':note};
 const mode={checked:initiallyChecked,closest:()=>({querySelector:s=>fields[s]}),addEventListener:(name,fn)=>{mode.change=fn;}};
 const document={querySelectorAll:s=>s==='[data-transfer-mode]'?[mode]:[],querySelector:()=>null};
 vm.runInNewContext(script,{document,window:{addEventListener:()=>{}},Set});
 for(const checked of [initiallyChecked,!initiallyChecked,initiallyChecked]){
  mode.checked=checked;mode.change();
  assert.equal(date.hidden,checked);assert.equal(input.disabled,checked);assert.equal(input.required,!checked);assert.equal(note.hidden,!checked);
 }
}
const view=fs.readFileSync(path.join(__dirname,'../views/Finance/Banking/transfer-order.php'),'utf8');
assert.match(view,/type="checkbox" name="transfer_mode" value="instant" data-transfer-mode/);
assert.match(view,/Echtzeitüberweisung \(wenn von Ihrer Bank unterstützt\)/);
assert(!view.includes('<select name="transfer_mode"'));
console.log('PASS checkbox initialization, toggling, required date, restored checked state and requested label (27 assertions); no browser/network writes');
