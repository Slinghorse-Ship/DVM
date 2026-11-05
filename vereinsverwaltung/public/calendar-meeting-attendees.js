window.KulturhusPage.register('calendar-meeting-attendees', pageContext => {
'use strict';
for(const panel of document.querySelectorAll('[data-meeting-attendees]')){
 const form=panel.closest('form'),refresh=panel.querySelector('[data-meeting-refresh]');
 const count=()=>{const selected=new Set(JSON.parse(form.elements.calendar_selected.value)),visible=JSON.parse(form.elements.calendar_visible.value);for(const id of visible)selected.delete(id);for(const input of panel.querySelectorAll('[name="attendees[]"]:checked'))selected.add(input.value);panel.querySelector('[data-meeting-count]').textContent=(window.KulturhusInterface?.format?.(selected.size===1?'{selected} Person ausgewählt · {total} Suchtreffer':'{selected} Personen ausgewählt · {total} Suchtreffer',{selected:selected.size,total:panel.dataset.meetingTotal})??(selected.size+' '+(selected.size===1?'Person':'Personen')+' ausgewählt · '+panel.dataset.meetingTotal+' Suchtreffer'));};
 for(const input of panel.querySelectorAll('[name="attendees[]"]'))input.addEventListener('change',count);
 const titles={assembly:'Mitgliederversammlung',board:'Vorstandsversammlung'};
 for(const radio of form.querySelectorAll('[name=activity_kind]'))radio.addEventListener('change',()=>{
  const title=form.querySelector('[name=title]'),previous=panel.dataset.meetingInitial;
  if(title&&(title.value.trim()===''||title.value===titles[previous]))title.value=titles[radio.value]||'';
  form.requestSubmit(refresh);
 });
}
});
