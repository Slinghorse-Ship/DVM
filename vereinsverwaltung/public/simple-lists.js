window.KulturhusPage.register('simple-lists', pageContext => {
'use strict';
const uiText=source=>window.KulturhusInterface?.text(source)??source;
const uiFormat=(source,values)=>window.KulturhusInterface?.format?.(source,values)??uiText(source).replace(/\{([a-z_]+)\}/g,(token,key)=>Object.hasOwn(values,key)?String(values[key]):token);
// Opt-in only: rows are already authorized and rendered by their own service.
(()=>{
 let sequence=0;
 for(const root of document.querySelectorAll('[data-simple-list]')){
  const selector=root.dataset.simpleListRows||'[data-simple-row]';
  const rows=Array.from(root.querySelectorAll(selector));if(!rows.length)continue;
  const text=row=>(row.dataset.searchText||row.textContent).normalize('NFKD').replace(/\p{M}/gu,'').toLocaleLowerCase('de');
  const searchTexts=rows.map(text),size=Math.max(5,Math.min(100,Number(root.dataset.simpleListSize)||20));let current=1;
  const tools=document.createElement('div');tools.className='simple-list-tools';
  const label=document.createElement('label'),input=document.createElement('input');input.type='search';input.className='form-control';input.id='simple-list-search-'+(++sequence);input.autocomplete='off';
  label.htmlFor=input.id;label.append(document.createTextNode(root.dataset.simpleList||uiText('Liste durchsuchen')),input);tools.append(label);
  const status=document.createElement('p');status.className='simple-list-status';status.setAttribute('role','status');status.setAttribute('aria-live','polite');tools.append(status);
  const nav=document.createElement('nav');nav.className='simple-list-pages';nav.setAttribute('aria-label',uiFormat('{label} – Seiten',{label:root.dataset.simpleList||uiText('Liste')}));
  const previous=document.createElement('button'),next=document.createElement('button'),page=document.createElement('span');
  previous.type=next.type='button';previous.className=next.className='btn btn-outline theme-secondary secondary';previous.textContent=uiText('Zurück');next.textContent=uiText('Weiter');nav.append(previous,page,next);
  root.before(tools);root.after(nav);
  function render(reset){if(reset)current=1;const terms=input.value.normalize('NFKD').replace(/\p{M}/gu,'').toLocaleLowerCase('de').trim().split(/\s+/).filter(Boolean);
   const matching=rows.map((row,i)=>({row,i})).filter(({i})=>terms.every(term=>searchTexts[i].includes(term)));
   const pages=Math.max(1,Math.ceil(matching.length/size));current=Math.min(current,pages);const visible=new Set(matching.slice((current-1)*size,current*size).map(({row})=>row));
   for(const row of rows)row.hidden=!visible.has(row);
   status.textContent=matching.length===0?uiText('Keine passenden Einträge. Suche ändern oder leeren.'):matching.length+' '+uiText(matching.length===1?'Eintrag':'Einträge')+(terms.length?uiText(' gefunden'):'')+(pages>1?' · '+uiFormat('{first}–{last} angezeigt',{first:(current-1)*size+1,last:Math.min(current*size,matching.length)}):'');
   page.textContent=uiFormat('Seite {page} von {pages}',{page:current,pages});previous.disabled=current===1;next.disabled=current===pages;nav.hidden=pages===1;
  }
  input.addEventListener('input',()=>render(true));
  previous.addEventListener('click',()=>{current--;render(false);tools.scrollIntoView({block:'nearest'});});
  next.addEventListener('click',()=>{current++;render(false);tools.scrollIntoView({block:'nearest'});});render(false);
 }
})();
});
