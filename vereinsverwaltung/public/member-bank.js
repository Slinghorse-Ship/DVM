window.KulturhusPage.register('member-bank', pageContext => {
 const {timeout: setTimeout} = pageContext;
(()=>{'use strict';const t=s=>window.KulturhusInterface?.text(s)||s;
 const form=document.querySelector('[data-bank-revealed-form]');if(!form)return;
 const status=document.querySelector('[data-bank-reveal-status]');const reset=document.querySelector('[data-bank-reveal-reset]');
 const clear=()=>{for(const input of form.querySelectorAll('input')){if(input.name==='password'||input.name.startsWith('data[')){input.value='';input.defaultValue='';input.removeAttribute('value');}}form.hidden=true;window.KulturhusNavigation?.markClean(form);if(status)status.textContent=t('Die freigegebenen Bankdaten wurden wieder verborgen. Öffnen Sie diese Seite erneut, um die Bankdaten nach einer Sicherheitsprüfung wieder anzuzeigen.');if(reset)reset.textContent=t('Bankdatenbereich erneut öffnen');};
 pageContext.cleanup(clear);
 setTimeout(clear,300000);pageContext.on(window, 'pagehide',clear,{once:true});
 pageContext.on(window, 'pageshow',event=>{if(event.persisted)clear();});
})();
});
