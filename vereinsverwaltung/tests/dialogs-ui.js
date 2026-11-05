'use strict';
(() => {
  const output=document.getElementById('results'),fixtures=document.getElementById('fixtures');
  document.getElementById('example').addEventListener('submit',e=>{e.preventDefault();document.getElementById('example-status').textContent='Bestätigung angekommen. Es wurde nichts gelöscht.';});
  document.getElementById('run').addEventListener('click',async event=>{
    event.currentTarget.disabled=true;output.textContent='';let count=0,nativeCalls=0;
    const old={confirm:window.confirm,prompt:window.prompt,alert:window.alert};
    for(const key of Object.keys(old))window[key]=()=>{nativeCalls++;throw Error('Native Browsermeldung: '+key);};
    const check=(ok,label)=>{if(!ok)throw Error(label);output.textContent+='OK '+(++count)+' · '+label+'\n';};
    const tick=()=>new Promise(resolve=>setTimeout(resolve,0));
    const modal=()=>document.querySelector('dialog.kh-dialog[open]');
    const click=label=>Array.from(modal().querySelectorAll('button')).find(b=>b.textContent===label).click();
    const make=(attribute='data-confirm')=>{
      const f=document.createElement('form');f.setAttribute(attribute,'Demokonto löschen?');
      f.innerHTML='<input name="csrf" type="hidden" value="synthetic-csrf"><input name="version" type="hidden" value="1"><label>Name<input name="name" required value="Demokonto"></label><button name="operation" value="remove">Löschen</button>';
      let calls=0,submitter=null;f.addEventListener('submit',e=>{e.preventDefault();calls++;submitter=e.submitter;});fixtures.append(f);
      return {f,button:f.querySelector('button'),calls:()=>calls,submitter:()=>submitter};
    };
    try{
      let t=make();t.button.focus();t.f.requestSubmit(t.button);
      check(!!modal()&&modal().matches(':modal'),'Eigener modaler Dialog geöffnet');
      check(modal().getAttribute('aria-labelledby')&&modal().getAttribute('aria-describedby'),'Titel und Beschreibung für Hilfstechnik');
      check(document.activeElement.textContent==='Abbrechen','Sichere Aktion zuerst fokussiert');
      check(t.calls()===0,'Vor Bestätigung kein nachgelagerter Submit-Handler');
      click('Abbrechen');await tick();check(t.calls()===0&&!modal(),'Abbrechen sendet nicht');check(document.activeElement===t.button,'Fokus kehrt zum Auslöser zurück');
      t.f.requestSubmit(t.button);modal().dispatchEvent(new Event('cancel',{cancelable:true}));await tick();check(t.calls()===0&&!modal(),'Escape/Cancel sendet nicht');
      t.f.requestSubmit(t.button);t.f.requestSubmit(t.button);check(document.querySelectorAll('dialog.kh-dialog').length===1,'Doppelklick erzeugt nur einen Dialog');
      click('Löschen');await tick();check(t.calls()===1&&t.submitter()===t.button,'Bestätigen erhält den ursprünglichen Submitter und sendet einmal');
      check(t.f.elements.csrf.value==='synthetic-csrf'&&t.f.elements.version.value==='1','CSRF und Version unverändert');
      t.f.elements.name.value='';t.f.requestSubmit(t.button);check(!modal()&&t.calls()===1,'Ungültiges Formular bleibt gesperrt');t.f.elements.name.value='Demokonto';
      t.f.requestSubmit(t.button);t.f.elements.version.value='2';click('Löschen');await tick();check(t.calls()===1&&modal().textContent.includes('inzwischen geändert'),'Zwischenzeitlich geänderte Daten verlangen neue Prüfung');click('Schließen');await tick();
      t.f.requestSubmit(t.button);t.button.disabled=true;click('Löschen');await tick();check(t.calls()===1,'Zwischenzeitlich gesperrte Aktion wird nicht ausgeführt');t.button.disabled=false;
      t.f.requestSubmit(t.button);t.f.remove();click('Löschen');await tick();check(t.calls()===1,'Entferntes Formular wird nicht gesendet');
      t=make('data-minutes-confirm');t.f.requestSubmit(t.button);click('Löschen');await tick();check(t.calls()===1,'Protokollaktionen nutzen denselben Dialog');t.f.remove();
      t=make();const shadow=document.createElement('input');shadow.name='requestSubmit';shadow.value='synthetic';t.f.append(shadow);t.f.requestSubmit;HTMLFormElement.prototype.requestSubmit.call(t.f,t.button);click('Löschen');await tick();check(t.calls()===1,'Feldnamen können requestSubmit nicht verdecken');t.f.remove();
      const text='<img src=x onerror=alert(1)> & Demodaten';let result=window.KulturhusDialog.confirm(text,{accept:'Entfernen',danger:true});check(!modal().querySelector('img')&&modal().textContent.includes(text),'Nachricht wird ausschließlich als Text angezeigt');click('Abbrechen');check(await result===false,'Direkte Bestätigung liefert false beim Abbruch');
      result=window.KulturhusDialog.confirm('Fortfahren?',{accept:'Fortfahren'});const duplicate=window.KulturhusDialog.confirm('Zweite Aktion?');check(await duplicate===false,'Gleichzeitige zweite Aktion bleibt gesperrt');click('Fortfahren');check(await result===true,'Direkte Bestätigung liefert true');
      result=window.KulturhusDialog.prompt('Name eingeben','Erika');check(modal().querySelector('input').value==='Erika','Eingabedialog übernimmt Vorgabe');click('Abbrechen');check(await result===null,'Eingabeabbruch liefert null');
      result=window.KulturhusDialog.prompt('Leere Angabe erlaubt','Erika');modal().querySelector('input').value='';click('Übernehmen');check(await result==='','Leere Eingabe bleibt von Abbruch unterscheidbar');
      result=window.KulturhusDialog.alert('Nur ein Hinweis');check(modal().querySelectorAll('button').length===1,'Hinweis hat genau eine Schließen-Aktion');click('Schließen');await result;
      const rich=document.createElement('div');rich.contentEditable='true';rich.textContent='Beispieltext';fixtures.append(rich);rich.focus();const range=document.createRange();range.selectNodeContents(rich);const selection=getSelection();selection.removeAllRanges();selection.addRange(range);result=window.KulturhusDialog.prompt('Linkadresse','https://');click('Übernehmen');await result;check(selection.toString()==='Beispieltext','Textmarkierung für den Editor bleibt erhalten');rich.remove();
      check(nativeCalls===0,'Kein confirm, prompt oder alert des Browsers');check(!document.querySelector('dialog.kh-dialog'),'Dialoge nach Abschluss entfernt');
      output.textContent+='BESTANDEN: '+count+' Prüfungen. Keine echten Daten verändert.';
    }catch(error){output.textContent+='FEHLER: '+error.message;}
    finally{for(const [key,value] of Object.entries(old))window[key]=value;fixtures.replaceChildren();document.getElementById('run').disabled=false;}
  });
})();
