window.KulturhusPage.register('inventory-form', pageContext => {
 const {frame: requestAnimationFrame} = pageContext;
(()=>{'use strict';
 const root=document.querySelector('[data-inventory-editor]');if(!root)return;
 function reveal(target){if(!target||!root.contains(target))return;for(let current=target;current&&current!==root;current=current.parentElement)if(current.tagName==='DETAILS')current.open=true;requestAnimationFrame(()=>target.scrollIntoView({block:'start',behavior:'auto'}));}
 function fromHash(){if(!location.hash)return;let id;try{id=decodeURIComponent(location.hash.slice(1));}catch{return;}reveal(document.getElementById(id));}
 root.addEventListener('click',event=>{const link=event.target.closest('a[href^="#"]');if(!link)return;let id;try{id=decodeURIComponent(link.getAttribute('href').slice(1));}catch{return;}const target=document.getElementById(id);if(target&&root.contains(target))reveal(target);});
 pageContext.on(window, 'hashchange',fromHash);fromHash();
 const content=root.querySelector('[data-inventory-content]');if(content){const quantity=content.querySelector('[name="data[content_quantity]"]'),unit=content.querySelector('[name="data[content_unit]"]'),summary=content.querySelector('[data-inventory-content-summary]');if(quantity&&unit&&summary){const update=()=>{summary.textContent=quantity.value!==''&&quantity.validity.valid?(window.KulturhusInterface?.format('{quantity} {unit} je Einheit',{quantity:quantity.value,unit:unit.value})??quantity.value+' '+unit.value+' je Einheit'):(window.KulturhusInterface?.text('Inhalt bitte ergänzen')??'Inhalt bitte ergänzen');};quantity.addEventListener('input',update);unit.addEventListener('change',update);update();}}
 const searchable=scope=>{for(const select of scope.querySelectorAll('select[name="data[source]"],select[name="common[source]"],select[name^="units["][name$="[source]"]'))select.setAttribute('data-iv-searchable','');window.KulturhusSelectSearch?.enhance(scope);};searchable(root);root.addEventListener('inventory-options-added',event=>searchable(event.target));
})();
});
