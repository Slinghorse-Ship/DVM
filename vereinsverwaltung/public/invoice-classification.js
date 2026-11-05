window.KulturhusPage.register('invoice-classification', pageContext => {
(()=>{'use strict';
 document.querySelectorAll('[data-invoice-classification]').forEach(wizard=>{
  const form=wizard.closest('form');
 const t=(source,values={})=>window.KulturhusInvoiceEntryText(form,source,values);
  const area=wizard.querySelector('[data-classification-area]');
  const eventBox=wizard.querySelector('[data-classification-event]');
  const eventKind=wizard.querySelector('[data-classification-event-kind]');
  const assetBox=wizard.querySelector('[data-classification-asset]');
  const assetKind=wizard.querySelector('[data-classification-asset-kind]');
  const inputTax=wizard.querySelector('[data-classification-input-tax]');
  const vatRate=wizard.querySelector('[data-classification-vat-rate]');
  const special=wizard.querySelector('[data-classification-special]');
  const status=wizard.querySelector('[data-classification-status]');
  const category=form.querySelector('[name=category]');
  const paymentSource=form.querySelector('[name=payment_source]');
  const recipient=form.querySelector('[data-invoice-recipient]');
  const eligibility=wizard.dataset.inputTaxEligibility||'';
  let spherePreferences={},sphereLabels={};
  try{spherePreferences=JSON.parse(wizard.dataset.spherePreferences||'{}');sphereLabels=JSON.parse(wizard.dataset.sphereLabels||'{}');}catch{}
  let preservePosted=form.querySelector('[data-invoice-recognition]')?.dataset.posted==='1';
  const rows=()=>[...form.querySelectorAll('[data-position-editor] > fieldset')];
  const suggestions={
   membership:{sphere:'1',account:'66310',label:t('Ideeller Bereich · Öffentlichkeitsarbeit / Mitgliedschaft')},
   club:{sphere:'1',account:'68150',label:t('Ideeller Bereich · Vereinsarbeit')},
   event:{
    ideell:{sphere:'1',account:'66310',label:t('Ideeller Bereich · Veranstaltung / Werbung')},
    purpose:{sphere:'3',account:'66310',label:t('Zweckbetrieb · Veranstaltung / Werbung')},
    business:{sphere:'4',account:'66310',label:t('Wirtschaftlicher Geschäftsbetrieb · Veranstaltung / Werbung')}
   },
   asset:{
    consumable:{sphere:'1',account:'68150',label:t('Ideeller Bereich · Verbrauchsmaterial')},
    equipment:{sphere:'1',account:'06300',label:t('Ideeller Bereich · Ausstattung')}
   },
   rent:{sphere:'2',account:'',label:t('Vermögensverwaltung · Steuerkonto bitte prüfen')},
   sales:{sphere:'4',account:'66310',label:t('Wirtschaftlicher Geschäftsbetrieb · Werbung / Verkauf')}
  };
  const categories={membership:'membership',event:'culture',asset:'inventory',rent:'rooms',club:'general',sales:'culture'};
  const preferredSphere=(row,key)=>{
   if(!row)return null;const sphere=spherePreferences[key]||row.sphere;if(!sphere||sphere===row.sphere)return row;
   const detail=row.label.includes('·')?row.label.slice(row.label.indexOf('·')+1).trim():row.label;
   return {...row,sphere,label:(sphereLabels[sphere]||sphere)+' · '+detail};
  };
  const dispatch=field=>{field?.dispatchEvent(new Event('change',{bubbles:true}));field?.dispatchEvent(new Event('input',{bubbles:true}));};
  const field=(row,key)=>row.querySelector('[data-field="'+key+'"]');
  function suggestion(){
   if(area.value==='event')return suggestions.event[eventKind.value]||null;
   if(area.value==='asset')return preferredSphere(suggestions.asset[assetKind.value]||null,'inventory');
   const key=categories[area.value]||'';return preferredSphere(suggestions[area.value]||null,key);
  }
  function complete(){
   const followup=(area.value!=='event'||eventKind.value!=='')&&(area.value!=='asset'||assetKind.value!=='');
   return area.value!==''&&followup&&inputTax.value!==''&&vatRate.value!==''&&special.value!=='';
  }
  function update(applyCategory=false){
   const showEvent=area.value==='event',showAsset=area.value==='asset';
   eventBox.hidden=!showEvent;assetBox.hidden=!showAsset;
   eventKind.required=showEvent;assetKind.required=showAsset;
   const memberInvoice=paymentSource?.value==='private_advance'&&recipient?.value!=='club';
   if(eligibility==='no'||memberInvoice){
    const option=inputTax.querySelector('option[value="deductible"]');
    if(option)option.disabled=true;
    if(inputTax.value==='deductible')inputTax.value='none';
   }
   const proposed=suggestion(),specialCase=special.value!=='none'||area.value==='uncertain';
   if(applyCategory===true&&category&&!specialCase&&categories[area.value])category.value=categories[area.value];
   if(applyCategory===true&&category&&(specialCase||!area.value))category.value='general';
   if(!area.value){status.textContent=t('Wofür war die Ausgabe?');return;}
   if(!complete()){status.textContent=t('Bitte die eingeblendete Zusatzfrage beantworten.');return;}
   if(memberInvoice){status.textContent=t('Rechnung nicht auf den Verein: brutto erfassen, keine Vorsteuer.');return;}
   if(specialCase){status.textContent=t('Sonderfall ausgewählt. Die Positionen bleiben offen und werden später geprüft.');return;}
   status.textContent=t('Übernommen: ')+(proposed?.label||t('Sphäre und Steuerkonto später prüfen'))+'.';
  }
  function setRow(row,key,value){
   const control=field(row,key);
   if(!control)return;
   if(value&&control instanceof HTMLSelectElement&&!Array.from(control.options).some(o=>o.value===value))control.append(new Option(value+t(' · vorgeschlagen'),value));
   control.value=value||'';dispatch(control);
  }
  function applyToRows(targets=rows()){
   if(!complete())return;
   const proposed=suggestion(),specialCase=special.value!=='none'||area.value==='uncertain';
   const rate=vatRate.value;
   const booking=inputTax.value==='deductible'?(rate==='19'?'9':rate==='7'?'8':''):'';
   targets.forEach(row=>{
    setRow(row,'sphere',specialCase?'':(proposed?.sphere||''));
    setRow(row,'account',specialCase?'':(proposed?.account||''));
    setRow(row,'booking_key',specialCase?'':booking);
    setRow(row,'input_tax',inputTax.value);
    if(['0','7','19'].includes(rate))setRow(row,'tax_rate',rate);
   });
   form.dispatchEvent(new Event('invoice-review-update'));
  }
  [area,eventKind,assetKind,inputTax,vatRate,special,paymentSource,recipient].filter(Boolean).forEach(control=>control.addEventListener('change',()=>{preservePosted=false;update(true);applyToRows();}));
  form.addEventListener('invoice-position-added',()=>{if(form.querySelector('[data-invoice-recognition]')?.dataset.hydrating==='1')return;update();const added=rows().at(-1);if(added)applyToRows([added]);});
  form.addEventListener('invoice-review-update',update);
  update(!preservePosted);if(!preservePosted)applyToRows();
 });
})();
});
