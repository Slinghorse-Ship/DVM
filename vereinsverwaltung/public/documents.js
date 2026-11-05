window.KulturhusPage.register('documents', pageContext => {
const text=source=>window.KulturhusInterface?.text(source)??source;
const format=(source,values)=>text(source).replace(/\{([a-z_]+)\}/g,(token,key)=>Object.hasOwn(values,key)?String(values[key]):token);
document.querySelectorAll('[data-doc-folder-disclosure]').forEach(details=>{details.open=!window.matchMedia('(max-width:700px)').matches;});
pageContext.on(document, 'input',event=>{
 const input=event.target;if(!input.matches('[data-doc-search]'))return;
 const query=input.value.toLocaleLowerCase('de').trim();
 input.closest('fieldset').querySelectorAll('[data-doc-choice]').forEach(row=>{row.hidden=!row.textContent.toLocaleLowerCase('de').includes(query)&&!row.querySelector('input').checked;});
});
document.querySelectorAll('input[type="file"][name="document_file"]').forEach(input=>input.addEventListener('change',()=>{
 const title=input.form.querySelector('input[name="title"]');if(title&&!title.value&&input.files[0])title.value=input.files[0].name.replace(/\.[^.]+$/,'');
}));

// Only the folder tree is affected; file details and forms keep their own state.
document.querySelectorAll('[data-folder-controls]').forEach(controls=>{
 const tree=document.getElementById(controls.dataset.folderControls);
 if(!tree||!tree.querySelector('details'))return;
 controls.hidden=false;
 controls.addEventListener('click',event=>{
  const button=event.target.closest('[data-folders-open]');if(!button)return;
  const open=button.dataset.foldersOpen==='1';
  tree.querySelectorAll('details').forEach(branch=>{branch.open=open;});
  controls.querySelector('[role="status"]').textContent=text(open?'Alle Ordner ausgeklappt.':'Alle Ordner eingeklappt.');
 });
});

// Keep the destination visible; a linked record already defines its protected area.
document.querySelectorAll('.doc-associations').forEach(group=>{
 const form=group.closest('form');if(!form)return;
 const update=()=>{if(group.dataset.uploadGroup)return;const selected=Array.from(group.querySelectorAll('[name="links[]"]:checked'));const status=group.querySelector('[data-doc-selection-status]');if(status)status.textContent=selected.length?format('{count} Vorgang/Vorgänge ausgewählt.',{count:selected.length})+' '+text(form.querySelector('[name="action"]')?.value==='document-upload-many'?'Diese Auswahl gilt für alle Dateien dieses Uploads.':'Diese Auswahl gilt nur für dieses Dokument.'):text('Noch kein Vorgang ausgewählt. Die Dateien werden im Eingang abgelegt.');const scope=form.querySelector('[name="scope"]');if(scope)scope.closest('label').hidden=selected.length>0;};
 group.addEventListener('change',update);update();
});
});
