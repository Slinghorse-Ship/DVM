window.KulturhusPage.register('simple-settings', pageContext => {
 const {frame: requestAnimationFrame} = pageContext;
'use strict';
(()=>{
 const root=document.querySelector('[data-simple-settings]');if(!root)return;
 function revealHash(){let id;try{id=decodeURIComponent(location.hash.slice(1));}catch{return;}
  const target=id&&document.getElementById(id);if(!target||!root.contains(target))return;
  for(let node=target;node&&node!==root;node=node.parentElement)if(node.tagName==='DETAILS')node.open=true;
  requestAnimationFrame(()=>target.scrollIntoView({block:'start'}));
 }
 pageContext.on(window, 'hashchange',revealHash);revealHash();
 root.addEventListener('click',event=>{const link=event.target.closest('a[href]');if(!link)return;const url=new URL(link.href,location.href);if(url.pathname===location.pathname&&url.search===location.search&&url.hash===location.hash)revealHash();});
})();
});
