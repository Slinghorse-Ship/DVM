window.KulturhusPage.register('inventory-individuals', pageContext => {
 'use strict';const form=document.querySelector('[data-individual-create]');if(!form)return;
 const text=s=>window.KulturhusInterface?.text(s)??s,format=(s,v)=>window.KulturhusInterface?.format(s,v)??s.replace(/\{(\w+)\}/g,(m,k)=>String(v[k]??m));
 function total(){const rows=[...form.querySelectorAll('[data-unit-rows] [data-unit-row]')],target=form.querySelector('[data-unit-total]');if(!target)return;let sum=0,unknown=0,invalid=false;for(const row of rows){const input=row.querySelector('[name$="[unit_value]"]');if(!input)continue;const value=input.value.trim();if(value===''){unknown++;continue;}if(!/^\d{1,10}(?:[.,]\d{1,2})?$/.test(value)){invalid=true;continue;}const[w,d='']=value.split(/[.,]/);sum+=Number(w)*100+Number(d.padEnd(2,'0'));}const prices=!!form.querySelector('[name=price_suggestion]');target.textContent=format('{count} vorbereitete Einzelstücke',{count:rows.length})+(prices?' · '+(invalid?text('Bitte die Preisangaben prüfen.'):format('Bekannte Einzelpreise zusammen: {amount} · {unknown} Preise noch unbekannt',{amount:(sum/100).toLocaleString(document.documentElement.lang||'de-DE',{style:'currency',currency:'EUR'}),unknown})): '');}
 form.addEventListener('input',total);total();
 // All preparation, removal and review steps use the same server path without JavaScript.
});
