window.KulturhusPage.register('minutes', pageContext => {
(() => {
  const t=(source,values={})=>{const key='minutes:'+source;const translated=window.KulturhusInterface?.text(key)??key;return(translated===key?source:translated).replace(/\{([a-z_]+)\}/g,(whole,name)=>Object.hasOwn(values,name)?String(values[name]):whole);};
  const form = document.querySelector('[data-minutes-form]');
  if (form && !form.hasAttribute('data-minutes-readonly')) {
    const field = form.querySelector('[data-minutes-rich]');
    const surface = document.createElement('div');
    surface.className = 'minutes-editor';
    surface.contentEditable = 'true';
    surface.setAttribute('role', 'textbox');
    surface.setAttribute('aria-label', t('Protokolltext'));
    surface.setAttribute('aria-multiline', 'true');
    surface.dataset.placeholder=t('Anwesende, Tagesordnung, Beschlüsse …');
    surface.setAttribute('aria-describedby', 'minutes-editor-help');
    surface.spellcheck = true;
    // The server validates independently; pasted markup is reduced before entering the editor.
    const clean = html => {
      const parsed = new DOMParser().parseFromString(html, 'text/html');
      const out = document.createDocumentFragment();
      const allowed = new Set(['P','DIV','BR','H2','H3','STRONG','B','EM','I','U','UL','OL','LI','BLOCKQUOTE','HR']);
      const drop = new Set(['SCRIPT','STYLE','IFRAME','OBJECT','EMBED','SVG','MATH','FORM','INPUT','IMG','VIDEO','AUDIO','LINK','META','TEMPLATE','NOSCRIPT','BASE','TEXTAREA','SELECT','OPTION','BUTTON','FRAME','FRAMESET','CANVAS','SOURCE','PICTURE']);
      const copy = (node, parent) => {
        if (node.nodeType === Node.TEXT_NODE) { parent.append(document.createTextNode(node.textContent)); return; }
        if (node.nodeType !== Node.ELEMENT_NODE || drop.has(node.tagName)) return;
        const target = allowed.has(node.tagName) ? document.createElement(node.tagName.toLowerCase()) : parent;
        if (target !== parent) parent.append(target);
        for (const child of node.childNodes) copy(child, target);
      };
      for (const child of parsed.body.childNodes) copy(child, out);
      return out;
    };
    surface.replaceChildren(clean(field.value));
    const toolbar = document.createElement('div');
    toolbar.className = 'minutes-toolbar';
    toolbar.setAttribute('role', 'toolbar'); toolbar.setAttribute('aria-label', t('Protokolltext formatieren'));
    const sync = () => {field.value = surface.innerHTML;};
    let dirty = false, savedRange = null;
    const markDirty = () => {dirty = true; form.querySelector('[data-minutes-status]').textContent = t('Änderungen noch nicht gespeichert. Entwurf speichern, bevor du die Seite verlässt.');};
    const restoreSelection = () => {
      surface.focus();
      if (savedRange && surface.contains(savedRange.commonAncestorContainer)) {const selection=getSelection();selection.removeAllRanges();selection.addRange(savedRange);}
    };
    const formatButtons=[];
    for (const [label, command, value] of [[t('Fett'),'bold'],[t('Kursiv'),'italic'],[t('Überschrift'),'formatBlock','h2'],[t('Absatz'),'formatBlock','p'],[t('Aufzählung'),'insertUnorderedList'],[t('Nummerierung'),'insertOrderedList'],[t('Rückgängig'),'undo']]) {
      const button = document.createElement('button'); button.type = 'button'; button.className = 'secondary'; button.textContent = label;
      button.dataset.command=command;
      button.tabIndex=formatButtons.length?-1:0;
      if (['bold','italic'].includes(command)) button.setAttribute('aria-pressed','false');
      if (typeof document.execCommand!=='function') button.disabled=true;
      button.addEventListener('mousedown', e => e.preventDefault());
      button.addEventListener('click', () => {restoreSelection();document.execCommand(command, false, value);sync();markDirty();});
      toolbar.append(button);formatButtons.push(button);
    }
    toolbar.addEventListener('keydown',event=>{
      if(!['ArrowLeft','ArrowRight','Home','End'].includes(event.key))return;
      const buttons=formatButtons.filter(button=>!button.disabled),index=buttons.indexOf(document.activeElement);if(index<0)return;
      event.preventDefault();const next=event.key==='Home'?0:event.key==='End'?buttons.length-1:(index+(event.key==='ArrowRight'?1:-1)+buttons.length)%buttons.length;
      for(const button of buttons)button.tabIndex=-1;buttons[next].tabIndex=0;buttons[next].focus();
    });
    pageContext.on(document, 'selectionchange',()=>{
      const selection=getSelection();if(!selection?.rangeCount||!surface.contains(selection.anchorNode)||!surface.contains(selection.focusNode))return;
      savedRange=selection.getRangeAt(0).cloneRange();
      for(const button of formatButtons)if(button.hasAttribute('aria-pressed'))button.setAttribute('aria-pressed',String(Boolean(document.queryCommandState?.(button.dataset.command))));
    });
    field.hidden = true; field.after(toolbar, surface);
    const plainEditor=form.querySelector('[data-minutes-plain-editor]');if(plainEditor){plainEditor.hidden=true;plainEditor.querySelector('[name=body_text]').disabled=true;}
    const oldLabel=form.querySelector('.minutes-fallback-label');if(oldLabel)oldLabel.hidden=true;
    if(form.elements.body_mode)form.elements.body_mode.value='html';
    const editorHelp=form.querySelector('.minutes-editor-help');if(editorHelp)editorHelp.hidden=false;
    surface.addEventListener('paste', e => {
      e.preventDefault(); const html = e.clipboardData.getData('text/html');
      if (html) {const safe = document.createElement('div'); safe.append(clean(html)); document.execCommand('insertHTML', false, safe.innerHTML);}
      else document.execCommand('insertText', false, e.clipboardData.getData('text/plain'));
      sync(); markDirty();
    });
    surface.addEventListener('drop', e => e.preventDefault());
    surface.addEventListener('input', sync);
    const editor = form.querySelector('[data-minutes-editor]'); const upload = form.querySelector('[data-minutes-upload]');
    const changeMode = () => {const pdf = form.elements.source_kind.value === 'pdf'; editor.hidden = pdf; upload.hidden = !pdf; form.elements.minute_pdf.disabled = !pdf;form.elements.minute_pdf.required=pdf&&form.elements.minute_pdf.dataset.minutesPdfRequired==='1';editor.querySelectorAll('[data-minutes-template] input,[data-minutes-template] textarea,[data-minutes-template] select,[data-minutes-template] button').forEach(control=>control.disabled=pdf);};
    form.querySelectorAll('[name=source_kind]').forEach(r => r.addEventListener('change', changeMode)); changeMode();
    const builder=form.querySelector('[data-minutes-template]');let templatePending=false;
    if(builder){
      builder.hidden=false;
      const list=builder.querySelector('[data-minutes-top-list]'),template=form.querySelector('[data-minutes-top-template]'),status=builder.querySelector('[data-minutes-template-status]'),apply=builder.querySelector('[data-minutes-apply-template]');
      const presets={assembly:[t('Eröffnung und Begrüßung'),t('Berichte'),t('Aussprache und Beschlüsse'),t('Verschiedenes')],board:[t('Eröffnung und Tagesordnung'),t('Berichte und laufende Aufgaben'),t('Beschlüsse und nächste Schritte'),t('Nächster Termin')],meeting:[t('Anlass und Ziel'),t('Besprochene Themen'),t('Aufgaben und nächste Schritte')]};
      const renumber=()=>list.querySelectorAll('[data-minutes-top-label]').forEach((label,index)=>label.textContent=t('Tagesordnungspunkt')+' '+(index+1));
      const pending=()=>{templatePending=true;apply.disabled=false;status.textContent=t('Neue Vorlagenangaben: werden beim Übernehmen oder Speichern im Protokolltext ergänzt.');markDirty();};
      const addTop=(title='')=>{
        const row=template.content.firstElementChild.cloneNode(true);row.querySelector('[data-minutes-top="title"]').value=title;
        row.open=list.childElementCount===0;
        const summary=row.querySelector('[data-minutes-top-summary]'),topic=row.querySelector('[data-minutes-top="title"]');
        const label=()=>summary.textContent=topic.value.trim()||t('Thema und Ergebnis eintragen');topic.addEventListener('input',label);label();
        row.querySelector('[data-minutes-remove-top]').addEventListener('click',()=>{row.remove();renumber();pending();});
        list.append(row);renumber();return row;
      };
      const select=builder.querySelector('[data-minutes-template-kind]');let previousKind=select.value;
      const addPreset=()=>{for(const title of presets[select.value]||[])addTop(title);};addPreset();
      select.addEventListener('change',()=>{
        // A type change is harmless before typing; existing work is never discarded.
        const hasInput=[...list.querySelectorAll('textarea,input')].some(field=>field.dataset.minutesTop!=='title'&&field.value.trim());
        const headings=[...list.querySelectorAll('[data-minutes-top="title"]')].map(field=>field.value);
        const untouched=JSON.stringify(headings)===JSON.stringify(presets[previousKind]);
        if(!hasInput&&(untouched||headings.every(value=>!value.trim()))){list.replaceChildren();addPreset();apply.disabled=false;}
        else status.textContent=t('Deine bereits eingegebenen Tagesordnungspunkte bleiben erhalten.');
        previousKind=select.value;
      });
      builder.addEventListener('input',pending);
      builder.querySelector('[data-minutes-add-top]').addEventListener('click',()=>{const row=addTop();row.open=true;pending();row.querySelector('input').focus();});
      const appendTemplate=()=>{
        const fields=[...builder.querySelectorAll('input,textarea')];const invalid=fields.find(input=>!input.validity.valid);
        if(invalid){builder.open=true;window.KulturhusFormValidation?.show(invalid);invalid.reportValidity();return false;}
        const content=document.createElement('div');
        const paragraph=(name,value)=>{if(!value.trim())return;const p=document.createElement('p');const strong=document.createElement('strong');strong.textContent=name+': ';p.append(strong);value.trim().split('\n').forEach((line,index)=>{if(index)p.append(document.createElement('br'));p.append(document.createTextNode(line));});content.append(p);};
        const title=document.createElement('h2');title.textContent=select.selectedOptions[0].textContent;content.append(title);
        const date=form.elements.meeting_on.value;if(/^\d{4}-\d{2}-\d{2}$/.test(date))paragraph(t('Sitzungsdatum'),date.split('-').reverse().join('.'));
        builder.querySelectorAll('[data-minutes-meta]').forEach(input=>paragraph(t(input.dataset.minutesMeta),input.value));
        let number=0;
        for(const row of list.children){
          const values=Object.fromEntries([...row.querySelectorAll('[data-minutes-top]')].map(input=>[input.dataset.minutesTop,input.value.trim()]));
          if(!Object.values(values).some(Boolean))continue;
          const heading=document.createElement('h3');heading.textContent=t('TOP')+' '+(++number)+(values.title?' · '+values.title:'');content.append(heading);
          paragraph(t('Besprechung'),values.discussion);paragraph(t('Beschluss'),values.decision);
          const votes=[['yes',t('Ja')],['no',t('Nein')],['abstain',t('Enthaltungen')]].filter(([key])=>values[key]!=='').map(([key,label])=>label+': '+values[key]);
          if(votes.length)paragraph(t('Abstimmung'),votes.join(' · '));paragraph(t('Ergebnis'),values.result);
        }
        if(select.value==='assembly'){
          const heading=document.createElement('h3');heading.textContent=t('Unterzeichnung des Protokolls');content.append(heading);
          paragraph(t('Ort und Datum der Unterzeichnung'),'________________________________');
          paragraph(t('Unterschrift Vorsitz oder Stellvertretung'),'________________________________');
          paragraph(t('Unterschrift Schriftführung'),'________________________________');
        }
        surface.focus();const range=document.createRange();range.selectNodeContents(surface);range.collapse(false);const selection=getSelection();selection.removeAllRanges();selection.addRange(range);
        if(typeof document.execCommand!=='function'||!document.execCommand('insertHTML',false,content.innerHTML))surface.append(...content.childNodes);
        sync();markDirty();templatePending=false;
        // The generated text is the saved record. Clear transient fields to prevent accidental duplicates.
        builder.querySelectorAll('[data-minutes-meta]').forEach(input=>input.value='');list.replaceChildren();addTop();
        builder.open=false;apply.disabled=true;status.textContent=t('In den Protokolltext übernommen. Dort kannst du alles weiterbearbeiten.');
        surface.focus();return true;
      };
      apply.addEventListener('click',appendTemplate);
      form.addEventListener('submit',event=>{if(form.elements.source_kind.value==='editor'&&templatePending&&!appendTemplate())event.preventDefault();},true);
      changeMode();
    }
    form.addEventListener('input', markDirty);
    form.addEventListener('change', markDirty);
    form.addEventListener('submit', event => {if(event.defaultPrevented)return;sync();dirty=false;});
    pageContext.guard(()=>dirty);
    pageContext.on(window, 'beforeunload', e => {if (dirty) {e.preventDefault(); e.returnValue = '';}});
  }
  const print=document.querySelector('[data-minutes-print]');
  if(print){print.hidden=false;print.addEventListener('click', () => window.print());}
})();
});
