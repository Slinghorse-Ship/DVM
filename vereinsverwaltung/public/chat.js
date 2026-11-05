window.KulturhusPage.register('chat', pageContext => {
 const {fetch, interval: setInterval, frame: requestAnimationFrame} = pageContext;
'use strict';
(()=>{
 const root=document.querySelector('[data-chat-workspace]'),notice=document.getElementById('chat-notification');if(!root||!notice)return;
 root.classList.add('chat-enhanced');
 const scroll=root.querySelector('[data-chat-scroll]'),latest=root.querySelector('[data-chat-latest]'),search=root.querySelector('[data-chat-room-search]'),create=root.querySelector('#chat-create');let busy=false;
 const messages=()=>document.getElementById('chat-messages'),nearBottom=()=>!scroll||scroll.scrollHeight-scroll.scrollTop-scroll.clientHeight<70;
 root.addEventListener('change',event=>{const vote=event.target.closest('[data-chat-poll-vote]');if(vote)vote.dataset.dirty='1';});
 const pollCreate=root.querySelector('[data-chat-poll-create]');
 if(pollCreate){
  const options=pollCreate.querySelector('[data-chat-poll-options]'),tools=pollCreate.querySelector('[data-chat-poll-option-tools]'),add=tools.querySelector('[data-chat-poll-option-add]'),remove=tools.querySelector('[data-chat-poll-option-remove]'),count=tools.querySelector('[data-chat-poll-option-count]');
  tools.hidden=false;
  const updateOptions=()=>{const total=options.querySelectorAll('input').length;add.disabled=total>=10;remove.disabled=total<=2;count.textContent=total+' von höchstens 10 Antworten';};
  add.addEventListener('click',()=>{const total=options.querySelectorAll('input').length;if(total>=10)return;const label=document.createElement('label'),input=document.createElement('input');label.append('Antwort '+(total+1));input.name='poll_options[]';input.maxLength=160;label.append(input);options.append(label);updateOptions();input.focus();});
  remove.addEventListener('click',()=>{const rows=options.querySelectorAll('label');if(rows.length<=2)return;rows[rows.length-1].remove();updateOptions();});updateOptions();
 }
 const updateLatest=()=>{if(latest)latest.hidden=nearBottom();};
 const bottom=()=>{if(scroll){scroll.scrollTop=scroll.scrollHeight;updateLatest();}};
 const filterRooms=()=>{const query=(search?.value||'').trim().toLocaleLowerCase('de');let visible=0;for(const room of root.querySelectorAll('[data-chat-room]')){const match=!query||(room.dataset.chatTitle||'').toLocaleLowerCase('de').includes(query);room.hidden=!match;if(match)visible++;}const empty=root.querySelector('[data-chat-room-empty]');if(empty)empty.hidden=visible>0;};
 search?.addEventListener('input',filterRooms);latest?.addEventListener('click',bottom);scroll?.addEventListener('scroll',updateLatest,{passive:true});
 const openSection=(id,focus=false)=>{const target=document.getElementById(id);if(!target||!root.contains(target))return;if(target.tagName==='DETAILS')target.open=true;target.scrollIntoView({block:'start',behavior:'auto'});if(focus)target.querySelector('input:not([type=hidden])')?.focus();};
 // A fragment survives a 303 redirect unless removed. It must never turn a sent
 // message or a created room back into a request to open another creation form.
 const clearCreateHash=()=>{if(location.hash==='#chat-create')window.KulturhusNavigation.updateUrl(location.pathname+location.search);};
 root.addEventListener('click',event=>{if(event.target.closest('[data-chat-create-close]')){create.open=false;clearCreateHash();document.querySelector('[data-chat-create-open]')?.focus();}if(event.target.closest('[data-chat-people-open]'))openSection('chat-people');});
 document.querySelectorAll('[data-chat-create-open]').forEach(link=>link.addEventListener('click',event=>{event.preventDefault();clearCreateHash();openSection('chat-create',true);}));
 root.addEventListener('submit',clearCreateHash);
 const hash=()=>{if(location.hash==='#chat-create'){if(!messages())openSection('chat-create');clearCreateHash();}if(location.hash==='#chat-people')openSection('chat-people');};pageContext.on(window, 'hashchange',hash);hash();
 const editingNotice=list=>{
  if(list.querySelector('details[open]'))return 'Du bearbeitest gerade etwas in der Unterhaltung. Neue Nachrichten werden danach wieder geladen.';
  if(list.querySelector('[data-chat-poll-vote][data-dirty="1"]')||(list.contains(document.activeElement)&&document.activeElement.closest('[data-chat-poll]')))return 'Deine Auswahl bleibt erhalten. Speichere zuerst deine Antwort; danach werden neue Nachrichten wieder geladen.';
  const selection=getSelection();if(selection&&!selection.isCollapsed&&list.contains(selection.anchorNode))return 'Solange Nachrichtentext markiert ist, bleibt die Ansicht unverändert.';
  return '';
 };
 const comparableMessages=list=>{const copy=list.cloneNode(true);copy.querySelectorAll('input[type="hidden"][name="request_key"]').forEach(input=>input.setAttribute('value',''));return copy.innerHTML;};
 const check=async()=>{
  if(pageContext.signal.aborted||document.hidden||busy)return;busy=true;
  try{
   const list=messages();
   if(list&&!list.dataset.history){
    const protectedBefore=editingNotice(list);if(protectedBefore){notice.textContent=protectedBefore;return;}
    const response=await fetch(location.href,{credentials:'same-origin',cache:'no-store'});if(!response.ok)throw Error('unavailable');
    const html=new DOMParser().parseFromString(await response.text(),'text/html'),fresh=html.getElementById('chat-messages');if(!fresh||fresh.dataset.chatId!==list.dataset.chatId)throw Error('session');
    // The user can start editing while the request is in flight.
    if(pageContext.signal.aborted)return;
    const protectedAfter=editingNotice(list);if(protectedAfter){notice.textContent=protectedAfter;return;}
    if(document.hidden||list!==messages())return;
    if(comparableMessages(fresh)!==comparableMessages(list)){
     const wasBottom=nearBottom(),top=scroll?.getBoundingClientRect().top||0,anchor=Array.from(list.querySelectorAll('[data-chat-message]')).find(row=>row.getBoundingClientRect().bottom>top),anchorId=anchor?.dataset.chatMessage,offset=anchor?.getBoundingClientRect().top;
     list.replaceChildren(...Array.from(fresh.childNodes));
     if(wasBottom)bottom();else if(anchorId){const newAnchor=list.querySelector('[data-chat-message="'+anchorId+'"]');if(newAnchor&&scroll)scroll.scrollTop+=newAnchor.getBoundingClientRect().top-offset;}
     updateLatest();notice.textContent=wasBottom?'Nachrichten sind aktuell.':'Neue oder geänderte Nachrichten geladen. Deine Leseposition bleibt erhalten.';
    }else notice.textContent='Nachrichten sind aktuell.';
    const rooms=root.querySelector('[data-chat-room-list]'),newRooms=html.querySelector('[data-chat-room-list]');if(rooms&&newRooms&&rooms.innerHTML!==newRooms.innerHTML){rooms.replaceChildren(...Array.from(newRooms.childNodes));filterRooms();}const total=root.querySelector('[data-chat-room-total]'),newTotal=html.querySelector('[data-chat-room-total]');if(total&&newTotal)total.textContent=newTotal.textContent;
   }else{
    const response=await fetch('?view=chat&chat_updates=1',{credentials:'same-origin',cache:'no-store'});if(!response.ok)throw Error('unavailable');const data=await response.json();if(pageContext.signal.aborted)return;notice.textContent=data.unread>0?`${data.unread} ungelesene Nachricht(en). Unterhaltung öffnen, um sie zu lesen.`:list?.dataset.history?'Du siehst ältere Nachrichten.':'';
   }
  }catch{if(pageContext.signal.aborted)return;notice.textContent='Nachrichten konnten gerade nicht geladen werden. Deine Eingaben bleiben erhalten.';}
  finally{busy=false;}
 };
 root.querySelector('[data-chat-refresh]')?.addEventListener('click',event=>{if(messages()&&!messages().dataset.history){event.preventDefault();check();}});
 if(messages()&&!messages().dataset.history&&!messages().querySelector('details[open]'))requestAnimationFrame(bottom);
 setInterval(check,15000);pageContext.on(document, 'visibilitychange',()=>{if(!document.hidden)check();});
})();
});
