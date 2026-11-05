/* Optional text selection helpers; reviewing and accepting always use the native form. */
(() => {
  window.KulturhusPage?.register('tariff-native', page => {
  const root = page.root.querySelector('[data-native-tariffs]');
  if (!root) return;
  for (const field of root.querySelectorAll('[data-tariff-rich]')) {
    const input = field.querySelector('textarea');
    const toolbar = field.querySelector('[data-tariff-toolbar]');
    if (!input || !toolbar) continue;
    toolbar.hidden = false;
    page.on(toolbar, 'click', event => {
      const button = event.target.closest('button');
      if (!button) return;
      const start = input.selectionStart, end = input.selectionEnd;
      let text;
      if (button.dataset.tariffFormat) {
        const marker = button.dataset.tariffFormat;
        text = marker + (input.value.slice(start, end) || button.dataset.emptySelection) + marker;
      } else {
        const variable = toolbar.querySelector('select').value;
        if (!variable) return;
        text = '{{' + variable + '}}';
      }
      input.setRangeText(text, start, end, 'end');
      input.focus();
      input.dispatchEvent(new Event('input', {bubbles: true}));
    });
  }
  });
})();
