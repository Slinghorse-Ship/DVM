window.KulturhusPage.register('handbook', pageContext => {
'use strict';
(() => {
 const search=document.querySelector('#handbook-search');if(!search)return;
 const status=document.querySelector('[data-handbook-results]');
 const normalize=value=>value.toLocaleLowerCase('de').normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/ß/g,'ss');
 const groups=Array.from(document.querySelectorAll('[data-handbook-chapter]'));
 const entries=Array.from(document.querySelectorAll('[data-handbook-result]')).map(row=>{
  const link=row.querySelector('a'),section=document.getElementById(link.hash.slice(1));
  return {row,text:normalize(section?.textContent||link.textContent)};
 });
 search.addEventListener('input',()=>{
  const words=normalize(search.value.trim()).split(/\s+/).filter(Boolean);let count=0;
  for(const entry of entries){entry.row.hidden=!words.every(word=>entry.text.includes(word));if(!entry.row.hidden)count++;}
  for(const group of groups){group.hidden=!!words.length&&!group.querySelector('[data-handbook-result]:not([hidden])');group.open=!!words.length&&!group.hidden;}
  status.textContent=words.length?(count?`${count} passende Anleitungen. Eine Aufgabe zum Öffnen auswählen.`:'Keine passende Anleitung. Einen kürzeren Begriff oder ein anderes Wort versuchen.'):'Kapitel aufklappen und eine Aufgabe auswählen. Strg+F durchsucht zusätzlich alle Anleitungstexte.';
 });
 document.querySelector('[data-handbook-print]')?.addEventListener('click',()=>window.print());
 const revealHash=()=>{
  let id;try{id=decodeURIComponent(location.hash.slice(1));}catch{return;}const target=document.getElementById(id);if(!target)return;
  let item=target;while(item){if(item.tagName==='DETAILS')item.open=true;item=item.parentElement;}
  target.scrollIntoView({block:'start'});
 };
 pageContext.on(window, 'hashchange',revealHash);if(location.hash)revealHash();
 document.querySelectorAll('[data-video-seek]').forEach(button=>button.addEventListener('click',()=>{
  const video=button.closest('.handbook-video-card').querySelector('video');
  video.currentTime=Number(button.dataset.videoSeek);video.play().catch(()=>{});
 }));
 document.querySelectorAll('.handbook video').forEach(video=>video.addEventListener('play',()=>{
  document.querySelectorAll('.handbook video').forEach(other=>{if(other!==video)other.pause();});
 }));
 document.querySelectorAll('.handbook-video-card').forEach(card=>card.addEventListener('toggle',()=>{if(!card.open)card.querySelector('video').pause();}));
 document.querySelector('#hilfe-videos')?.addEventListener('toggle',event=>{if(!event.currentTarget.open)document.querySelectorAll('.handbook video').forEach(video=>video.pause());});
})();
});
