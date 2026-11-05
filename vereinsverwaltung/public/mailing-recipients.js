window.KulturhusPage.register('mailing-recipients', pageContext => {
 const {timeout: setTimeout} = pageContext;
(()=>{'use strict';
const norm=s=>s.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLocaleLowerCase('de');
document.querySelectorAll('[data-mailing-recipients]').forEach(root=>{
const messages=JSON.parse(root.dataset.recipientMessages||'{}'),t=(source,values={})=>(messages[source]??source).replace(/\{([a-z_]+)\}/g,(match,key)=>Object.hasOwn(values,key)?String(values[key]):match);
const rows=[...root.querySelectorAll('[data-recipient-row]')].map(el=>({el,input:el.querySelector('input'),label:el.textContent.trim(),group:el.dataset.group}));
const search=root.querySelector('[data-recipient-search]'),list=root.querySelector('[role=listbox]'),status=root.querySelector('[data-recipient-status]'),selected=root.querySelector('[data-recipient-selected]'),group=root.querySelector('[data-recipient-group]');let found=[],active=-1;
const close=()=>{list.hidden=true;search.setAttribute('aria-expanded','false');search.removeAttribute('aria-activedescendant');active=-1;};
function render(){selected.replaceChildren();const chosen=rows.filter(r=>r.input.checked);status.textContent=t('{count} von höchstens 250 Personen ausgewählt.',{count:chosen.length});chosen.forEach(r=>{const b=document.createElement('button');b.type='button';b.textContent=r.label+' · '+t('Entfernen');b.addEventListener('click',()=>{r.input.checked=false;render();});selected.append(b);});}
function choose(r){if(!r.input.checked&&rows.filter(x=>x.input.checked).length>=250){status.textContent=t('Höchstens 250 Personen. Bitte zuerst eine Person entfernen.');return;}r.input.checked=true;render();search.value='';close();search.focus();}
function show(){found=rows.filter(r=>norm(r.label).includes(norm(search.value))).slice(0,100);list.replaceChildren();active=-1;search.removeAttribute('aria-activedescendant');found.forEach((r,i)=>{const b=document.createElement('button');b.type='button';b.id='mailing-person-'+i;b.setAttribute('role','option');b.setAttribute('aria-selected',String(r.input.checked));b.textContent=r.label+(r.input.checked?' · '+t('ausgewählt'):'');b.addEventListener('click',()=>choose(r));list.append(b);});if(!found.length)list.textContent=t('Keine passenden Personen gefunden.');list.hidden=false;search.setAttribute('aria-expanded','true');}
search.addEventListener('input',show);search.addEventListener('focus',show);
search.addEventListener('keydown',e=>{if(e.key==='Escape'){close();return;}if(e.key==='Enter'){e.preventDefault();if(active>=0)choose(found[active]);return;}if(['ArrowDown','ArrowUp'].includes(e.key)){e.preventDefault();if(list.hidden)show();if(!found.length)return;active=(active+(e.key==='ArrowDown'?1:-1)+found.length)%found.length;search.setAttribute('aria-activedescendant','mailing-person-'+active);[...list.children].forEach((b,i)=>b.classList.toggle('is-active',i===active));list.children[active].scrollIntoView({block:'nearest'});}});
root.addEventListener('focusout',()=>setTimeout(()=>{if(!root.contains(document.activeElement))close();},0));
root.querySelector('[data-recipient-apply]').addEventListener('click',()=>{if(group.value==='selected'){search.focus();return;}const next=rows.filter(r=>r.group===group.value);if(next.length>250){status.textContent=t('Diese Gruppe hat mehr als 250 Personen. Bitte einzelne Personen über die Suche auswählen. Die bisherige Auswahl bleibt erhalten.');return;}rows.forEach(r=>r.input.checked=next.includes(r));render();close();});
root.closest('form').addEventListener('reset',()=>setTimeout(()=>{render();close();},0));
root.querySelector('[data-recipient-fallback]').hidden=true;root.querySelector('.mailing-recipient-controls').hidden=false;render();
});})();
});
