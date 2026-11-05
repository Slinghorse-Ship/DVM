window.KulturhusPage.register('role-editor', pageContext => {
 const {frame: requestAnimationFrame} = pageContext;
(()=>{'use strict';for(const form of document.querySelectorAll('[data-role-editor]')){
 const custom=form.querySelector('input[type=checkbox][name="data[website_custom]"]'),settings=form.querySelector('input[type=checkbox][name="data[settings_access]"]'),status=form.querySelector('[data-role-editor-status]');
 const fields=[...form.querySelectorAll('select[name^="data[website_levels]"]')];if(!custom||!fields.length)continue;
 const baseline=field=>settings?.checked?'write':field.name==='data[website_levels][press]'?(form.querySelector('[name="data[press_access]"]:checked')?.value||'none'):'none';
 const describe=()=>{if(status)status.textContent=custom.checked?'Eigene Bereichsfreigaben sind aktiviert. Mit „Rolle speichern“ übernehmen.':'Voreinstellungen dieser Vereinsrolle. Redaktion und Superuser können zusätzliche Grundrechte behalten. Zum gezielten Sperren eigene Freigaben verwenden.';};
 const restore=()=>{for(const field of fields){field.value=baseline(field);field.dispatchEvent(new Event('change',{bubbles:true}));}};
 let restoring=false;
 for(const field of fields)field.addEventListener('change',()=>{if(restoring)return;custom.checked=true;describe();});
 const defaults=()=>{if(!custom.checked){restoring=true;restore();restoring=false;}describe();};
 custom.addEventListener('change',defaults);settings?.addEventListener('change',defaults);for(const field of form.querySelectorAll('[name="data[press_access]"]'))field.addEventListener('change',defaults);
 form.addEventListener('reset',()=>requestAnimationFrame(describe));describe();
}})();
});
