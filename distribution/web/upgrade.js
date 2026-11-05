(() => {
  'use strict';
  const form = document.querySelector('#upgrade-form');
  if (!form) return;
  const progress = document.querySelector('#progress'), error = document.querySelector('#error');
  const continueButton = document.querySelector('#continue'), pauseButton = document.querySelector('#pause');
  let running = false, paused = false;
  pauseButton.addEventListener('click', () => { paused = true; pauseButton.disabled = true; });
  form.addEventListener('submit', async event => {
    const action = event.submitter?.value;
    if (!action || running) { if (running) event.preventDefault(); return; }
    event.preventDefault(); running = true; paused = false; error.textContent = '';
    const buttons = [...form.querySelectorAll('button:not(#pause)')];
    buttons.forEach(button => { button.disabled = true; });
    pauseButton.hidden = action === 'status'; pauseButton.disabled = false;
    let next = action, complete = false;
    try {
      do {
        const body = new URLSearchParams(new FormData(form)); body.set('action', next);
        const response = await fetch(location.pathname, { method: 'POST', body, headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        const state = await response.json();
        if (!response.ok || state.error) throw new Error(state.error || 'Aktualisierung unterbrochen.');
        progress.textContent = state.label + (state.cursor > 0 ? ` · ${state.cursor} Dateien` : '');
        complete = state.complete;
        continueButton.value = state.status === 'ready' ? 'begin' : 'step';
        continueButton.textContent = state.status === 'ready' ? 'Aktualisieren' : 'Fortsetzen';
        if (action === 'status') break;
        next = 'step';
        if (!complete && !paused) await new Promise(resolve => setTimeout(resolve, 40));
      } while (!complete && !paused);
      if (complete) document.querySelector('#upgrade-key').value = '';
    } catch (failure) { error.textContent = failure.message || 'Verbindung unterbrochen. Stand prüfen und fortsetzen.'; }
    finally {
      running = false; pauseButton.hidden = true;
      buttons.forEach(button => { button.disabled = complete && ['begin', 'step', 'rollback'].includes(button.value); });
    }
  });
})();
