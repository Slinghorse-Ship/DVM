window.KulturhusPage.register('calendar-checks', pageContext => {
(() => {
 'use strict';
 const eventForm=document.querySelector('#event-form');
 if(eventForm){
  const start=eventForm.elements.start,end=eventForm.elements.end;
  const dates=()=>{end.setCustomValidity(start.value&&end.value&&end.value<=start.value?(window.KulturhusInterface?.text('Das Ende muss nach dem Beginn liegen.')??'Das Ende muss nach dem Beginn liegen.'):'');};
  start.addEventListener('input',dates);end.addEventListener('input',dates);dates();
 }
 for (const section of document.querySelectorAll('[data-check-list]')) {
  const form=section.querySelector('[data-check-filter]'),search=form.elements.q,attention=form.elements.attention;
  const rows=[...section.querySelectorAll('[data-check-row]')],count=section.querySelector('[data-check-count]'),empty=section.querySelector('[data-check-empty]');
  const normal=value=>value.toLocaleLowerCase('de').normalize('NFD').replace(/[\u0300-\u036f]/g,'');
  const update=()=>{
   const needle=normal(search.value.trim());let visible=0;
   for(const row of rows){row.hidden=(attention.value==='due'&&row.dataset.checkAlert!=='1')||!normal(row.dataset.checkSearch).includes(needle);if(!row.hidden)visible++;}
   count.textContent=(window.KulturhusInterface?.format?.('{visible} von {total} Nachweisen angezeigt',{visible,total:rows.length})??(visible+' von '+rows.length+' Nachweisen angezeigt'));empty.hidden=visible!==0;
   const url=new URL(location.href);for(const key of ['q','attention']){const value=form.elements[key].value.trim();if(value)url.searchParams.set(key,value);else url.searchParams.delete(key);}window.KulturhusNavigation.updateUrl(url);
  };
  form.addEventListener('submit',event=>{event.preventDefault();update();});search.addEventListener('input',update);attention.addEventListener('change',update);
  form.querySelector('[data-check-reset]').addEventListener('click',event=>{event.preventDefault();search.value='';attention.value='';update();search.focus();});
  form.querySelector('[data-check-submit]').hidden=true;update();
 }
})();
});
