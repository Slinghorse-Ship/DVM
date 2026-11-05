window.KulturhusPage.register('inventory-invoice-recognition', pageContext => {
 const {fetch} = pageContext;
 const t=s=>window.KulturhusInterface?.text(s)??s,fmt=(s,v={})=>t(s).replace(/\{([a-z_]+)\}/g,(m,k)=>Object.hasOwn(v,k)?String(v[k]):m);
(()=>{'use strict';const section=document.querySelector('[data-inventory-invoice-ocr]');if(!section)return;const itemForm=[...document.forms].find(f=>f.querySelector('[name="data[name]"]')),csrf=section.querySelector('[name=csrf]')?.value||itemForm?.querySelector('[name=csrf]')?.value;
 const draftKey='inventory-invoice-draft:'+section.dataset.inventoryInvoiceOcr+':'+csrf;
 if(itemForm){const version=()=>itemForm.querySelector('[name=version]')?.value||'';try{const saved=JSON.parse(sessionStorage.getItem(draftKey)||'null');if(saved){const note=document.createElement('p');note.setAttribute('role',saved.version===version()?'status':'alert');if(saved.version===version()){for(const record of saved.fields||[]){const input=[...itemForm.elements].find(f=>f.name===record.name&&f.type===record.type&&(!['checkbox','radio'].includes(f.type)||f.value===record.value));if(!input||input.disabled)continue;if(['checkbox','radio'].includes(input.type))input.checked=!!record.checked;else if(input.multiple&&Array.isArray(record.selected))for(const option of input.options)option.selected=record.selected.includes(option.value);else input.value=record.value;input.dispatchEvent(new Event('input',{bubbles:true}));input.dispatchEvent(new Event('change',{bubbles:true}));}sessionStorage.removeItem(draftKey);note.textContent=t('Deine noch nicht gespeicherten Gegenstandsangaben wurden nach dem Rechnungsupload wiederhergestellt. Bitte prüfen und Gegenstand speichern.');}else{note.textContent=t('Der Gegenstand wurde inzwischen geändert. Der frühere Entwurf wurde nicht automatisch übernommen. Vergleiche die nachstehenden Entwurfsangaben mit dem aktuellen Gegenstand.');const details=document.createElement('details');details.dataset.inventoryDraftConflict='';const summary=document.createElement('summary');summary.textContent=t('Frühere Entwurfsangaben ansehen');details.append(summary);for(const record of saved.fields||[]){const line=document.createElement('p');line.textContent=(record.label||record.name)+': '+(['checkbox','radio'].includes(record.type)?(record.checked?t('ausgewählt'):t('nicht ausgewählt')):record.selected?.join(', ')||record.value);details.append(line);}itemForm.prepend(details);}itemForm.prepend(note);}}catch{}
  itemForm.addEventListener('submit',event=>{if(section.querySelector('form[data-inventory-invoice] .live-upload-list li')){event.preventDefault();let note=section.querySelector('[data-ocr-save-order]');if(!note){note=document.createElement('p');note.dataset.ocrSaveOrder='';note.setAttribute('role','alert');section.prepend(note);}note.textContent=t('Bitte zuerst die Rechnungsdateien unten prüfen und speichern oder entfernen. Deine Gegenstandsangaben bleiben dabei erhalten; anschließend den Gegenstand speichern.');note.scrollIntoView({block:'center'});}});
  section.querySelector('form[data-inventory-invoice]')?.addEventListener('submit',event=>queueMicrotask(()=>{if(event.defaultPrevented)return;try{const fields=[];for(const input of itemForm.elements)if(input.name&&input.type!=='hidden'&&input.type!=='file'&&input.type!=='password'&&!input.disabled)fields.push({name:input.name,type:input.type,value:input.value,checked:input.checked,selected:input.multiple?[...input.selectedOptions].map(o=>o.value):undefined,label:[...(input.labels?.[0]?.childNodes||[])].filter(n=>n.nodeType===Node.TEXT_NODE).map(n=>n.textContent).join(' ').trim()});sessionStorage.setItem(draftKey,JSON.stringify({version:version(),fields}));}catch{}}));
 }
 const el=(tag,value)=>{const n=document.createElement(tag);if(value!==undefined)n.textContent=value;return n;};
 function attach(host,request,row=null){
  if(host._ocrBound)return;host._ocrBound=true;
  const endpoint=new URL(section.dataset.ocrUrl,location.href),stored=typeof request.doc==='string';
  if(stored){endpoint.searchParams.set('doc',request.doc);endpoint.searchParams.set('revision',request.revision);}else endpoint.searchParams.set('invoice_request',request.requestKey);
  const button=request.button||el('button',t('Rechnung hier auslesen'));button.type='button';button.className='btn btn-outline theme-secondary';if(!request.button)host.append(button);
  const box=el('section');box.dataset.inventoryOcrResult='';box.className='inventory-ocr-result';const status=el('p');status.setAttribute('role','status');const output=el('div');box.append(status,output);host.append(box);
  const read=stored?el('button',t('Vorhandenes Ergebnis dieser Dateifassung prüfen')):null;
  if(read){read.type='button';read.className='btn btn-outline theme-secondary';read.dataset.inventoryOcrRead='';box.append(read);}
  const show=result=>{
   output.replaceChildren();status.setAttribute('role','status');
   if(!result){status.textContent=t('Für diese ursprüngliche Dateifassung ist noch kein Ergebnis gespeichert. Original prüfen und eine neue Erkennung bewusst starten.');return;}
   if(result.state!=='ready'){status.textContent=t(result.error||'Noch kein bestätigtes Erkennungsergebnis verfügbar. Original prüfen; eine laufende Erkennung nicht doppelt starten.');return;}
   status.textContent=t('Vorschläge erkannt. Am Original prüfen; nichts wurde automatisch gespeichert oder gebucht.');
   const details=el('details');details.open=true;details.append(el('summary',t('Erkannte Rechnungsangaben und Positionen')));const list=el('dl');list.className='inventory-ocr-fields';
   for(const[name,value]of Object.entries(result.fields||{}))list.append(el('dt',t(name)),el('dd',name==='Belegart'&&['Rechnung','Gutschrift'].includes(value)?t(value):String(value)));details.append(list);
   for(const warning of result.warnings||[])details.append(el('p',t(String(warning))));
   const invoice=result.fields||{};
   if(row?.date&&/^\d{4}-\d{2}-\d{2}$/.test(invoice['Rechnungsdatum']||'')){
    const date=el('button',t('Rechnungsdatum als Dokumentdatum übernehmen'));date.type='button';date.className='btn btn-outline theme-secondary';date.onclick=()=>{row.date.value=invoice['Rechnungsdatum'];row.date.dispatchEvent(new Event('input',{bubbles:true}));status.textContent=t('Dokumentdatum übernommen. Datei anschließend wie gewohnt prüfen und speichern.');};details.append(date);
   }
   for(const[index,position]of(result.items||[]).entries()){
    const number=String(position.number??index+1),card=el('fieldset');card.append(el('legend',fmt('Position {number}',{number})),el('p',fmt('{name} · Menge {quantity} {unit} · Einzelpreis laut Datei {price} · Positionsbetrag {amount}',{name:position.name??t('Bezeichnung unklar'),quantity:position.quantity??'?',unit:position.unit??'',price:position.unit_price??'?',amount:position.line_amount??position.gross_amount??position.net_amount??'?'})));
    if(position.uncertain||position.ambiguous)card.append(el('p',t('Netto/Brutto und Werte am Original prüfen.')));
    const nameLabel=el('label',t('Bezeichnung für den Gegenstand (Vorschlag)')),name=el('input');name.value=position.name??'';name.maxLength=200;nameLabel.append(name);
    const priceLabel=el('label',t('Geprüfter Wert je Einheit (€) – optional')),price=el('input');price.inputMode='decimal';price.maxLength=30;price.placeholder=t('Am Original prüfen und eintragen');price.addEventListener('input',()=>{price.setCustomValidity('');window.KulturhusFormValidation?.clear(price);});priceLabel.append(price);card.append(nameLabel,priceLabel);
    const apply=el('button',t('Diese Angaben in den Gegenstandsentwurf übernehmen'));apply.type='button';apply.className='btn btn-outline theme-secondary';apply.dataset.inventoryOcrApply='';
    apply.onclick=()=>{
     if(!itemForm){status.textContent=t('Gegenstandsformular nicht verfügbar.');return;}
     if(itemForm.querySelector('[name=version]')?.value!==section.dataset.inventoryOcrVersion){status.textContent=t('Gegenstand wurde inzwischen geändert. Ursprünglichen Entwurf behalten und heutigen Stand neu prüfen.');return;}
     if(price.value.trim()&&!/^\d{1,10}(?:[,.]\d{1,2})?$/.test(price.value.trim())){status.textContent=t('Stückwert mit höchstens zwei Nachkommastellen prüfen.');price.setCustomValidity(status.textContent);window.KulturhusFormValidation?.show(price,status.textContent);price.focus();return;}
     const targetName=itemForm.querySelector('[name="data[name]"]'),targetPrice=itemForm.querySelector('[name="data[unit_value]"]'),note=itemForm.querySelector('[name="data[value_note]"]');
     if(name.value.trim())targetName.value=name.value.trim();if(price.value.trim()&&targetPrice)targetPrice.value=price.value.trim();
     if(note)note.value=fmt('Rechnung {reference} vom {date}, Position {number}',{reference:invoice['Rechnungsnummer']??'',date:invoice['Rechnungsdatum']??'',number})+(position.quantity?fmt(' · {quantity} {unit}',{quantity:position.quantity,unit:position.unit??t('Einheiten')}):'')+(price.value.trim()?fmt(' · geprüfter Wert je Einheit {price} €',{price:price.value.trim()}):'');
     for(const field of[targetName,targetPrice,note])field?.dispatchEvent(new Event('input',{bubbles:true}));
     status.textContent=t('Angaben in den Gegenstandsentwurf übernommen. Bestand und Mengen wurden nicht geändert.')+' '+t(row?'Zuerst die Rechnungsdateien prüfen und speichern. Deine Gegenstandsangaben bleiben erhalten; danach den Gegenstand ausdrücklich speichern.':'Prüfe die Angaben im Gegenstandsformular und speichere den Gegenstand ausdrücklich.')+(price.value.trim()&&!targetPrice?' '+t('Dieser Gegenstand hat Stückdetails; den Preis dort beim betreffenden Stück prüfen.'):'');
    };card.append(apply);details.append(card);
   }
   if(!(result.items||[]).length)details.append(el('p',t('Keine sicheren Positionen erkannt. Bezeichnung und Stückwerte im Gegenstandsformular manuell prüfen.')));output.append(details);
  };
  const run=async reading=>{
   button.disabled=true;if(read)read.disabled=true;status.setAttribute('role','status');status.textContent=t(reading?'Vorhandenes Ergebnis wird gelesen. Es wird keine neue Erkennung gestartet.':'Rechnung wird ausgelesen. Der Gegenstand und seine Eingaben bleiben unverändert …');
   try{
    const url=new URL(endpoint),options={credentials:'same-origin',headers:{Accept:'application/json'}};
    if(reading)url.searchParams.set('inventory_ocr_read','1');else{
     const data=new FormData();data.set('csrf',csrf);data.set('inventory_item',section.dataset.inventoryInvoiceOcr);data.set('inventory_version',section.dataset.inventoryOcrVersion);data.set('inventory_ocr_source',stored?'document':'staged');
     for(const k of(stored?['doc','revision']:['token','upload_key','upload_action','upload_field']))data.set(k,String(request[k]));data.set('inventory_ocr_complete','1');options.method='POST';options.body=data;
    }
    const response=await fetch(url,options);let body;try{body=await response.json();}catch{throw Error('unconfirmed');}
    if(!response.ok){const error=Error(body.error||t('Erkennung nicht verfügbar.'));error.safe=true;error.original=body.original;throw error;}
    show(body.result);
   }catch(error){
    if(pageContext.signal.aborted)return;status.setAttribute('role','alert');status.textContent=(error.safe?error.message:t('Erkennung nicht bestätigt.'))+' '+t(stored?'Prüfe zuerst das vorhandene Ergebnis dieser Dateifassung, bevor du erneut startest. Deine Eingaben und die Datei bleiben erhalten.':'Die private Vorbereitung und deine Eingaben bleiben erhalten. Prüfe das Original vor einer bewussten erneuten Erkennung; es wird nichts gebucht.');
    if(error.original){output.replaceChildren();const original=el('pre',JSON.stringify(error.original,null,2));original.dataset.inventoryOcrOriginal='';output.append(original);}
   }finally{button.disabled=false;if(read)read.disabled=false;}
  };
  button.addEventListener('click',()=>run(false));read?.addEventListener('click',()=>run(true));
 }
 function staged({row,form,action,field}){if(!form.dataset.inventoryInvoice||!/[.](xml|pdf|jpe?g|png|heic|heif)$/i.test(row.name))return;attach(row.li,{token:row.token,upload_key:row.key,upload_action:action,upload_field:field,requestKey:new URL(form.getAttribute('action')||location.href,location.href).searchParams.get('invoice_request')},row);}
 pageContext.on(document,'inventory-invoice-upload-ready',e=>staged(e.detail));document.querySelectorAll('.live-upload-list li').forEach(li=>{if(li._inventoryOcr)staged(li._inventoryOcr);});section.querySelectorAll('[data-inventory-ocr-document]').forEach(button=>attach(button.closest('li'),{doc:button.dataset.inventoryOcrDocument,revision:button.dataset.revision,button}));
})();
});
