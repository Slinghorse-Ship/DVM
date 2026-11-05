window.KulturhusPage.register('event-vouchers', pageContext => {
 const {timeout: setTimeout} = pageContext;
(() => {
  'use strict';
  document.querySelectorAll('[data-voucher-print]').forEach(button => button.addEventListener('click', () => window.print()));
  let translations = {};
  try { translations = JSON.parse(document.querySelector('[data-voucher-interface-messages]')?.dataset.voucherInterfaceMessages || '{}'); } catch {}
  const text = source => translations[source] || source;
  const filter = document.querySelector('[data-voucher-member-search]');
  const people = [...document.querySelectorAll('[data-voucher-member]')];
  const status = document.querySelector('[data-voucher-selected]');
  const update = () => {
    const term = (filter?.value || '').trim().toLocaleLowerCase('de');
    for (const person of people) person.hidden = !person.textContent.toLocaleLowerCase('de').includes(term);
    const selected = people.filter(p => p.querySelector('input').checked).length;
    if (status) status.textContent = text(selected === 1 ? '{count} Mitglied ausgewählt' : '{count} Mitglieder ausgewählt').replace('{count}', String(selected));
  };
  filter?.addEventListener('input', update);
  people.forEach(person => person.addEventListener('change', update));
  document.querySelector('[data-voucher-select-all]')?.addEventListener('click', () => {
    if (filter) filter.value = '';
    people.forEach(person => { person.querySelector('input').checked = true; });update();
  });
  document.querySelector('[data-voucher-select-none]')?.addEventListener('click', () => {
    people.forEach(person => { person.querySelector('input').checked = false; });update();
  });
  update();
  const openTarget = () => {
    const target = document.getElementById(location.hash.slice(1));
    if (target instanceof HTMLDetailsElement) target.open = true;
  };
  pageContext.on(window, 'hashchange', openTarget);openTarget();
  const searchForm = document.querySelector('[data-voucher-search-form]');
  if (!searchForm) return;
  const scanButton = searchForm.querySelector('[data-voucher-scan]');
  scanButton.hidden = false;
  const camera = searchForm.querySelector('[data-voucher-camera]');
  const video = camera.querySelector('video');
  const message = searchForm.querySelector('[data-voucher-scan-status]');
  const canvas = document.createElement('canvas');
  const context = canvas.getContext('2d', {willReadFrequently: true});
  let stream = null, run = 0, timer = null;
  const stop = () => {
    ++run;clearTimeout(timer);stream?.getTracks().forEach(track => track.stop());stream = null;
    video.srcObject = null;camera.hidden = true;scanButton.disabled = false;
  };
  pageContext.cleanup(stop);
  const validateCode = raw => {
    const match = /^KH:1:([A-F0-9]{32}):([A-F0-9]{16})$/.exec(raw.trim().toUpperCase());
    if (!match) { message.textContent = text('Das ist kein Veranstaltungsgutschein.');return null; }
    if (match[1].toLowerCase() !== searchForm.dataset.voucherEvent) { message.textContent = text('Dieser Gutschein gehört zu einer anderen Veranstaltung.');return null; }
    return match[2];
  };
  searchForm.addEventListener('submit', event => {
    const input = searchForm.querySelector('[data-voucher-native-query], [name=q]');
    if (/^KH:/i.test(input.value.trim())) { const code = validateCode(input.value);if (!code) event.preventDefault();else if (!input.hasAttribute('data-voucher-native-query')) input.value = code; }
  });
  scanButton.addEventListener('click', async () => {
    if (!navigator.mediaDevices?.getUserMedia || typeof window.jsQR !== 'function') {
      message.textContent = text('Kamera hier nicht verfügbar. Bitte den Gutscheincode eingeben oder einen angeschlossenen Scanner verwenden.');return;
    }
    const current = ++run;scanButton.disabled = true;message.textContent = text('Kamerazugriff erlauben und QR-Code vor die Kamera halten.');
    try {
      const opened = await navigator.mediaDevices.getUserMedia({audio:false,video:{facingMode:{ideal:'environment'},width:{ideal:1280},height:{ideal:720}}});
      if (current !== run) { opened.getTracks().forEach(track => track.stop());return; }
      stream = opened;camera.hidden = false;video.srcObject = stream;await video.play();
      const frame = () => {
        if (current !== run) return;
        if (video.readyState >= 2 && video.videoWidth) {
          canvas.width = Math.min(800, video.videoWidth);canvas.height = Math.round(video.videoHeight * canvas.width / video.videoWidth);
          context.drawImage(video, 0, 0, canvas.width, canvas.height);
          const pixels = context.getImageData(0, 0, canvas.width, canvas.height);
          const qr = window.jsQR(pixels.data, pixels.width, pixels.height, {inversionAttempts:'dontInvert'});
          if (qr) {
            const code = validateCode(qr.data);
            if (code) { stop();message.textContent = text('Gutschein erkannt. Bitte Einlösung prüfen.');searchForm.querySelector('[data-voucher-native-query], [name=q]').value = code;searchForm.requestSubmit();return; }
          }
        }
        timer = setTimeout(frame, 180);
      };
      frame();
    } catch (error) {
      if (current !== run) return;stop();
      message.textContent = error.name === 'NotAllowedError' ? text('Kamerazugriff nicht erlaubt. Bitte Kamera freigeben oder Gutscheincode eingeben.') : text('Kamera konnte nicht geöffnet werden. Bitte Gutscheincode eingeben.');
    }
  });
  searchForm.querySelector('[data-voucher-stop]').addEventListener('click', () => { stop();message.textContent = ''; });
  pageContext.on(window, 'pagehide', stop);
  pageContext.on(document, 'visibilitychange', () => { if (document.hidden) stop(); });
})();
});
