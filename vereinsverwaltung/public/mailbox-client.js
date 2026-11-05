(() => {
 'use strict';
 function sizeMessages(root,context){for(const frame of root.querySelectorAll('[data-mailbox-html]')){
  if(frame.dataset.sized)continue;frame.dataset.sized='1';let bodyObserver;
  const fit=()=>{try{const body=frame.contentDocument?.body;if(!body)return;const style=frame.contentWindow.getComputedStyle(body),height=Math.ceil(body.getBoundingClientRect().height+parseFloat(style.marginTop||0)+parseFloat(style.marginBottom||0));frame.style.height=Math.max(44,Math.min(20000,height+2))+'px';}catch{/* A deliberately opened external link has its own origin. */}};
  const loaded=()=>{bodyObserver?.disconnect();const body=frame.contentDocument?.body;if(body){bodyObserver=new ResizeObserver(fit);bodyObserver.observe(body);}fit();};
  frame.addEventListener('load',loaded);const widthObserver=new ResizeObserver(fit);widthObserver.observe(frame);loaded();
  context?.cleanup(()=>{frame.removeEventListener('load',loaded);bodyObserver?.disconnect();widthObserver.disconnect();});
 }}
 function mount(root,context){sizeMessages(root,context);if(!window.KulturhusRichText)return;for(const composer of root.querySelectorAll('[data-mailbox-rich]')){
  if(composer.dataset.mounted)continue;composer.dataset.mounted='1';const form=composer.closest('form'),plain=form?.elements.reply_text,html=form?.elements.reply_html;if(!plain||!html||form.closest('fieldset[disabled]'))continue;
  html.disabled=false;composer.hidden=false;window.KulturhusRichText.mount(composer);const plainLabel=form.querySelector('[data-mailbox-plain]');if(plainLabel)plainLabel.hidden=true;plain.required=false;
  const toggle=composer.querySelector('[data-mailbox-format-toggle]');const formats=()=>{const open=composer.dataset.formats!=='open';composer.dataset.formats=open?'open':'compact';toggle?.setAttribute('aria-expanded',String(open));if(toggle)toggle.textContent=open?toggle.dataset.lessLabel:toggle.dataset.moreLabel;};toggle?.addEventListener('click',formats);context?.cleanup(()=>toggle?.removeEventListener('click',formats));
  const sync=()=>{plain.value=window.KulturhusRichText.plain(html.value);};form.addEventListener('input',sync);form.addEventListener('submit',sync);context?.cleanup(()=>{form.removeEventListener('input',sync);form.removeEventListener('submit',sync);});
 }}
 if(window.KulturhusPage)window.KulturhusPage.register('mailbox-client',context=>mount(context.root,context));else document.addEventListener('DOMContentLoaded',()=>mount(document));
})();
