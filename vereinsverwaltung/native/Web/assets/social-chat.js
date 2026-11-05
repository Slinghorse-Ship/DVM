(() => {
  'use strict';
  function mount(root, on, cleanup) {
    root.querySelectorAll('[data-social-chat]').forEach(chat => {
      let labels={};try{labels=JSON.parse(chat.dataset.conversationInterface||'{}');}catch{}
      const text=source=>typeof labels[source]==='string'?labels[source]:source;
      const search=chat.querySelector('[data-chat-search]');
      if(search)on(search,'input',()=>{
        const term=search.value.trim().toLocaleLowerCase(document.documentElement.lang||'de');
        chat.querySelectorAll('.chat-choice').forEach(item=>{item.hidden=!item.textContent.toLocaleLowerCase(document.documentElement.lang||'de').includes(term);});
      });
      chat.querySelectorAll('[data-reply-byte-limit]').forEach(input=>{
        const validate=()=>input.setCustomValidity(new TextEncoder().encode(input.value).length>Number(input.dataset.replyByteLimit)?text('Instagram-Antwort ist zu lang. Bitte kürzen.'):'');
        validate();on(input,'input',validate);
      });
      const hit=chat.querySelector('[data-chat-hit-focus]'),messages=chat.querySelector('[data-chat-latest]');
      if(!hit&&messages)messages.scrollTop=messages.scrollHeight;
      const viewport=window.visualViewport;
      const resize=()=>{
        if(!chat.isConnected)return;
        if(innerWidth>760){chat.style.removeProperty('--chat-height');delete chat.dataset.chatKeyboard;return;}
        const height=viewport?.height||innerHeight, top=chat.getBoundingClientRect().top;
        const scale=chat.offsetWidth?chat.getBoundingClientRect().width/chat.offsetWidth:1;
        // Reserve the same mobile header space as the plain CSS view. A panel
        // that consumes only the initial remainder can leave no room to scroll
        // past the heading, so its own height would never grow.
        chat.style.setProperty('--chat-height',Math.max(260,(height-Math.min(120,Math.max(0,top))+(viewport?.offsetTop||0))/scale)+'px');
        chat.toggleAttribute('data-chat-keyboard',height<innerHeight*.75&&document.activeElement?.matches('textarea'));
      };
      let frame=0;
      const schedule=()=>{if(!frame)frame=requestAnimationFrame(()=>{frame=0;resize();});};
      resize();on(window,'resize',schedule);on(window,'scroll',schedule,{capture:true,passive:true});
      if(viewport){on(viewport,'resize',schedule);on(viewport,'scroll',schedule);}
      cleanup(()=>cancelAnimationFrame(frame));
      if(hit)queueMicrotask(()=>{if(hit.isConnected){hit.focus({preventScroll:true});hit.scrollIntoView({block:'center',inline:'nearest'});}});
      // A touch button must stay in place while textarea focus leaves on pointer-down.
      // Actual keyboard/viewport changes already have their own resize events.
      if(window.ResizeObserver){const observer=new ResizeObserver(resize);observer.observe(chat);cleanup(()=>observer.disconnect());}
    });
  }
  if(window.KulturhusPage){
    window.KulturhusPage.register('social-chat',page=>mount(page.root,(node,type,fn,options)=>page.on(node,type,fn,options),page.cleanup));
    return;
  }
  function start(){mount(document,(node,type,fn,options)=>node.addEventListener(type,fn,options),fn=>window.addEventListener('pagehide',fn,{once:true}));}
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});else start();
})();
