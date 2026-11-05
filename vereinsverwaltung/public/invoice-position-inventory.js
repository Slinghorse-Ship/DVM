(()=>{'use strict';
 const element=(tag,text)=>{const node=document.createElement(tag);if(text!==undefined)node.textContent=text;return node;};
 const money=value=>{const match=String(value).trim().match(/^(\d{1,8})(?:[.,](\d{1,2}))?$/);return match?Number(match[1])*100+Number((match[2]||'').padEnd(2,'0')):null;};
 const euros=cents=>(cents/100).toLocaleString('de-DE',{minimumFractionDigits:2,maximumFractionDigits:2})+' €';
 let sequence=0;
 window.KulturhusInvoiceInventory={enhance({row,data,options,onChange}){
 const t=(source,values={})=>window.KulturhusInvoiceEntryText(row.closest('form'),source,values);
  if(!options?.can_create)return null;
  const original=data.new_item&&typeof data.new_item==='object'&&!Array.isArray(data.new_item)?data.new_item:null;
  let active=!!original,initialized=!!original;
  const section=element('section'),button=element('button',t('Neuen Gegenstand anlegen')),panel=element('div'),status=element('p'),summary=element('p');
  section.className='invoice-inventory-action';button.type='button';button.className='secondary';button.dataset.inventoryCreate='';panel.dataset.inventoryDraft='';panel.id='invoice-inventory-draft-'+(++sequence);button.setAttribute('aria-controls',panel.id);status.setAttribute('role','status');summary.dataset.inventoryQuantity='';
  panel.append(element('h4',t('Neuer Gegenstand aus dieser Position')),element('p',t('Artikel, Menge, Preis und Rechnung werden übernommen. Ergänzen Sie den Lagerort. Gespeichert wird zusammen mit der Rechnung.')),summary);
  const fields={},grid=element('div');grid.className='invoice-inventory-fields';
  function field(key,title,value,type='input'){
   const label=element('label',title),input=element(type);input.dataset.inventoryField=key;fields[key]=input;label.append(input);grid.append(label);if(type==='input')input.value=value||'';return input;
  }
  const name=field('name',t('Name im Inventar'),original?.name??String(data.description||'').replace(/^Pos\.\s*\d+\s*[·:]\s*/i,'').slice(0,120));name.required=true;name.maxLength=160;
  const location=field('home_location',t('Wo wird der Gegenstand aufbewahrt?'),'','select');location.required=true;location.dataset.selectSearch='';location.append(new Option(t('Lagerort suchen und auswählen'),''));
  for(const place of options.locations||[]){const option=new Option(place.name,place.id);option.dataset.version=String(place.version);location.append(option);}
  if(original?.home_location&&![...location.options].some(option=>option.value===original.home_location))location.append(new Option(t('Bisheriger Lagerort nicht mehr verfügbar – neu auswählen'),original.home_location));
  location.value=original?.home_location||'';
  const version=element('input');version.type='hidden';version.dataset.inventoryField='location_version';version.value=original?.location_version||'';fields.location_version=version;panel.append(version);
  location.addEventListener('change',()=>{version.value=location.selectedOptions[0]?.dataset.version||'';});
  const unit=field('unit',t('Einheit im Inventar'),'','select');unit.required=true;
  for(const [value,label]of Object.entries(options.units||{}))unit.append(new Option(label,value));
  const normalized={'stk':'Stück','stk.':'Stück','stück':'Stück','satz':'Satz','sätze':'Satz','set':'Set','sets':'Set'};
  const unitValue=original?.unit??normalized[String(data.unit||'').toLocaleLowerCase()]??(Object.hasOwn(options.units||{},data.unit)?data.unit:'Stück');
  if(![...unit.options].some(option=>option.value===unitValue))unit.append(new Option(unitValue+t(' · prüfen'),unitValue));unit.value=unitValue;
  const extra=element('details');extra.append(element('summary',t('Kategorie und Inhalt eines Satzes ergänzen')));
  const category=field('category',t('Inventarkategorie (optional)'),original?.category||'');category.maxLength=100;const list=element('datalist');list.id='invoice-inventory-categories-'+sequence;category.setAttribute('list',list.id);for(const value of options.categories||[])list.append(new Option(value));panel.append(list);
  const content=field('content_quantity',t('Wie viele Teile enthält eine Einheit?'),original?.content_quantity??'1');content.type='number';content.min='1';content.max='1000000';content.step='1';content.required=true;
  const contentUnit=field('content_unit',t('Einheit der enthaltenen Teile'),'','select');for(const [value,label]of Object.entries(options.units||{}))contentUnit.append(new Option(label,value));if(original?.content_unit&&![...contentUnit.options].some(option=>option.value===original.content_unit))contentUnit.append(new Option(original.content_unit+t(' · prüfen'),original.content_unit));contentUnit.value=original?.content_unit||'Stück';contentUnit.required=true;
  const extraGrid=element('div');extraGrid.className='invoice-inventory-fields';for(const input of [category,content,contentUnit])extraGrid.append(input.parentElement);extra.append(extraGrid,element('p',t('Beispiel: 3 Sätze mit je 4 Füßen ergeben 12 Stück. Die Rechnung bleibt bei 3 Sätzen. Seriennummern können Sie später beim Gegenstand ergänzen.')));if(Number(original?.content_quantity||1)>1||original?.category)extra.open=true;
  panel.append(grid,extra);section.append(button,status,panel);row.append(section);
  const state={get enabled(){return active;},draft(){return active?Object.fromEntries(Object.entries(fields).map(([key,input])=>[key,input.value])):null;},sync({index,enabled}){
   const linked=row.querySelectorAll('[data-field=items]').length>0;button.disabled=!enabled||(!active&&linked);button.setAttribute('aria-expanded',String(active));button.textContent=active?t('Neuanlage zurücknehmen'):t('Neuen Gegenstand anlegen');panel.hidden=!active;
   const existing=row.querySelector('[data-existing-inventory]');if(existing){for(const input of existing.querySelectorAll('select,button'))input.disabled=!enabled||active;}
   for(const [key,input]of Object.entries(fields)){input.disabled=!active||!enabled;if(active&&enabled)input.name='invoice_positions['+index+'][new_item]['+key+']';else{input.removeAttribute('name');window.KulturhusFormValidation?.clear(input);}}
   status.textContent=active?(enabled?t('Für die gemeinsame Speicherung vorgemerkt. Noch nicht im Inventar gespeichert.'):t('Neuanlage pausiert. Einzelpositionen wieder einschalten, um den Gegenstand mitzuspeichern.')):linked?t('Dieser Position ist bereits Inventar zugeordnet. Entfernen Sie diese Zuordnung, wenn Sie stattdessen einen neuen Gegenstand anlegen möchten.'):'';
   if(active){
    name.setCustomValidity(new TextEncoder().encode(name.value).length>160?t('Bitte die Bezeichnung des Gegenstands kürzen.'):'');
    category.setCustomValidity(new TextEncoder().encode(category.value).length>100?t('Bitte die Bezeichnung der Kategorie kürzen.'):'');
    unit.setCustomValidity(Object.hasOwn(options.units||{},unit.value)?'':t('Bitte eine der angebotenen Einheiten auswählen.'));
    location.setCustomValidity(location.value&&(!location.selectedOptions[0]?.dataset.version||version.value!==location.selectedOptions[0].dataset.version)?t('Dieser Lagerort ist nicht mehr aktuell. Bitte den Lagerort erneut auswählen.'):'');
    const quantity=row.querySelector('[data-field=quantity]')?.value||'',whole=/^\d+(?:[.,]0+)?$/.test(quantity)&&Number(quantity.replace(',','.'))>0;
    row.querySelector('[data-field=quantity]').setCustomValidity(whole?'':t('Für einen Inventargegenstand ist eine ganze Stückzahl erforderlich. Prüfen Sie die Menge dieser Rechnungsposition.'));
    const amount=money(row.querySelector('[data-field=amount]')?.value),price=money(row.querySelector('[data-field=unit_price]')?.value),basis=row.querySelector('[data-field=price_basis]')?.value,rawRate=row.querySelector('[data-field=tax_rate]')?.value,rate=Number(rawRate),gross=price===null?null:Math.round(price*(basis==='net'?(100+rate)/100:1)),count=Number(quantity.replace(',','.')),exact=whole&&['net','gross'].includes(basis)&&['0','7','19'].includes(rawRate)&&gross!==null&&gross*count===amount;
    const plural={Satz:'Sätze',Set:'Sets',Packung:'Packungen',Karton:'Kartons',Kiste:'Kisten',Rolle:'Rollen',Flasche:'Flaschen'},unitLabel=count===1?unit.value:plural[unit.value]||unit.value;
    summary.textContent=whole?t('Vorgemerkt: {quantity} × {unit}.',{quantity,unit:unit.selectedOptions[0]?.textContent||unit.value})+(Number(content.value)>1?t(' Inhalt je Einheit: {quantity} × {unit}; insgesamt {total}.',{quantity:content.value,unit:contentUnit.selectedOptions[0]?.textContent||contentUnit.value,total:count*Number(content.value)}):'')+' '+(exact?t('Wert je Einheit: {amount}.',{amount:euros(gross)}):t('Ein eindeutiger Stückwert ist noch nicht belegt; er bleibt zunächst offen.')):t('Bitte die Menge der Rechnungsposition prüfen: Inventar benötigt eine ganze Anzahl.');
   }else{row.querySelector('[data-field=quantity]').setCustomValidity('');for(const input of [name,category,unit,location])input.setCustomValidity('');}
  }};
  button.addEventListener('click',()=>{active=!active;if(active&&!initialized){name.value=String(row.querySelector('[data-field=description]')?.value||'').replace(/^Pos\.\s*\d+\s*[·:]\s*/i,'').slice(0,120);const sourceUnit=row.querySelector('[data-field=unit]')?.value||'';unit.value=normalized[sourceUnit.toLocaleLowerCase()]||(Object.hasOwn(options.units||{},sourceUnit)?sourceUnit:'Stück');initialized=true;}onChange();if(active){panel.hidden=false;name.focus();}});
  return state;
 }};
})();
