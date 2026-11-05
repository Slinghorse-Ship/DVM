window.KulturhusPage.register('website-native', pageContext => {
function initEditorialwebsite_native(){
// Native, accessible editor: no embedded document or iframe.
document.querySelectorAll('[data-native-rich]').forEach(field => {
    const surface = document.createElement('div');
    surface.className = 'native-rich';
    surface.contentEditable = 'true';
    surface.setAttribute('role', 'textbox');
    surface.setAttribute('aria-label', 'Formatierter Inhalt');
    surface.setAttribute('aria-multiline', 'true');
    surface.innerHTML = field.value;
    const toolbar = document.createElement('div');
    toolbar.className = 'native-actions';
    toolbar.setAttribute('role', 'group');
    toolbar.setAttribute('aria-label', 'Text formatieren');
    for (const [label, command, value] of [['Fett','bold'],['Kursiv','italic'],['Überschrift','formatBlock','h2'],['Absatz','formatBlock','p'],['Liste','insertUnorderedList'],['Nummerierung','insertOrderedList'],['Link','createLink'],['Bild','insertImage']]) {
        const button = document.createElement('button');
        button.type = 'button'; button.className = 'secondary'; button.textContent = label;
        button.addEventListener('mousedown', event => event.preventDefault());
        button.addEventListener('click', async () => {
            let argument = value;
            if (command === 'createLink' || command === 'insertImage') {
                argument = await window.KulturhusDialog.prompt('Adresse (https:// oder /site/…)');
                if (!argument || !/^(https?:\/\/|\/(?!\/))/.test(argument)) return;
            }
            surface.focus(); document.execCommand(command, false, argument);
            field.value = surface.innerHTML;
        });
        toolbar.append(button);
    }
    const toggle = document.createElement('button');
    toggle.type = 'button'; toggle.className = 'secondary'; toggle.textContent = 'HTML bearbeiten';
    toggle.addEventListener('click', () => {
        if (field.hidden) { field.value = surface.innerHTML; field.hidden = false; surface.hidden = true; toggle.textContent = 'Formatierte Ansicht'; }
        else { surface.innerHTML = field.value; surface.hidden = false; field.hidden = true; toggle.textContent = 'HTML bearbeiten'; }
    });
    toolbar.append(toggle); field.hidden = true; field.after(toolbar, surface);
    surface.addEventListener('input', () => { field.value = surface.innerHTML; });
    field.form.addEventListener('submit', () => { if (field.hidden) field.value = surface.innerHTML; });
});

}
initEditorialwebsite_native();
});
