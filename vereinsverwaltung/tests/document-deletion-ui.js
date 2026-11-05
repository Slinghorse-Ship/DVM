'use strict';
// Static, synthetic view verification only; no network or persistent writes.
document.addEventListener('submit',event=>{
  event.preventDefault();
  document.getElementById('result').textContent='Bestätigt: '+event.submitter.textContent.trim()+'. Nur Ansicht geprüft; keine Daten geändert.';
});
