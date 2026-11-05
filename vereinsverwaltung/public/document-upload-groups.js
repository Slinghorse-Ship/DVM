'use strict';
// One transport batch can contain several independently checked destinations.
window.KulturhusDocumentGroups = {
 create(form, restored, context = null) {
  const text=source=>window.KulturhusInterface?.text(source)??source;
  const format=(source,values)=>text(source).replace(/\{([a-z_]+)\}/g,(token,key)=>Object.hasOwn(values,key)?String(values[key]):token);

  const dialogs=new Set();
  context?.cleanup(()=>{for(const dialog of dialogs){dialog.close();dialog.remove();}dialogs.clear();});
  const trackDialog=dialog=>{dialogs.add(dialog);dialog.addEventListener('close',()=>dialogs.delete(dialog),{once:true});};
  if(form.dataset.invoiceInbox==='1')return {
   register(){},bind(){},remove(){},
   review(rows,confirm){
    const dialog=document.createElement('dialog');trackDialog(dialog);dialog.className='live-upload-review';if(form.hasAttribute('data-inventory-invoice-form'))dialog.dataset.inventoryInvoiceConfirm='1';dialog.setAttribute('aria-label',text('Rechnungseingang bestätigen'));
    const heading=document.createElement('h2');heading.textContent=text('Im Rechnungseingang speichern');
    const explanation=document.createElement('p');explanation.textContent=(form.dataset.inventoryInvoice?format('Zuordnung nur zu diesem Gegenstand: {item}.',{item:form.dataset.inventoryInvoice})+' ':'')+format('Alle {count} Dateien werden als Eingangsbelege unter Finanzen abgelegt. Es wird keine Ausgabe, Freigabe oder Zahlung erzeugt.',{count:rows.length});
    const list=document.createElement('ul');for(const row of rows){const item=document.createElement('li');item.textContent=row.title.value+' · '+row.date.value+' · '+row.name;list.append(item);}
    const cancel=document.createElement('button');cancel.type='button';cancel.className='secondary';cancel.textContent=text('Zurück zur Bearbeitung');cancel.addEventListener('click',()=>dialog.close());
    const save=document.createElement('button');save.type='button';save.textContent=format('Alle {count} Dateien im Rechnungseingang speichern',{count:rows.length});save.addEventListener('click',()=>{dialog.close();if(!context?.signal.aborted&&form.isConnected)confirm();});
    dialog.append(heading,explanation,list,cancel,save);dialog.addEventListener('close',()=>dialog.remove());document.body.append(dialog);dialog.showModal();cancel.focus();
   }
  };
  const original = form.querySelector('.doc-associations');
  if (!original) return null;
  const template = original.cloneNode(true);
  let sourceData=null;try{sourceData=JSON.parse(original.dataset.docSourceData||'null');}catch{}
  const groups = new Map();
  const rows = new Set();
  const originalFolder = form.querySelector('[name="folder"]');
  const originalScope = form.querySelector('[name="scope"]');
  const panel = document.createElement('section');
  panel.className = 'document-upload-groups';
  original.before(panel);
  const controls = document.createElement('div');
  controls.className = 'doc-actions';
  const add = document.createElement('button');
  add.type = 'button'; add.className = 'secondary'; add.textContent = text('+ Weiteres Ablageziel');
  const nextLabel = document.createElement('label');
  nextLabel.textContent = text('Neue Dateien ablegen bei');
  const nextGroup = document.createElement('select');
  nextGroup.setAttribute('aria-label', text('Neue Dateien ablegen bei'));
  nextLabel.append(nextGroup);
  const move = document.createElement('button');
  move.type = 'button'; move.className = 'secondary'; move.textContent = text('Markierte Dateien diesem Ziel zuordnen');
  const status = document.createElement('p');
  status.setAttribute('role', 'status');
  controls.append(add, nextLabel, move);
  panel.append(controls, status);
  const saved = restored.action === 'document-upload-many' ? restored : {};

  function links(group) {
   return Array.from(group.node.querySelectorAll('[data-document-link]:checked'));
  }
  function label(group) {
   const chosen = links(group);
   return group.name + ': ' + (chosen.length ? chosen.map(c => c.closest('label').textContent.trim()).join('; ') : text('Allgemeine Dokumentablage'));
  }
  function choices(select, preferred) {
   const value = preferred || select.value;
   select.replaceChildren();
   for (const [id, group] of groups) {
    const option = document.createElement('option'); option.value = id; option.textContent = label(group); select.append(option);
   }
   select.value = groups.has(value) ? value : 'g0';
  }
  function refresh() {
   choices(nextGroup);
   for (const row of rows) choices(row.group);
   for (const [id, group] of groups) {
    const count = Array.from(rows).filter(r => r.group.value === id).length;
    group.count.textContent = format('{count} Datei(en) für dieses Ziel.',{count})+' ' + (links(group).length ? text('Dieses Ziel gilt nur für die zugeteilten Dateien.') : text('Ohne weiteren Vorgang in der allgemeinen Ablage.'));
    group.scope.closest('label').hidden = true;group.destination.value=links(group)[0]?.value||'';
   }
  }
  function addGroup(id, node = template.cloneNode(true)) {
   if (groups.size >= 10 || groups.has(id)) return;
   const name = format('Ziel {number}',{number:Number(id.slice(1))+1});
   if(sourceData){
    const selected=saved.groupLinks?.[id]??(id==='g0'?Array.from(original.querySelectorAll('[name="links[]"]:checked'),input=>input.value):[]);
    const list=node.querySelector('.doc-choices');list.replaceChildren();
    for(const key of new Set([...selected,...sourceData.candidates])){
     const source=sourceData.choices[key];if(!source)continue;
     const label=document.createElement('label');label.dataset.docChoice='';const checkbox=document.createElement('input');checkbox.type='checkbox';checkbox.className='check';checkbox.name='links[]';checkbox.value=key;checkbox.checked=selected.includes(key);
     const span=document.createElement('span');const small=document.createElement('small');small.textContent=sourceData.type_labels[source.source_type]+(source.unavailable?' · '+text('Zuordnung derzeit nicht verfügbar'):!Number(source.available)?' · '+text('Historische Zuordnung, Vorgang nicht mehr aktiv'):'');span.append(small,document.createTextNode(source.label));label.append(checkbox,span);list.append(label);
    }
   }

   node.dataset.uploadGroup='1';node.querySelector('legend').textContent = name;
   const marker = document.createElement('input'); marker.type = 'hidden'; marker.name = 'document_group_ids[]'; marker.value = id; node.append(marker);
   for (const checkbox of node.querySelectorAll('[name="links[]"]')) {
    checkbox.name = `document_group_links[${id}][]`; checkbox.dataset.documentLink = '1';
    if (saved.groupLinks) checkbox.checked = (saved.groupLinks[id] || []).includes(checkbox.value);
    else if (id !== 'g0') checkbox.checked = false;
   }
   const count = node.querySelector('[data-doc-selection-status]');
   const primaryLabel=document.createElement('label');primaryLabel.textContent=text('Ablageziel');const destination=document.createElement('select');const inbox=document.createElement('option');inbox.value='';inbox.textContent=text('Allgemeine Dokumentablage');destination.append(inbox);
   for(const checkbox of node.querySelectorAll('[data-document-link]')){const option=document.createElement('option');option.value=checkbox.value;option.textContent=checkbox.closest('label').textContent.trim();destination.append(option);}
   const checked=Array.from(node.querySelectorAll('[data-document-link]:checked'));destination.value=checked[0]?.value||'';primaryLabel.append(destination);node.querySelector('legend').after(primaryLabel);
   const extra=document.createElement('details');const extraSummary=document.createElement('summary');extraSummary.textContent=text('Zusätzliche Verknüpfungen (optional)');extra.append(extraSummary);
   for(const child of Array.from(node.children)){if(child!==primaryLabel&&child.tagName!=='LEGEND'&&child!==marker&&child!==count)extra.append(child);}node.append(extra);extra.open=checked.length>1;
   destination.addEventListener('change',()=>{for(const checkbox of node.querySelectorAll('[data-document-link]'))checkbox.checked=checkbox.value===destination.value;refresh();});
   const options = document.createElement('details'); const summary = document.createElement('summary'); summary.textContent = text('Ordner bei Bedarf ändern'); options.append(summary);
   const folderLabel = document.createElement('label'); folderLabel.textContent = text('Ordner');
   const folder = originalFolder.cloneNode(true); folder.name = `document_group_folders[${id}]`; folder.value = saved.groupFolders?.[id] ?? (id === 'g0' ? originalFolder.value : ''); folderLabel.append(folder);
   const scopeLabel = document.createElement('label'); scopeLabel.textContent = text('Bereich ohne Vorgangszuordnung');
   const scope = originalScope.cloneNode(true); scope.name = `document_group_scopes[${id}]`; scope.dataset.groupScope = '1'; scope.value = saved.groupScopes?.[id] ?? originalScope.value; scopeLabel.append(scope);
   scopeLabel.hidden=true;options.append(folderLabel, scopeLabel); node.append(options);
   const group = {name, node, count, folder, scope, destination}; groups.set(id, group);
   node.addEventListener('change', refresh);
   if (id !== 'g0') {
    const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'secondary'; remove.textContent = text('Leeres Ziel entfernen');
    remove.addEventListener('click', () => {
     if (Array.from(rows).some(r => r.group.value === id)) { status.textContent = text('Die Dateien dieser Gruppe zuerst einer anderen Gruppe zuordnen.'); return; }
     groups.delete(id); node.remove(); refresh();
    }); node.append(remove);
   }
   controls.before(node); refresh();
  }
  addGroup('g0', original);
  for (const id of saved.groupIds || []) if (/^g[0-9]$/.test(id) && id !== 'g0') addGroup(id);
  originalFolder.closest('label').hidden = true;
  originalScope.closest('label').hidden = true;
  add.addEventListener('click', () => {
   const id = Array.from({length:10}, (_, i) => 'g' + i).find(key => !groups.has(key));
   if (!id) { status.textContent = text('Höchstens zehn Ablageziele pro Upload.'); return; }
   addGroup(id); nextGroup.value = id; status.textContent = format('Neue Dateien werden zunächst {destination} zugeordnet.',{destination:groups.get(id).name});
  });
  move.addEventListener('click', () => {
   const selected = Array.from(rows).filter(r => r.groupCheck.checked);
   for (const row of selected) { row.group.value = nextGroup.value; row.groupCheck.checked = false; }
   status.textContent = selected.length ? format('{count} Datei(en) verschoben. Vor dem Speichern wird alles zur Bestätigung angezeigt.',{count:selected.length}) : text('Zuerst die gewünschten Dateien in der Uploadliste markieren.'); refresh();
  });
  return {
   register(row) {
    const labelNode = document.createElement('label'); labelNode.textContent = format('Ablageziel für {name}',{name:row.name});
    row.group = document.createElement('select'); choices(row.group, nextGroup.value); labelNode.append(row.group);
    const checkLabel = document.createElement('label'); checkLabel.className = 'check'; row.groupCheck = document.createElement('input'); row.groupCheck.type = 'checkbox'; checkLabel.append(row.groupCheck, document.createTextNode(format('Zum Verschieben markieren: {name}',{name:row.name})));
    row.li.prepend(checkLabel, labelNode); rows.add(row); row.group.addEventListener('change', refresh); refresh();
   },
   bind(row, token) { row.group.name = `document_groups[${token}]`; choices(row.group, saved.fileGroups?.[token] || row.group.value); refresh(); },
   remove(row) { rows.delete(row); refresh(); },
   review(all, confirm) {
    const dialog = document.createElement('dialog');trackDialog(dialog); dialog.className = 'live-upload-review'; dialog.setAttribute('aria-label',text('Dokumente und Vorgänge bestätigen'));
    const heading = document.createElement('h2'); heading.textContent = format('{count} Dokumente – Zuordnung bestätigen',{count:all.length}); dialog.append(heading);
    for (const [id, group] of groups) {
     const members = all.filter(row => row.group.value === id); if (!members.length) continue;
     const title = document.createElement('h3'); title.textContent = format('{destination} · {count} Datei(en)',{destination:group.name,count:members.length}); dialog.append(title);
     const destination = document.createElement('p'); destination.textContent = format('Ablageziel: {destination}',{destination:links(group).map(c=>c.closest('label').textContent.trim()).join('; ')||text('Allgemeine Dokumentablage')}); dialog.append(destination);
     const folder = document.createElement('p'); folder.textContent = format('Ordner: {folder}',{folder:group.folder.selectedOptions[0].textContent}); dialog.append(folder);
     const list = document.createElement('ul'); for (const row of members) { const item = document.createElement('li'); item.textContent = row.title.value + ' · ' + row.date.value + ' · ' + row.name; list.append(item); } dialog.append(list);
    }
    const kind = form.querySelector('[name="kind"]'); const common = document.createElement('p'); common.textContent = format('Gemeinsame Dokumentart: {kind}. Jedes Dokument bleibt später einzeln bearbeitbar.',{kind:kind.selectedOptions[0].textContent}); dialog.append(common);
    const cancel = document.createElement('button'); cancel.type = 'button'; cancel.className = 'secondary'; cancel.textContent = text('Zurück zur Bearbeitung'); cancel.addEventListener('click',()=>dialog.close());
    const save = document.createElement('button'); save.type = 'button'; save.textContent = format('Diese Zuordnungen für alle {count} Dokumente bestätigen',{count:all.length}); save.addEventListener('click',()=>{dialog.close();if(!context?.signal.aborted&&form.isConnected)confirm();});
    dialog.append(cancel, save); dialog.addEventListener('close',()=>dialog.remove()); document.body.append(dialog); dialog.showModal(); cancel.focus();
   }
  };
 }
};
