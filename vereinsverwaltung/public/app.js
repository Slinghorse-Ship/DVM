window.KulturhusPage.register('app', pageContext => {
const uiText = source => window.KulturhusInterface?.text(source) ?? source;
const mobileNavigation = matchMedia('(max-width:1000px)');
const menuButton = document.querySelector('[data-menu]');
const navigation = document.getElementById('navigation');
const appSurface = document.querySelector('.app');
const collapseButton = document.querySelector('[data-collapse]');
function closeSidebarMenus() {
 navigation?.querySelectorAll('.nav-group').forEach(group=>group.open=false);
}
function savedSidebarSmall() {try{return sessionStorage.getItem('kulturhus-sidebar-small')==='1';}catch{return false;}}
function setSidebarSmall(small, remember=true) {
 document.body.classList.toggle('sidebar-small', small);
 collapseButton?.setAttribute('aria-expanded', String(!small));
 collapseButton?.setAttribute('aria-label', small ? uiText('Menü ausklappen') : uiText('Menü einklappen'));
 if(small)closeSidebarMenus();
 if(remember)try{sessionStorage.setItem('kulturhus-sidebar-small',small?'1':'0');}catch{}
}
function setMenu(open, focus = true) {
 open = open && mobileNavigation.matches;
 document.body.classList.toggle('menu-open', open);
 menuButton?.setAttribute('aria-expanded', String(open));
 if (navigation) navigation.inert = mobileNavigation.matches && !open;
 if (appSurface) appSurface.inert = open;
 if (open) { setSidebarSmall(false,false); navigation?.querySelector('[data-menu-close]')?.focus(); }
 else if (focus && mobileNavigation.matches) menuButton?.focus();
}
pageContext.on(menuButton, 'click', () => setMenu(!document.body.classList.contains('menu-open')));
document.querySelectorAll('[data-menu-close],[data-menu-backdrop]').forEach(button => pageContext.on(button, 'click', () => setMenu(false)));
pageContext.on(collapseButton, 'click', () => {if(mobileNavigation.matches)setMenu(false);else setSidebarSmall(!document.body.classList.contains('sidebar-small'));});
pageContext.on(navigation, 'click', event => {
 const summary=event.target.closest('.nav-group>summary');
 if (!summary || mobileNavigation.matches || !document.body.classList.contains('sidebar-small')) return;
 event.preventDefault();const open=!summary.parentElement.open;closeSidebarMenus();summary.parentElement.open=open;summary.focus();
});
pageContext.on(document, 'click',event=>{if(document.body.classList.contains('sidebar-small')&&!navigation?.contains(event.target))closeSidebarMenus();});
setSidebarSmall(!mobileNavigation.matches&&savedSidebarSmall(),false);
pageContext.on(mobileNavigation, 'change', () => {setMenu(false, false);setSidebarSmall(!mobileNavigation.matches&&savedSidebarSmall(),false);});
setMenu(false, false);
pageContext.on(document, 'keydown', event => {
 if(event.key==='Escape'&&document.body.classList.contains('sidebar-small')){const current=navigation?.querySelector('.nav-group[open]>summary');if(current){event.preventDefault();closeSidebarMenus();current.focus();}return;}
 if (!document.body.classList.contains('menu-open')) return;
 if (event.key === 'Escape') { event.preventDefault(); setMenu(false); }
 if (event.key === 'Tab') {
  const controls = Array.from(navigation.querySelectorAll('a,button,input,select,summary,[tabindex]')).filter(el => !el.disabled && el.tabIndex >= 0 && el.getClientRects().length);
  const first = controls[0], last = controls[controls.length - 1];
  if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
  else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
 }
});

document.querySelectorAll('[data-toggle]').forEach(button=>button.addEventListener('click',()=>{const panel=document.getElementById(button.dataset.toggle);if(panel){panel.hidden=!panel.hidden;button.setAttribute('aria-expanded',String(!panel.hidden));}}));
document.querySelectorAll('[data-column]').forEach(box=>{const panel=box.closest('[data-columns-panel]');if(panel)panel.hidden=false;box.addEventListener('change',()=>{document.querySelectorAll(`[data-col="${box.dataset.column}"]`).forEach(cell=>cell.hidden=!box.checked);const field=document.querySelector(`[data-export-column="${box.dataset.column}"]`);if(field)field.disabled=!box.checked;});});
const selected=()=>{const count=document.querySelectorAll('[name="selected[]"]:checked').length;const label=document.querySelector('[data-selection-count]');if(label){label.hidden=false;label.textContent=`${count} ${uiText('ausgewählt')}`;}document.querySelectorAll('[data-needs-selection]').forEach(button=>button.disabled=count===0);};
document.querySelectorAll('[data-select-all]').forEach(box=>box.disabled=false);
document.querySelector('[data-select-all]')?.addEventListener('change',event=>{document.querySelectorAll('[name="selected[]"]').forEach(box=>box.checked=event.target.checked);selected();});
document.querySelectorAll('[name="selected[]"]').forEach(box=>box.addEventListener('change',selected));selected();
// Confirmation forms are guarded centrally by dialogs.js before submit handlers run.
'use strict';
for(const wizard of document.querySelectorAll('[data-application-wizard]')){
 let step=0;const sections=[...wizard.querySelectorAll('[data-wizard-step]')];
 const show=next=>{step=Math.max(0,Math.min(sections.length-1,next));sections.forEach((section,i)=>section.hidden=i!==step);wizard.querySelectorAll('[data-wizard-go]').forEach(button=>{if(Number(button.dataset.wizardGo)===step)button.setAttribute('aria-current','step');else button.removeAttribute('aria-current');});};
 wizard.addEventListener('click',event=>{if(event.target.closest('[data-wizard-next]'))show(step+1);if(event.target.closest('[data-wizard-back]'))show(step-1);const go=event.target.closest('[data-wizard-go]');if(go)show(Number(go.dataset.wizardGo));});
 wizard.addEventListener('invalid',event=>{const section=event.target.closest('[data-wizard-step]');if(section)show(Number(section.dataset.wizardStep));},true);
 wizard.addEventListener('keydown',event=>{if(event.key==='Enter'&&event.target.tagName==='INPUT'&&step<sections.length-1){event.preventDefault();show(step+1);}});show(0);
}
for (const modal of document.querySelectorAll('dialog[data-modal]')) {
  modal.removeAttribute('open');
  modal.showModal();
  modal.addEventListener('cancel', event => { event.preventDefault(); window.KulturhusNavigation.navigate(modal.dataset.closeUrl); });
}

const ruleList=document.querySelector('[data-rule-list]');
const numberRuleButtons=()=>ruleList?.querySelectorAll('[data-remove-rule]').forEach((button,i)=>button.value='remove:'+i);
document.querySelector('[data-add-rule]')?.addEventListener('click',event=>{event.preventDefault();if(ruleList&&ruleList.children.length<24){ruleList.append(document.getElementById('anniversary-rule-template').content.cloneNode(true));numberRuleButtons();ruleList.lastElementChild.querySelector('input').focus();}});
ruleList?.addEventListener('click',event=>{const button=event.target.closest('[data-remove-rule]');if(button){event.preventDefault();button.closest('.anniversary-rule').remove();numberRuleButtons();document.querySelector('[data-add-rule]').focus();}});

for(const editor of document.querySelectorAll('[data-positions-editor]')){
 const list=editor.querySelector('[data-position-list]');
 const dialog=editor.querySelector('[data-tax-dialog]');
 const positionTexts=JSON.parse(editor.dataset.positionText||'{}');
 const t=(source,values={})=>String(Object.hasOwn(positionTexts,source)?positionTexts[source]:source).replace(/\{([a-z_]+)\}/g,(token,key)=>Object.hasOwn(values,key)?String(values[key]):token);
 const preserveInput=editor.dataset.positionPreserveInput==='1';
 const originalTaxes=JSON.parse(editor.dataset.positionOriginalTaxes||'[]');

 let selectedPosition=null, selectedGroup='';
 const format=cents=>`${Math.floor(cents/100).toLocaleString('de-DE')},${String(cents%100).padStart(2,'0')} €`;
 const parse=value=>{const match=value.trim().match(/^(\d{1,10})(?:[,.](\d{1,2}))?$/);return match?Number(match[1])*100+Number((match[2]||'').padEnd(2,'0')):null;};
 const kind=()=>editor.closest('form').querySelector('[name="kind"]')?.value||editor.dataset.positionKind;
 const refresh=()=>{
  let gross=0,tax=0,valid=true;
  const rows=[...list.querySelectorAll('[data-position]')];
  rows.forEach((row,i)=>{row.querySelector('[data-position-number]').textContent=String(i+1);row.querySelector('[data-remove-position]').disabled=rows.length===1;
   const taxField=row.querySelector('[data-position-tax]');taxField.setCustomValidity(preserveInput&&(['donation','goods'].includes(kind())?taxField.value!=='0':!['0','7','19'].includes(taxField.value))?t('Steuersatz passt nicht zur gewählten Belegart. Ursprüngliche Auswahl prüfen.'):'');
   const cents=parse(row.querySelector('[data-position-amount]').value), rate=Number(row.querySelector('[data-position-tax]').value);const inputTax=row.querySelector('[name="position_input_tax[]"]'),key=row.querySelector('[data-position-key]');if(inputTax){inputTax.disabled=kind()!=='expense';const inputLabel=inputTax.closest('label');if(inputLabel)inputLabel.hidden=kind()!=='expense';}const keyRate={'2':7,'3':19,'8':7,'9':19,'101':19,'102':7,'401':19,'402':7}[key.value];key.setCustomValidity(inputTax&&!inputTax.disabled&&inputTax.value==='none'&&key.value?t('Kein Vorsteuerabzug: BU-Schlüssel leer lassen.'):keyRate&&keyRate!==rate?t('BU-Schlüssel und Steuer laut Rechnung passen nicht zusammen.'):'');
   if(cents===null||cents<=0||cents>100000000000||!Number.isFinite(rate)||(preserveInput&&(['donation','goods'].includes(kind())?taxField.value!=='0':!['0','7','19'].includes(taxField.value))))valid=false;else{gross+=cents;const rounded=Math.floor((cents*rate+Math.floor((100+rate)/2))/(100+rate)),original=originalTaxes[i];tax+=original&&Number(original.gross_cents)===cents&&Number(original.tax_rate)===rate&&Number.isInteger(original.tax_cents)&&Math.abs(original.tax_cents-rounded)<=1?original.tax_cents:rounded;}
  });
  if(gross>100000000000)valid=false;
  editor.querySelector('[data-gross-total]').textContent=valid?format(gross):'–';editor.querySelector('[data-tax-total]').textContent=valid?format(tax):'–';editor.querySelector('[data-net-total]').textContent=valid?format(gross-tax):'–';
  editor.querySelector('[data-add-position]').disabled=rows.length>=50;
 };
 const setRates=()=>{
  for(const select of list.querySelectorAll('[data-position-key]')){
   const unavailable=['donation','goods','contribution'].includes(kind());
   for(const option of select.options)option.disabled=unavailable&&option.value!==''&&!(preserveInput&&option.selected);
   if(unavailable&&!preserveInput)select.value='';
  }
  for(const select of list.querySelectorAll('[data-position-tax]')){
   const old=select.value,rates=['donation','goods'].includes(kind())?['0']:['0','7','19'];
   if(preserveInput&&old!==''&&!rates.includes(old))rates.push(old);
   select.replaceChildren(...rates.map(rate=>{const option=document.createElement('option');option.value=rate;option.textContent=`${rate} %`;option.selected=rate===old;return option;}));
   select.setCustomValidity(preserveInput&&(['donation','goods'].includes(kind())?old!=='0':!['0','7','19'].includes(old))?t('Steuersatz passt nicht zur gewählten Belegart. Ursprüngliche Auswahl prüfen.'):'');
  }
  refresh();
 };
 editor.querySelector('[data-add-position]').addEventListener('click',()=>{if(list.children.length>=50)return;list.append(editor.querySelector('[data-position-template]').content.cloneNode(true));setRates();list.lastElementChild.querySelector('input').focus();});
 editor.addEventListener('input',refresh);editor.addEventListener('change',refresh);editor.closest('form').querySelector('[name="kind"]')?.addEventListener('change',setRates);
 const yearOptions=JSON.parse(editor.dataset.positionYearOptions||'{}'),dateInput=editor.closest('form').querySelector('[name=document_date],[name=received_on]');let currentYear=editor.dataset.positionYear;
 const updateYear=()=>{
  const year=dateInput?.value.slice(0,4)||currentYear;if(year===currentYear)return;
  const exact=Object.hasOwn(yearOptions,year),config=yearOptions[year]||yearOptions.fallback||{accounts:[],keys:{}},fallback=year!==''&&year!=='0'&&(!exact||config.fallback);currentYear=year;
  const updateRows=[...list.querySelectorAll('[data-position]'),...editor.querySelector('[data-position-template]').content.querySelectorAll('[data-position]')];
  for(const row of updateRows)for(const[selector,entries,title]of[['[data-position-account]',config.accounts.map(a=>[a.code,a.code+' · '+a.name]),t('Noch nicht kontiert')],['[data-position-key]',Object.entries(config.keys).map(([code,k])=>[code,code+' · '+k.name]),t('Ohne gesonderten Schlüssel')]]){
   const field=row.querySelector(selector),selected=field.value;field.replaceChildren(new Option(title,''));for(const[code,name]of entries)field.append(new Option(name,code));
   if(selected&&!entries.some(([code])=>code===selected))field.append(new Option(selected+' · '+t('Belegjahr erneut prüfen'),selected));field.value=selected;field.dispatchEvent(new Event('change',{bubbles:true}));
  }
  results=config.accounts.map(a=>({...a,group:a.group??a.category}));limit=100;selectedGroup='';
  dialog.querySelector('[data-tax-year-heading]').textContent=fallback?t('SKR42 {catalog} · historischer Beleg {year}',{catalog:config.catalog_year||'',year}):exact?t('Kontenplan {year} · Kulturhus',{year}):t('Kein geprüfter Jahreskontenplan');
  dialog.querySelector('[data-tax-year-footer]').textContent=fallback?t('SKR42 {catalog} · {count} auswählbare Steuerkonten. Originaldatum {year} bleibt erhalten; Exportjahr separat prüfen.',{catalog:config.catalog_year||'',count:results.length,year}):exact?t('SKR42 {year} · {count} auswählbare Steuerkonten.',{year,count:results.length}):t('Originaldatum erhalten; steuerliche Zuordnung klären.');
  editor.querySelector('[data-position-year-status]').textContent=fallback?t('SKR42 {catalog} für historischen Beleg {year} auswählen. Das Rechnungsjahr bleibt erhalten.',{catalog:config.catalog_year||'',year}):exact?t('Belegjahr {year} geändert. Bestehende Kontierung anhand dieses Jahreskontenplans prüfen.',{year}):t('Bitte ein gültiges Belegdatum eingeben.');filter();refresh();
 };
 dateInput?.addEventListener('change',updateYear);
 const search=dialog.querySelector('[data-tax-search]');let results=JSON.parse(editor.querySelector('[data-tax-catalog]').content.textContent),limit=100;
 const filter=()=>{const term=search.value.trim().toLocaleLowerCase('de-DE');const groups={'':0};
  const matches=results.filter(row=>{const text=`${row.code} ${row.name} ${row.function} ${['40450','40500','40550','40600','40650','40700'].includes(row.code)?'Spende':''}`.toLocaleLowerCase('de-DE');if(!text.includes(term))return false;groups['']++;groups[row.category]=(groups[row.category]||0)+1;return !selectedGroup||selectedGroup===row.category;});
  const nodes=matches.slice(0,limit).map(row=>{const button=document.createElement('button');button.type='button';button.className='tax-result';button.dataset.taxPick=row.code;const code=document.createElement('strong');code.textContent=row.code;const label=document.createElement('span');label.textContent=row.name;const detail=document.createElement('small');detail.textContent=`${row.group}${row.function?' · '+row.function:''}${row.custom?' · '+t('vereinseigen'):''}`;label.append(detail);button.append(code,label);return button;});
  dialog.querySelector('[data-tax-rows]').replaceChildren(...nodes);
  for(const badge of dialog.querySelectorAll('[data-group-count]'))badge.textContent=String(groups[badge.dataset.groupCount]||0);
  for(const button of dialog.querySelectorAll('[data-tax-group]'))button.setAttribute('aria-pressed',String(button.dataset.taxGroup===selectedGroup));
  dialog.querySelector('[data-tax-count]').textContent=t('{count} Steuerkonten · {shown} angezeigt',{count:matches.length,shown:Math.min(limit,matches.length)});dialog.querySelector('[data-tax-empty]').hidden=matches.length>0;dialog.querySelector('[data-tax-more]').hidden=limit>=matches.length;
 };
 const close=()=>{dialog.close();selectedPosition?.querySelector('[data-open-tax]').focus();};
 dialog.addEventListener('cancel',event=>{event.preventDefault();close();});search.addEventListener('input',()=>{limit=100;filter();});
 editor.addEventListener('click',event=>{
  const remove=event.target.closest('[data-remove-position]');if(remove&&list.children.length>1){remove.closest('[data-position]').remove();refresh();editor.querySelector('[data-add-position]').focus();}
  const open=event.target.closest('[data-open-tax]');if(open){selectedPosition=open.closest('[data-position]');selectedGroup='';limit=100;search.value='';filter();dialog.showModal();search.focus();}
  const group=event.target.closest('[data-tax-group]');if(group){selectedGroup=group.dataset.taxGroup;limit=100;filter();}
  const result=event.target.closest('[data-tax-pick]');if(result&&selectedPosition){const select=selectedPosition.querySelector('[data-position-account]');const row=results.find(r=>r.code===result.dataset.taxPick);if(![...select.options].some(o=>o.value===row.code)){const option=document.createElement('option');option.value=row.code;option.textContent=`${row.code} · ${row.name}`;select.append(option);}select.value=row.code;select.dispatchEvent(new Event('input',{bubbles:true}));select.dispatchEvent(new Event('change',{bubbles:true}));close();}
  if(event.target.closest('[data-tax-more]')){limit+=100;filter();}if(event.target.closest('[data-close-tax]'))close();
 });
 setRates();
}

});
