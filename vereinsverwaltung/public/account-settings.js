window.KulturhusPage.register('account-settings', pageContext => {
 const {frame: requestAnimationFrame} = pageContext;
'use strict';
(()=>{
 const root=document.querySelector('[data-account-settings]');
 if(!root)return;
 const openTarget=()=>{
  let id;try{id=decodeURIComponent(location.hash.slice(1));}catch{return;}
  if(!id)return;
  const target=document.getElementById(id);
  if(!target||!root.contains(target))return;
  for(let node=target;node&&node!==root;node=node.parentElement)if(node.tagName==='DETAILS')node.open=true;
  requestAnimationFrame(()=>{target.scrollIntoView({block:'start'});});
 };
 pageContext.on(window, 'hashchange',openTarget);
 root.addEventListener('click',event=>{
  const link=event.target.closest('a[href]');if(!link)return;
  const destination=new URL(link.href,location.href);
  if(destination.pathname===location.pathname&&destination.search===location.search&&destination.hash===location.hash)openTarget();
 });
 openTarget();
})();
});
