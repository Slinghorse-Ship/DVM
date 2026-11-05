/** Capture real local UI, adding temporary DOM callouts before taking a crop.
 * No raster editing, no fabricated controls. Input forms may contain clearly
 * labelled unsaved examples. Callers must never submit mail/publication forms.
 */
const fs=require('node:fs'),path=require('node:path');
const root=path.resolve(__dirname,'../..');
async function capture(page,id,title,items,options={}){
 if(!['http://127.0.0.1:8092','http://127.0.0.1:8091'].includes(new URL(page.url()).origin))throw Error('Handbook capture requires isolated local application');
 // Website copy contains real public events. Replace this presentation-only
 // example palette before any capture; no database or public content changes.
 await page.evaluate(()=>{
  document.querySelectorAll('[aria-label="Programmzeitraum"] a').forEach((link,i)=>{link.textContent=link.textContent.replace(/\(\d+\)/,'('+(i===0?2:4)+')');});
  document.querySelectorAll('[data-nl-event]').forEach((event,i)=>{
   const title=['Beispielkonzert – erfundener Termin','Lesung – erfundener Termin','Kulturabend – erfundener Termin'][i%3];
   Object.assign(event.dataset,{title,date:'15.10.2026 · 19:00 Uhr',venue:'Beispielsaal',summary:'Erfundener Veranstaltungstext für die Anleitung.',url:'https://example.org/programm'});
   event.querySelector('strong').textContent=title;event.querySelector('small').textContent='15.10.2026 · 19:00 Uhr';
   const img=event.querySelector('img');if(img){img.src='data:image/svg+xml,'+encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="400" height="160"><rect width="400" height="160" fill="#e8f0f6"/><text x="200" y="92" text-anchor="middle" font-family="Arial" font-size="28" fill="#174f80">Beispielmotiv</text></svg>');img.alt='Erfundenes Beispielmotiv';img.dataset.handbookSafeImage='1';event.dataset.image=img.src;}
  });
 });
 await page.evaluate(()=>document.querySelectorAll('[data-handbook-callout]').forEach(x=>x.remove()));
 const boxes=[];
 for(const [locator,note] of items){
  const target=locator.first();await target.waitFor({state:'visible'});
  if(!await target.count())throw Error('Missing screenshot target '+id);
  boxes.push(await target.evaluate(e=>{const r=e.getBoundingClientRect();return{x:r.x+scrollX,y:r.y+scrollY,width:r.width,height:r.height};}));
 }
 const padding=45;
 const horizontalPadding=options.horizontalPadding===0?0:padding;
 let x=Math.max(0,Math.min(...boxes.map(r=>r.x))-horizontalPadding),y=Math.max(0,Math.min(...boxes.map(r=>r.y))-padding);
 let right=Math.max(...boxes.map(r=>r.x+r.width))+horizontalPadding,bottom=Math.max(...boxes.map(r=>r.y+r.height))+padding;
 const dimensions=await page.evaluate(()=>({width:document.documentElement.scrollWidth,height:document.documentElement.scrollHeight}));
 right=Math.min(right,dimensions.width);bottom=Math.min(bottom,dimensions.height);
 if(right-x>1050||bottom-y>950)throw Error(`Crop too large ${id}: ${right-x}x${bottom-y}. Use focused regions.`);
 const audit=await page.evaluate(clip=>{
  const intersects=e=>{const r=e.getBoundingClientRect();return r.width&&r.height&&r.x+scrollX<clip.right&&r.right+scrollX>clip.x&&r.y+scrollY<clip.bottom&&r.bottom+scrollY>clip.y;};
  const texts=[];const walk=document.createTreeWalker(document.body,NodeFilter.SHOW_TEXT);
  while(walk.nextNode()){const n=walk.currentNode;if(n.textContent.trim()&&n.parentElement&&n.parentElement.checkVisibility({checkOpacity:true,checkVisibilityCSS:true})&&!n.parentElement.closest('script,style,option,template')){const range=document.createRange();range.selectNodeContents(n);if(intersects(range))texts.push(n.textContent.trim());}}
  for(const input of document.querySelectorAll('input:not([type=hidden]):not([type=checkbox]):not([type=radio]),textarea,select'))if(input.checkVisibility({checkOpacity:true,checkVisibilityCSS:true})&&intersects(input))texts.push(input.tagName==='SELECT'?input.selectedOptions[0]?.textContent||'':input.value);
  const images=[...document.images].filter(intersects).map(img=>({safe:!!img.dataset.handbookSafeImage,alt:img.alt}));
  return{text:texts.join('\n'),images};
 },{x,y,right,bottom});
 const auditDir=path.join(root,'.local/handbook-capture-audit');fs.mkdirSync(auditDir,{recursive:true});fs.writeFileSync(path.join(auditDir,id+'.json'),JSON.stringify(audit,null,2));
 if(audit.images.some(img=>!img.safe))throw Error('Unreviewed image intersects capture '+id);
 if(/(?:wehlage|kraudelt|plavenieks|brinker|red house|gabriele bagge|suelz|\bDE\d{20}\b)/i.test(audit.text))throw Error('Real-data marker in capture '+id);
 for(const mail of audit.text.match(/[\w.+-]+@[\w.-]+\.[a-z]{2,}/ig)||[])if(!/@example\.(?:org|com|invalid)$/i.test(mail))throw Error('Non-example email in capture '+id);
 await page.evaluate(({rects,badgeInside})=>{
  rects.forEach((r,i)=>{
   const frame=document.createElement('div');frame.dataset.handbookCallout='';
   Object.assign(frame.style,{position:'absolute',left:r.x-3+'px',top:r.y-3+'px',width:r.width+6+'px',height:r.height+6+'px',border:'2px solid #164e8c',borderRadius:'4px',pointerEvents:'none',zIndex:'2147483646',boxSizing:'border-box'});
   const badge=document.createElement('span');badge.textContent=String(i+1);
   Object.assign(badge.style,{position:'absolute',left:'-38px',top:'0',width:'30px',height:'30px',lineHeight:'30px',textAlign:'center',borderRadius:'50%',background:'#164e8c',color:'#fff',border:'2px solid white',fontFamily:'Arial,sans-serif',fontSize:'17px',fontWeight:'800',boxSizing:'content-box'});
   const dialog=document.querySelector('dialog[open]');
   if(dialog){
    const d=dialog.getBoundingClientRect();
    frame.style.left=(r.x-scrollX-d.x+dialog.scrollLeft-3)+'px';
    frame.style.top=(r.y-scrollY-d.y+dialog.scrollTop-3)+'px';
    badge.style.left='auto';badge.style.right='2px';
    frame.append(badge);dialog.append(frame);
   }else{
    if(r.x<42||badgeInside){badge.style.left='auto';badge.style.right='4px';badge.style.top=badgeInside?'3px':(r.y<38?'3px':'-38px');}
    frame.append(badge);document.body.append(frame);
   }
  });
 },{rects:boxes,badgeInside:options.badgeInside===true});
 const filename='hilfe-'+id+'.png';
 await page.screenshot({path:path.join(root,'vereinsverwaltung/resources/handbook',filename),fullPage:true,clip:{x,y,width:right-x,height:bottom-y},animations:'disabled'});
 await page.evaluate(()=>document.querySelectorAll('[data-handbook-callout]').forEach(x=>x.remove()));
 return {file:filename,title,notes:items.map(x=>x[1]),source:new URL(page.url()).search,captured_at:'2026-09-07',privacy:'synthetic-inputs-and-visible-content-reviewed',width:Math.ceil(right-x),height:Math.ceil(bottom-y)};
}
module.exports={capture};
