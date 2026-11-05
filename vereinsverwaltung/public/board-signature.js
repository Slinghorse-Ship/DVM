window.KulturhusPage.register('board-signature', pageContext => {
'use strict';
(() => {
 const form=document.querySelector('[data-board-signature]');if(!form)return;
 let messages={};try{messages=JSON.parse(form.dataset.accountMessages||'{}');}catch{}
 const uiText=source=>Object.hasOwn(messages,source)&&typeof messages[source]==='string'?messages[source]:source;
 const file=form.querySelector('[name="signature"]'),draw=form.querySelector('[data-signature-draw]'),canvas=draw.querySelector('canvas'),ctx=canvas.getContext('2d'),preview=form.querySelector('[data-signature-preview]'),status=form.querySelector('[data-signature-status]');
 let previous=null,ink=false,minX=900,minY=280,maxX=0,maxY=0,url='';
 pageContext.cleanup(()=>{if(url)URL.revokeObjectURL(url);});
 const reset=()=>{ctx.clearRect(0,0,canvas.width,canvas.height);ctx.strokeStyle='#172335';ctx.fillStyle='#172335';ctx.lineWidth=4;ctx.lineCap='round';ctx.lineJoin='round';ink=false;previous=null;minX=900;minY=280;maxX=maxY=0;};reset();draw.hidden=false;
 const point=event=>{const box=canvas.getBoundingClientRect();return [Math.max(0,Math.min(canvas.width,(event.clientX-box.left)*canvas.width/box.width)),Math.max(0,Math.min(canvas.height,(event.clientY-box.top)*canvas.height/box.height))];};
 const bounds=([x,y])=>{minX=Math.min(minX,x);minY=Math.min(minY,y);maxX=Math.max(maxX,x);maxY=Math.max(maxY,y);ink=true;};
 canvas.addEventListener('pointerdown',event=>{if(event.button!==0&&event.pointerType==='mouse')return;event.preventDefault();canvas.setPointerCapture(event.pointerId);previous=point(event);bounds(previous);ctx.beginPath();ctx.arc(previous[0],previous[1],2,0,Math.PI*2);ctx.fill();});
 canvas.addEventListener('pointermove',event=>{if(!previous)return;const next=point(event);ctx.beginPath();ctx.moveTo(...previous);ctx.lineTo(...next);ctx.stroke();bounds(next);previous=next;});
 for(const type of ['pointerup','pointercancel','lostpointercapture'])canvas.addEventListener(type,()=>{previous=null;});
 form.querySelector('[data-signature-clear]').addEventListener('click',()=>{reset();status.textContent=uiText('Zeichenfläche geleert. Eine schon übernommene Vorschau bleibt bis zu einer neuen Übernahme erhalten.');});
 file.addEventListener('change',()=>{const image=file.files[0];if(!image)return;if(url)URL.revokeObjectURL(url);url=URL.createObjectURL(image);preview.querySelector('img').src=url;preview.hidden=false;form.querySelector('[name="reviewed"]').checked=false;},true);
 form.querySelector('[data-signature-use]').addEventListener('click',()=>{
  if(!ink){status.textContent=uiText('Bitte zuerst in das weiße Feld zeichnen.');return;}
  const padding=12,x=Math.max(0,Math.floor(minX)-padding),y=Math.max(0,Math.floor(minY)-padding),width=Math.min(canvas.width-x,Math.ceil(maxX)-x+padding),height=Math.min(canvas.height-y,Math.ceil(maxY)-y+padding);
  const cropped=document.createElement('canvas');cropped.width=Math.max(24,width);cropped.height=Math.max(24,height);cropped.getContext('2d').drawImage(canvas,x,y,width,height,0,0,width,height);
  cropped.toBlob(blob=>{if(pageContext.signal.aborted)return;if(!blob){status.textContent=uiText('Zeichnung konnte nicht übernommen werden. Bitte als Bild hochladen.');return;}const transfer=new DataTransfer();transfer.items.add(new File([blob],'unterschrift.png',{type:'image/png'}));file.files=transfer.files;file.dispatchEvent(new Event('change',{bubbles:true}));status.textContent=uiText('Zeichnung übernommen. Upload abwarten, die Vorschau prüfen und anschließend Unterschrift speichern.');},'image/png');
 });
})();
});
