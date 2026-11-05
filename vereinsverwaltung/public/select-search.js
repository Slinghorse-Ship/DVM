'use strict';
(() => {
 if(window.KulturhusSelectSearch){window.KulturhusSelectSearch.enhance();return;}
 const uiText=source=>window.KulturhusInterface?.text(source)??source;
 const uiFormat=(source,values)=>window.KulturhusInterface?.format?.(source,values)??uiText(source).replace(/\{([a-z_]+)\}/g,(token,key)=>Object.hasOwn(values,key)?String(values[key]):token);
 const managed=new WeakMap(),entries=new Set();
 const lastName=select=>(select.name.match(/(?:^|\[)([^\[\]]+)\]?$/)||[])[1]||select.name;
 const labelFor=select=>select.getAttribute('aria-label')||Array.from(select.labels||[]).map(label=>Array.from(label.childNodes).filter(n=>n.nodeType===Node.TEXT_NODE).map(n=>n.textContent).join(' ').trim()).find(Boolean)||uiText('Auswahl');
 const eligible=select=>{
  if(select.multiple||select.closest('[data-tax-dialog],[data-pdf-viewer]')||select.matches('[data-no-select-search],[data-pdf-zoom],[data-tax-search]'))return false;
  if(select.hasAttribute('data-select-native'))return false;
  if(select.hasAttribute('data-iv-searchable')||select.hasAttribute('data-select-search'))return true;
  const name=lastName(select),label=labelFor(select);
  if(/kategorie/i.test(label)||name==='category')return true;
  if(/rechnung|beleg/i.test(label)||/^(expense|entry|invoice|source)$/.test(name))return true;
  if(/^(state|status|kind|type|mode|sort|period|year|month|size|page_size|limit|tax_rate|language|locale|currency|role|view|tab|zoom|scale|rotation|active)$/.test(name))return false;
  return select.options.length>=10;
 };

 let sequence=0,opened=null;
 // Prevent only the compatibility mousedown: cancelling pointerdown suppresses taps in Safari.
 function attach(select){
  const existing=managed.get(select);if(existing){existing.resume();existing.sync();return;}if(!eligible(select))return;
  const label=labelFor(select);if(!select.hasAttribute('aria-label'))select.setAttribute('aria-label',label);let input=select.previousElementSibling;
  if(!input?.matches('[data-select-search-input]')){input=document.createElement('input');select.before(input);}
  input.type='text';input.className='form-control select-search iv-select-search';input.dataset.selectSearchInput='';input.removeAttribute('name');input.autocomplete='off';input.setAttribute('role','combobox');input.setAttribute('aria-label',label);input.setAttribute('aria-autocomplete','list');input.setAttribute('aria-expanded','false');
  const popup=document.createElement('div');popup.className='select-search-popup';popup.id='select-search-options-'+(++sequence);popup.setAttribute('role','listbox');popup.setAttribute('aria-label',label);popup.hidden=true;(select.closest('dialog')||document.body).append(popup);input.setAttribute('aria-controls',popup.id);
  select.classList.add('select-search-native');select.tabIndex=-1;select.setAttribute('aria-hidden','true');
  let query='',isOpen=false,visible=[],active=-1,renderSignature='';
  const current=()=>select.selectedOptions[0]?.textContent.trim()||'';
  const available=()=>Array.from(select.options).filter(o=>!o.disabled&&!o.parentElement?.disabled&&!o.hidden);
  function position(){const r=input.getBoundingClientRect();popup.style.left=Math.max(8,Math.min(r.left,innerWidth-180))+'px';popup.style.width=Math.min(Math.max(r.width,180),innerWidth-16)+'px';const below=innerHeight-r.bottom-8,above=r.top-8;const height=Math.min(280,Math.max(below,above));popup.style.maxHeight=Math.max(90,height)+'px';if(below>=Math.min(200,above)){popup.style.top=r.bottom+3+'px';popup.style.bottom='auto';}else{popup.style.bottom=innerHeight-r.top+3+'px';popup.style.top='auto';}}
  function mark(){for(let i=0;i<popup.children.length;i++){const node=popup.children[i];if(node.getAttribute('role')!=='option')continue;node.classList.toggle('is-active',i===active);node.setAttribute('aria-selected',visible[i]?.selected?'true':'false');}if(active>=0&&popup.children[active]){input.setAttribute('aria-activedescendant',popup.children[active].id);popup.children[active].scrollIntoView({block:'nearest'});}else input.removeAttribute('aria-activedescendant');}
  // Keep option nodes stable across unrelated DOM updates while a pointer is down.
  function render(){if(!isOpen)return;const needle=query.trim().toLocaleLowerCase('de');const matches=available().filter(o=>!needle||o.textContent.toLocaleLowerCase('de').includes(needle));visible=matches.slice(0,100);const signature=JSON.stringify([needle,matches.length,visible.map(o=>[o.value,o.textContent,o.selected])]);if(signature===renderSignature){position();return;}renderSignature=signature;popup.replaceChildren();visible.forEach((o,i)=>{const row=document.createElement('div');row.id=popup.id+'-'+i;row.setAttribute('role','option');row.textContent=o.textContent;row.addEventListener('mousedown',e=>e.preventDefault());row.addEventListener('click',()=>choose(i));popup.append(row);});if(!visible.length){const empty=document.createElement('p');empty.textContent=uiText('Keine passenden Einträge. Suchtext ändern.');empty.setAttribute('role','status');popup.append(empty);}if(matches.length>visible.length){const hint=document.createElement('p');hint.setAttribute('role','status');hint.textContent=uiFormat('Die ersten {shown} von {total} Einträgen. Zum Eingrenzen einen Namen oder Begriff eingeben.',{shown:visible.length,total:matches.length});popup.append(hint);}active=-1;mark();position();}
  function close(){isOpen=false;renderSignature='';popup.hidden=true;input.setAttribute('aria-expanded','false');input.removeAttribute('aria-activedescendant');input.value=current();query='';if(opened===close)opened=null;}
  function open(){if(input.disabled)return;if(opened&&opened!==close)opened();opened=close;isOpen=true;popup.hidden=false;input.setAttribute('aria-expanded','true');query='';render();input.select();}
  function choose(index){const option=visible[index];if(!option||option.disabled)return;select.value=option.value;input.setCustomValidity('');select.dispatchEvent(new Event('input',{bubbles:true}));select.dispatchEvent(new Event('change',{bubbles:true}));close();input.focus();}
  function sync(){const disabled=select.matches(':disabled');if(input.disabled!==disabled)input.disabled=disabled;input.setAttribute('aria-required',select.required?'true':'false');if(disabled&&isOpen)close();if(!isOpen)input.value=current();else render();}
  input.addEventListener('focus',open);input.addEventListener('click',()=>{if(!isOpen)open();});input.addEventListener('input',()=>{input.setCustomValidity('');if(!isOpen){isOpen=true;opened=close;popup.hidden=false;input.setAttribute('aria-expanded','true');}query=input.value;render();});
  input.addEventListener('keydown',e=>{if(e.key==='Escape'){e.preventDefault();close();}else if(e.key==='ArrowDown'||e.key==='ArrowUp'){e.preventDefault();if(!isOpen)open();active=Math.max(0,Math.min(visible.length-1,active+(e.key==='ArrowDown'?1:-1)));mark();}else if(e.key==='Enter'){e.preventDefault();if(!isOpen)open();else if(active>=0)choose(active);else if(visible.length===1)choose(0);}else if(e.key==='Tab')close();});
  input.addEventListener('blur',close);select.addEventListener('focus',()=>input.focus());select.addEventListener('change',()=>{input.setCustomValidity('');close();sync();});
  select.addEventListener('invalid',event=>{event.preventDefault();if(window.KulturhusFormValidation){window.KulturhusFormValidation.show(select);return;}input.focus();input.setCustomValidity(select.validationMessage||uiText('Bitte einen Eintrag auswählen.'));input.reportValidity();});
  select.form?.addEventListener('reset',()=>setTimeout(()=>{input.setCustomValidity('');close();sync();},0));
  const reposition=()=>{if(isOpen)position();};addEventListener('resize',reposition);addEventListener('scroll',reposition,true);
  const entry={select,input,sync,resume(){if(!entries.has(entry)){(select.closest('dialog')||document.body).append(popup);addEventListener('resize',reposition);addEventListener('scroll',reposition,true);entries.add(entry);}},remove(){close();popup.remove();removeEventListener('resize',reposition);removeEventListener('scroll',reposition,true);}};entries.add(entry);managed.set(select,entry);sync();
 }
 function enhance(root=document){for(const entry of entries)if(!entry.select.isConnected){entry.remove();entries.delete(entry);}if(root.matches?.('select'))attach(root);root.querySelectorAll?.('select').forEach(attach);}
 window.KulturhusSelectSearch={enhance};enhance();
 window.KulturhusPage.register('select-search',context=>{enhance(context.root);context.cleanup(()=>{for(const entry of entries){entry.remove();entries.delete(entry);}});});
 let queued=false;new MutationObserver(records=>{if(records.every(r=>r.target.closest?.('.select-search-popup')))return;if(queued)return;queued=true;requestAnimationFrame(()=>{queued=false;enhance();});}).observe(document.body,{childList:true,subtree:true,attributes:true,attributeFilter:['disabled','required']});
})();
