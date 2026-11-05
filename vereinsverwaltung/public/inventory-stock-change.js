window.KulturhusPage.register('inventory-stock-change', pageContext => {
(()=>{'use strict';
 const text=source=>window.KulturhusInterface?.text(source)??source,format=(source,values)=>window.KulturhusInterface?.format(source,values)??source.replace(/\{(\w+)\}/g,(match,key)=>String(values[key]??match));
 const box=document.querySelector('#inventory-stock-change'),form=box?.querySelector('form');if(!form)return;
 const kind=form.querySelector('[name="data[kind]"]'),quantity=form.querySelector('[name="data[quantity]"]'),location=form.querySelector('[name="data[location]"]'),destination=form.querySelector('[name="data[destination]"]'),selection=form.querySelector('.iv-piece-selection'),pieceInputs=[...(selection?.querySelectorAll('input[type=checkbox]')||[])],stocks=JSON.parse(box.dataset.stockRows||'{}');
 const places=Object.entries(box.dataset.locationOptions?JSON.parse(box.dataset.locationOptions):Object.fromEntries([...location.options].map(o=>[o.value,o.textContent]))),free=id=>Math.max(0,Number(stocks[id]?.shelf||0)-Number(stocks[id]?.reserved||0));
 const transferNote=document.createElement('p');transferNote.setAttribute('role','status');transferNote.dataset.transferGuidance='';location.closest('label').after(transferNote);
 let lastMode=null,firstRefresh=true,syncingSource=false,staleSource=false;
 function sourceOptions(){
  if(lastMode===kind.value)return;lastMode=kind.value;
  const transferring=kind.value==='transfer',choices=places.filter(([id])=>id!==''&&(!transferring||free(id)>0)),previous=location.value,restoring=firstRefresh&&box.dataset.movePosted==='1';
  staleSource=!!(transferring&&restoring&&box.dataset.originalSource&&!choices.some(([id])=>id===box.dataset.originalSource));
  let chosen=choices.some(([id])=>id===previous)?previous:'';
  if(transferring&&!chosen&&choices.length===1&&!restoring)chosen=choices[0][0];
  const original=restoring?box.dataset.originalSource:'';if(original&&!choices.some(([id])=>id===original))chosen=original;
  const next=[new Option(text(transferring&&!choices.length?'Kein frei verfügbarer Bestand':'Bitte auswählen'),''),...choices.map(([id,name])=>new Option(name,id))];if(original&&!choices.some(([id])=>id===original))next.push(new Option(text('Ausgewählter Lagerort nicht mehr verfügbar – bitte neu auswählen'),original));
  firstRefresh=false;syncingSource=true;location.replaceChildren(...next);location.value=chosen;location.dispatchEvent(new Event('change',{bubbles:true}));syncingSource=false;
 }
 const note=document.createElement('p');note.setAttribute('role','status');note.dataset.stockGuidance='';quantity.closest('label').after(note);
 const selectNote=document.createElement('p');selectNote.setAttribute('role','status');selectNote.dataset.stockSelectionStatus='';if(selection)selection.querySelector('summary').after(selectNote);
 function refresh(){
  if(syncingSource)return;sourceOptions();
  const mode=kind.value,stock=stocks[location.value],shelf=Number(stock?.shelf||0),amount=Number(quantity.value),count=mode==='count';
  if(destination){destination.disabled=mode!=='transfer';destination.required=mode==='transfer';destination.closest('label').hidden=mode!=='transfer';}
  transferNote.hidden=mode!=='transfer';transferNote.textContent=mode!=='transfer'?'':staleSource?text('Der gewählte Ausgangsort hat keinen frei verfügbaren Bestand mehr. Bitte neu auswählen.'):location.options.length===1?text('Für diesen Gegenstand gibt es derzeit keinen frei verfügbaren Bestand zum Umlagern.'):text('Als Ausgangsort stehen nur Lagerorte mit frei verfügbarem Bestand dieses Gegenstands zur Auswahl.');
  form.querySelector(':scope > button').textContent=text(count?'Anzahl speichern':'Lagerbewegung bestätigen');
  let remove=0;if(quantity.value!==''&&Number.isInteger(amount)&&amount>=0){if(count)remove=Math.max(0,shelf-amount);else if(['consume','discard'].includes(mode))remove=amount;}
  note.textContent=count&&stock&&quantity.value!==''&&Number.isInteger(amount)&&amount>=0?format('Bisher {quantity} {unit} am gewählten Lagerort. Neue Anzahl: {new_quantity}.',{quantity:shelf,unit:box.dataset.unit,new_quantity:amount})+(Number(stock.lent)>0||Number(stock.blocked)>0?' '+text('Ausgeliehene und gesperrte Stücke bleiben zusätzlich erhalten.'):''):'';
  if(!selection)return;
  const relevant=remove>0,partial=relevant&&remove<pieceInputs.length;selection.hidden=!relevant;if(relevant)selection.open=true;
  pieceInputs.forEach(input=>{input.disabled=!partial;input.setCustomValidity('');if(!partial)window.KulturhusFormValidation?.clear(input);});
  const selected=pieceInputs.filter(input=>input.checked).length;
  selectNote.textContent=partial?format(remove===1?'Welches Stück gehört nicht mehr dazu? {selected} von {quantity} ausgewählt.':'Welche {quantity} Stücke gehören nicht mehr dazu? {selected} von {quantity} ausgewählt.',{selected,quantity:remove}):relevant?format('Alle {quantity} Stücke werden aus diesem Bestand entfernt. Eine einzelne Auswahl ist nicht nötig.',{quantity:remove}):'';
  if(partial){const first=pieceInputs[0],message=selected===remove?'':format(remove===1?'Bitte genau {quantity} Stück auswählen, das aus dem Bestand entfernt wird.':'Bitte genau {quantity} Stücke auswählen, die aus dem Bestand entfernt werden.',{quantity:remove});first.setCustomValidity(message);if(!message)window.KulturhusFormValidation?.clear(first);}
 }
 location.addEventListener('change',()=>{if(!syncingSource)staleSource=false;});
 [kind,quantity,location,...pieceInputs].forEach(input=>{input.addEventListener('input',refresh);input.addEventListener('change',refresh);});refresh();
})();
});
