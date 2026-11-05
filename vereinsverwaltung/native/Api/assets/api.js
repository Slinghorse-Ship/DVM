if(!window.KulturhusApiTokens){
 window.KulturhusApiTokens=true;
 document.documentElement.dataset.apiClipboardReady='1';
 document.addEventListener('click',async event=>{const button=event.target instanceof Element?event.target.closest('[data-api-copy]'):null;if(!button)return;const panel=button.closest('.native-api'),token=panel?.querySelector('#api-new-token'),status=panel?.querySelector('[data-api-copy-status]');if(!token||!status)return;token.focus();token.select();try{await navigator.clipboard.writeText(token.value);status.textContent=panel.dataset.apiCopySuccess||'Kopiert.';}catch{status.textContent=panel.dataset.apiCopyFallback||'API-Zugang markiert. Jetzt kopieren.';}});
}
