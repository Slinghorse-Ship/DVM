window.KulturhusPage.register('invoice-inbox-detail', pageContext => {
(()=>{'use strict';const details=document.getElementById('rechnung-entfernen');if(!details)return;const reveal=()=>{if(location.hash==='#rechnung-entfernen')details.open=true;};pageContext.on(window, 'hashchange',reveal);pageContext.on(document, 'click',event=>{const a=event.target.closest('a');if(a&&a.hash==='#rechnung-entfernen'&&a.pathname===location.pathname&&a.search===location.search)details.open=true;});reveal();})();
});
