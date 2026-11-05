'use strict';
// DOM contract only. No browser, network, persistence or form submission.
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const source=fs.readFileSync(require('node:path').join(__dirname,'../public/asset-guided.js'),'utf8');
let checks=0;
const equal=(a,b)=>{assert.equal(a,b);checks++;};
for(const software of [true,false]){
 const field=(extra={})=>Object.assign({disabled:false,required:false,listeners:{},addEventListener(n,f){this.listeners[n]=f;}},extra);
 const toggle=field({value:''}),date=field({value:'2025-09-15',validity:{valid:true}}),reason=field(),confirmation=field(),documentField=field(),rights=field();
 const sale=software?null:field({selectedOptions:[{dataset:{sale:JSON.stringify({date:'2025-10-01',gross_cents:6000,tax_cents:0,result_cents:2000})}}]});
 const evidenceFields=[confirmation,documentField,...(software?[rights]:[])];
 const section=fields=>({hidden:false,querySelectorAll:()=>fields});
 const evidence=section(evidenceFields),saleSection=section(sale?[sale]:[]),area=section([date,reason,...evidenceFields,...(sale?[sale]:[])]),amounts={},saleAmounts=software?null:{};
 const nodes={'[name=disposal_kind]':toggle,'[data-disposal-fields]':area,'[name=disposal_date]':date,'[data-disposal-amounts]':amounts,'[name=sale_entry]':sale,'[data-sale-amounts]':saleAmounts};
 const panel={dataset:{monthValues:JSON.stringify({9:{depreciation:'80,00 €',remaining:'40,00 €'},10:{depreciation:'90,00 €',remaining:'30,00 €'}})},
  querySelector:s=>nodes[s],querySelectorAll:s=>s==='[data-disposal-sale]'?(software?[]:[saleSection]):[evidence]};
 vm.runInNewContext(source,{document:{getElementById:()=>null,querySelector:()=>null,querySelectorAll:()=>[panel]},Intl});
 equal(area.hidden,true);for(const f of area.querySelectorAll()){equal(f.disabled,true);equal(f.required,false);}
 toggle.value=software?'software_end':'scrap';toggle.listeners.change();
 equal(area.hidden,false);equal(evidence.hidden,false);equal(date.readOnly,false);
 for(const f of [date,reason,...evidenceFields]){equal(f.disabled,false);equal(f.required,true);}
 assert.match(amounts.textContent,/40,00 €/);checks++;
 date.validity.valid=false;date.listeners.input();assert.match(amounts.textContent,/Datum wählen/);checks++;
 if(sale){
  toggle.value='sale';toggle.listeners.change();equal(date.readOnly,true);equal(date.value,'2025-10-01');equal(evidence.hidden,true);equal(sale.disabled,false);
  for(const f of evidenceFields){equal(f.disabled,true);equal(f.required,false);}
 }
 toggle.value='';toggle.listeners.change();equal(area.hidden,true);
 for(const f of area.querySelectorAll()){equal(f.disabled,true);equal(f.required,false);}
}
console.log(checks+' disposal UI checks passed, including software without sale controls');
