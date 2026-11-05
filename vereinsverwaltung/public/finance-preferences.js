window.KulturhusPage.register('finance-preferences', pageContext => {
'use strict';
(() => {
 for(const box of document.querySelectorAll('[data-account-preference]')){
  if(box.dataset.preferenceReady)continue;box.dataset.preferenceReady='1';
  const rows=JSON.parse(box.querySelector('[data-account-options]').content.textContent),byCode=new Map(rows.map(r=>[r.code,r]));
  const hidden=box.querySelector('[data-account-codes]'),mode=box.querySelector('[data-account-mode]'),body=box.querySelector('[data-account-selection]'),picker=box.querySelector('[data-account-picker]'),list=box.querySelector('[data-account-selected]'),count=box.querySelector('[data-account-count]'),more=box.querySelector('[data-account-more]'),group=box.querySelector('[data-account-group]');
  const chosen=new Set(hidden.value.split(',').filter(Boolean));let limit=25;
  function render(){
   const codes=Array.from(chosen).sort();hidden.value=codes.join(',');body.hidden=mode.value!=='selection';list.replaceChildren();
   count.textContent=codes.length?`${codes.length} Steuerkonten ausgewählt. Änderungen werden erst mit „Finanz-Vorauswahl speichern“ übernommen.`:'Noch keine Steuerkonten ausgewählt. Suchen Sie ein Konto oder fügen Sie einen Bereich hinzu.';
   for(const code of codes.slice(0,limit)){const row=byCode.get(code),li=document.createElement('li'),label=document.createElement('span'),button=document.createElement('button');label.textContent=code+' · '+(row?.name||'Nicht mehr verfügbar – Auswahl prüfen')+(row&&!row.active?' (inaktiv)':'');button.type='button';button.className='secondary';button.textContent='Entfernen';button.setAttribute('aria-label','Steuerkonto '+code+' entfernen');button.addEventListener('click',()=>{chosen.delete(code);render();});li.append(label,button);list.append(li);}
   more.hidden=codes.length<=limit;
  }
  mode.addEventListener('change',render);
  picker.addEventListener('change',()=>{if(picker.value){chosen.add(picker.value);picker.value='';render();picker.dispatchEvent(new Event('change',{bubbles:true}));}});
  box.querySelector('[data-account-add-group]').addEventListener('click',()=>{for(const row of rows)if(row.active&&(!group.value||row.group===group.value))chosen.add(row.code);render();});
  box.querySelector('[data-account-remove-group]').addEventListener('click',()=>{for(const code of chosen)if(!group.value||byCode.get(code)?.group===group.value)chosen.delete(code);limit=25;render();});
  more.addEventListener('click',()=>{limit+=25;render();});render();
 }
})();
});
