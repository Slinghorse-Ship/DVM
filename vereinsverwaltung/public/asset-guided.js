window.KulturhusPage.register('asset-guided', pageContext => {
(()=>{
 'use strict';
 const partial=document.getElementById('partial-disposal');
 if(partial){
  const book=partial.querySelector('[name="action"][value="asset-partial-book"]')?.closest('form');
  const edit=partial.querySelector('[name="action"][value="asset-partial-prepare"]')?.closest('form');
  if(book&&edit){
   const notice=document.createElement('p');notice.hidden=true;notice.setAttribute('role','status');notice.textContent='Angaben geändert. Bitte zuerst den Teilabgang neu berechnen.';book.before(notice);
   let dirty=false;
   const changed=()=>{dirty=true;notice.hidden=false;book.querySelectorAll('button,input[type="checkbox"]').forEach(field=>{field.disabled=true;if(field.type==='checkbox')field.checked=false;});};
   edit.addEventListener('input',changed);edit.addEventListener('change',changed);
   book.addEventListener('submit',event=>{if(dirty)event.preventDefault();});
  }
 }
 document.querySelectorAll('[data-asset-disposal]').forEach(panel=>{
  const toggle=panel.querySelector('[name=disposal_kind]'),area=panel.querySelector('[data-disposal-fields]'),date=panel.querySelector('[name=disposal_date]'),amounts=panel.querySelector('[data-disposal-amounts]');
  const values=JSON.parse(panel.dataset.monthValues),sale=panel.querySelector('[name=sale_entry]');
  const money=n=>new Intl.NumberFormat('de-DE',{style:'currency',currency:'EUR'}).format(n/100);
  const update=()=>{
   const active=toggle.value!=='',sold=toggle.value==='sale';area.hidden=!active;
   area.querySelectorAll('input,select,textarea').forEach(field=>{field.disabled=!active;field.required=active;});
   for(const [selector,enabled] of [['[data-disposal-sale]',sold],['[data-disposal-scrap]',['scrap','software_end'].includes(toggle.value)]])panel.querySelectorAll(selector).forEach(section=>{
    section.hidden=!enabled;section.querySelectorAll('input,select,textarea').forEach(field=>{field.disabled=!enabled;field.required=enabled;});
   });
   date.readOnly=sold;
   const selected=sold&&sale?.selectedOptions[0]?.dataset.sale?JSON.parse(sale.selectedOptions[0].dataset.sale):null;
   if(sold)date.value=selected?.date||'';
   const saleAmounts=panel.querySelector('[data-sale-amounts]');
   if(saleAmounts)saleAmounts.textContent=selected?'Verkaufspreis: '+money(selected.gross_cents)+' · Umsatzsteuer: '+money(selected.tax_cents)+' · '+(selected.result_cents<0?'Verlust: ':'Gewinn: ')+money(Math.abs(selected.result_cents)):'';
   const value=date.value&&date.validity.valid?values[Number(date.value.slice(5,7))]:null;
   amounts.textContent=value?'Abschreibung bis einschließlich Abgangsmonat: '+value.depreciation+'. Auszubuchender Restwert: '+value.remaining+'.':'Datum wählen. Abschreibung und auszubuchender Restwert werden berechnet.';
  };
  toggle.addEventListener('change',update);sale?.addEventListener('change',update);date.addEventListener('input',update);date.addEventListener('change',update);update();
 });
 document.querySelectorAll('[data-cost-events]').forEach(panel=>{
  const initialise=row=>{
   const kind=row.querySelector('[data-cost-kind]'),fields=row.querySelector('[data-cost-event-fields]');
   const update=()=>{
    const active=kind.value!=='';fields.hidden=!active;
    fields.querySelectorAll('input,select,textarea').forEach(field=>{field.disabled=!active;field.required=active&&field.type!=='checkbox';});
    for(const [selector,enabled] of [['[data-cost-impairment]',kind.value==='impairment'],['[data-cost-disposal]',kind.value==='disposal']]){
     const section=row.querySelector(selector);section.hidden=!enabled;
     section.querySelectorAll('input,select,textarea').forEach(field=>{field.disabled=!enabled;field.required=enabled&&field.type!=='checkbox';});
    }
    row.querySelector('[name$="[confirmed]"]').required=active;
    row.querySelector('[name$="[no_proceeds]"]').required=kind.value==='disposal';
   };
   kind.addEventListener('change',update);update();
  };
  panel.querySelectorAll('[data-cost-event]').forEach(initialise);
  const add=panel.querySelector('[data-cost-add]');
  add.addEventListener('click',()=>{
   const index=panel.querySelectorAll('[data-cost-event]').length;if(index>=20)return;
   const row=panel.querySelector('[data-cost-event]').cloneNode(true);
   row.querySelector('legend').textContent='Bewertungsereignis '+(index+1);
   row.querySelectorAll('[name]').forEach(field=>{
    field.name=field.name.replace(/^valuation\[\d+\]/,'valuation['+index+']');
    if(field.type==='checkbox')field.checked=false;
    else if(field.type==='hidden')field.value=Array.from(crypto.getRandomValues(new Uint8Array(16)),n=>n.toString(16).padStart(2,'0')).join('');
    else field.value='';
   });
   add.before(row);initialise(row);row.querySelector('[data-cost-kind]').focus();add.disabled=index+1>=20;
  });
 });
 const form=document.querySelector('[data-asset-guided]');if(!form)return;
 const rule=form.querySelector('[data-asset-rule]'),months=form.querySelector('[data-asset-months]'),own=form.querySelector('[data-asset-own-life]');
 rule.addEventListener('change',()=>{const proposal=rule.selectedOptions[0]?.dataset.months;if(proposal){months.value=proposal;own.open=false;}else{own.open=true;months.focus();}});
 months.addEventListener('change',()=>{if(rule.value&&rule.selectedOptions[0].dataset.months!==months.value)rule.value='';});
 const method=form.querySelector('[data-asset-method]');if(!method)return;
 const gwg=form.querySelector('[data-asset-gwg]'),linear=form.querySelector('[data-asset-linear]'),account=form.elements.account,residual=form.elements.residual;
 const degressive=form.querySelector('[data-asset-degressive]'),cost=form.querySelector('[data-asset-cost]');
 let linearAccount=account.value==='06700'?'':account.value,linearResidual=residual.value;
 const update=()=>{
  const immediate=method.value==='gwg_immediate',costBased=method.value==='cost';
  gwg.hidden=!immediate;gwg.disabled=!immediate;linear.hidden=immediate||costBased;linear.disabled=immediate||costBased;
  if(cost){cost.hidden=!costBased;cost.disabled=!costBased;cost.querySelectorAll('select').forEach(input=>{input.required=costBased;});}
  gwg.querySelectorAll('input').forEach(input=>{input.required=immediate;});
  if(degressive){const selected=method.value==='degressive';degressive.hidden=!selected;degressive.disabled=!selected;degressive.querySelectorAll('input').forEach(input=>{input.required=selected&&input.name!=='degressive_switch';});}
  if(immediate){linearAccount=account.value==='06700'?linearAccount:account.value;linearResidual=residual.value;account.value='06700';residual.value='0,00';}
  else if(account.readOnly){account.value=linearAccount;residual.value=linearResidual;}
  if(costBased)residual.value='0,00';
  account.readOnly=immediate;account.required=costBased;residual.readOnly=immediate||costBased;
 };
 method.addEventListener('change',update);update();
})();
});
