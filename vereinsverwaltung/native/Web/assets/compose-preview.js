/* Current unsaved editorial content; no API request, persistence or transport. */
(() => {
 'use strict';const mounted=new WeakSet();
 const element=(tag,value)=>{const n=document.createElement(tag);if(value!==undefined)n.textContent=value;return n;};
 function mount(root=document,context=null){for(const panel of root.querySelectorAll('[data-compose-live]')){
  if(mounted.has(panel))continue;mounted.add(panel);
  const seed=JSON.parse(panel.querySelector('[data-compose-live-seed]').value),layout=panel.closest('.compose-layout'),form=[...(layout?.querySelectorAll('form')||[])].find(n=>n.elements.namedItem('operation')?.value==='communication-save');if(!form)continue;
  const content=panel.querySelector('[data-compose-live-content]'),label=panel.querySelector('[data-compose-live-kind]');
  const writing=form.querySelector('.compose-writing');if(writing)writing.before(panel);else form.append(panel);
  for(const name of ['title','summary']){const input=form.elements.namedItem(name);if(input?.closest('label')){const field=input.closest('label');field.classList.add('compose-live-'+name);content.append(field);}}
  const richEditor=form.querySelector('[data-kh-richtext]');if(richEditor)content.append(richEditor);
  const media=element('div');media.dataset.composeLiveMedia='';content.append(media);
  const rich=html=>{const n=element('div');n.className='kh-richtext';n.innerHTML=window.KulturhusRichText?.sanitize(html)||'';return n;};
  const image=(id,alt)=>{const source=seed.media[id];if(!source)return null;const n=element('img');n.src=source.url;n.alt=alt||source.alt;n.loading='lazy';return n;};
  const read=name=>form.elements.namedItem(name)?.value||'';
  const list=name=>{try{const value=JSON.parse(read(name)||'[]');return Array.isArray(value)?value:[];}catch{return[];}};
  const update=()=>{
   const kind=read('kind')||seed.kind;label.textContent={post:'Beitrag',story:'Story',reel:'Reel',carousel:'Karussell',release:'Pressemitteilung'}[kind]||'';panel.dataset.kind=kind;media.replaceChildren();
   const blocks=list('blocks_json'),slides=kind==='story'?list('story_json'):kind==='carousel'?list('carousel_json'):[];
   if(slides.length){const index=Number(form.querySelector('[data-kh-editor="'+(kind==='story'?'story':'carousel')+'"] [data-editor-select]')?.value||0),slide=slides[index]||slides[0],photo=image(slide.media_ref,slide.title);if(photo)media.append(photo);media.append(element('h3',slide.title),element('p',slide.text));}

   for(const block of blocks){
    if(block.type==='richtext')media.append(rich(block.html));
    else if(block.type==='image'){const picture=image(block.media_ref,block.alt);if(picture)media.append(picture);}
    else if(block.type==='heading')media.append(element('h3',block.text));
    else if(block.type==='text')media.append(element('p',block.text));
    else if(block.type==='divider')media.append(element('hr'));
    else if(block.type==='button'){const button=element('span',block.text);button.className='communication-button';media.append(button);}
    else if(block.type==='event'){const picture=image(block.media_ref,block.title);if(picture)media.append(picture);media.append(element('h3',block.title),element('p',[block.date,block.venue].filter(Boolean).join(' · ')),element('p',block.text));}
   }
  };
  const on=(type)=>context?context.on(form,type,update):form.addEventListener(type,update);on('input');on('change');update();
 }}
 window.KulturhusComposePreview=Object.freeze({mount});if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',()=>mount(),{once:true});else mount();
})();
