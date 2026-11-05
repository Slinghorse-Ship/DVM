window.KulturhusPage.register('website-navigation', pageContext => {
function initEditorialwebsite_navigation(){
'use strict';
document.querySelectorAll('[data-website-menu]').forEach(editor => {
  const list = editor.querySelector('[data-menu-rows]');
  const automatic = editor.querySelector('[data-menu-auto]');
  const program = editor.querySelector('[data-menu-program]');
  const status = editor.querySelector('[data-menu-status]');
  const syncYear = () => {
    program.readOnly = automatic.checked;
    if (automatic.checked) program.value = program.dataset.autoLabel;
  };
  const updateButtons = () => {
    const rows = [...list.children];
    rows.forEach((row, index) => {
      row.querySelector('[data-menu-move="up"]').disabled = index === 0;
      row.querySelector('[data-menu-move="down"]').disabled = index === rows.length - 1;
    });
  };
  list.addEventListener('click', event => {
    const button = event.target.closest('[data-menu-move]');
    if (!button || editor.disabled) return;
    const row = button.closest('[data-menu-row]');
    const up = button.dataset.menuMove === 'up';
    const neighbor = up ? row.previousElementSibling : row.nextElementSibling;
    if (!neighbor) return;
    if (up) list.insertBefore(row, neighbor); else list.insertBefore(neighbor, row);
    [...list.children].forEach((item, index) => { item.querySelector('[data-menu-position]').value = String(index + 1); });
    updateButtons();
    const label = row.querySelector('input[type="text"]');
    status.textContent = label.value + ' verschoben. Mit „Menü speichern“ übernehmen.';
    if (button.disabled) label.focus(); else button.focus();
  });
  automatic.addEventListener('change', syncYear);
  syncYear();updateButtons();
});

}
initEditorialwebsite_navigation();
});
