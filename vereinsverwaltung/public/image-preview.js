'use strict';
window.KulturhusPage.register('image-preview', context => {
 import('./preview-pan.js?v=3').then(({enablePreviewPan})=>{
  if(context.signal.aborted)return;
  for(const viewer of context.root.querySelectorAll('[data-image-preview]')){
   const image=viewer.querySelector('img'),wrap=viewer.querySelector('.image-preview-scroll'),label=viewer.querySelector('[data-image-scale]');
   let scale=1,fit=true;
   const updatePan=enablePreviewPan(wrap,image,{min:.05,max:3,getScale:()=>scale,setScale:next=>{fit=false;scale=next;update();}});
   context.cleanup(()=>updatePan.dispose?.());
   function update(){
    if(context.signal.aborted||!image.naturalWidth)return;
    if(fit)scale=Math.min(1,wrap.clientWidth/image.naturalWidth);
    image.style.width=Math.max(1,Math.round(image.naturalWidth*scale))+'px';label.textContent=Math.round(scale*100)+' %';updatePan();
   }
   for(const [key,direction]of[['smaller',-1],['larger',1]])context.on(viewer.querySelector('[data-image-'+key+']'),'click',()=>{fit=false;scale=Math.max(.05,Math.min(3,scale+direction*.25));update();});
   context.on(viewer.querySelector('[data-image-fit]'),'click',()=>{fit=true;update();});
   context.on(viewer.querySelector('[data-image-original]'),'click',()=>{fit=false;scale=1;update();});
   context.on(image,'load',update);
   context.on(image,'error',()=>{viewer.querySelector('[data-image-status]').textContent='Bildvorschau nicht verfügbar. Bitte das Original herunterladen.';});
   const observer=new ResizeObserver(()=>{if(fit)update();});observer.observe(wrap);context.cleanup(()=>observer.disconnect());update();
  }
 }).catch(()=>{
  if(!context.signal.aborted)for(const status of context.root.querySelectorAll('[data-image-status]'))status.textContent='Bildvorschau nicht verfügbar. Bitte das Original herunterladen.';
 });
});
