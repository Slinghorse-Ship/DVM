'use strict';
const cart = new Map();
const euro = cents => (cents / 100).toLocaleString('de-DE', {style: 'currency', currency: 'EUR'});
function renderCart() {
  const container = document.querySelector('#pos-cart');
  if (!container) return;
  container.replaceChildren(); let total = 0;
  for (const [id, line] of cart) {
    const row = document.createElement('div'); row.className = 'pos-cart-row';
    const label = document.createElement('span'); label.textContent = `${line.quantity} × ${line.label}`;
    const amount = document.createElement('strong'); amount.textContent = euro(line.quantity * line.price); total += line.quantity * line.price;
    const remove = document.createElement('button'); remove.type = 'button'; remove.textContent = '−'; remove.setAttribute('aria-label', `Ein ${line.label} entfernen`);
    remove.addEventListener('click', () => {if (--line.quantity === 0) cart.delete(id); renderCart();});
    row.append(label, amount, remove); container.append(row);
  }
  const sum = document.createElement('p'); sum.textContent = `Summe ${euro(total)}`; container.append(sum);
  document.querySelector('#pos-lines').value = JSON.stringify([...cart].map(([id, line]) => ({id, quantity: line.quantity})));
  document.querySelector('#pos-save-cart').disabled = cart.size === 0;
}
document.querySelectorAll('[data-product]').forEach(button => {button.addEventListener('click', () => {
  const id = button.dataset.product;
  const line = cart.get(id) || {label: button.dataset.label, price: Number(button.dataset.price), quantity: 0};
  if (line.quantity < 100) {line.quantity++; cart.set(id, line); renderCart();}
});button.disabled=false;});
document.querySelector('[data-print]')?.addEventListener('click', () => window.print());
document.querySelectorAll('[data-pos-received]').forEach(input => {
  const output = input.form.querySelector('[data-pos-change]');
  const update = () => {
    const raw = input.value.trim().replace(',', '.');
    const cents = /^\d+(?:\.\d{1,2})?$/.test(raw) ? Math.round(Number(raw) * 100) : NaN;
    const total = Number(input.dataset.posTotal);
    output.textContent = Number.isFinite(cents) ? (cents >= total ? `Rückgeld: ${euro(cents - total)}` : `Noch offen: ${euro(total - cents)}`) : 'Bitte den erhaltenen Betrag eingeben.';
  };
  input.addEventListener('input', update);update();
});
document.querySelectorAll('[data-native-payment]').forEach(button => button.addEventListener('click', async () => {
  if(window.DVMNative?.platform==='android' && button.dataset.nativeMethod==='sumup-tap'){document.querySelector('[data-native-notice]').textContent='Tap to Pay ist auf diesem Gerät nicht verfügbar. Kartenleser oder anderen Zahlungsweg wählen.';return;}
  if(window.DVMNative?.payments && typeof window.DVMNative.pay==='function'){
    button.disabled=true;try{const accepted=await window.DVMNative.pay({attempt:button.dataset.nativePayment,csrf:button.dataset.csrf});if(!accepted)document.querySelector('[data-native-notice]').textContent='Zahlungsversuch nicht gestartet. Gespeicherten Stand prüfen.';}catch{document.querySelector('[data-native-notice]').textContent='Zahlungsversuch nicht bestätigt. Gespeicherten Stand prüfen.';}finally{button.disabled=false;}return;
  }
  const handler = window.webkit?.messageHandlers?.kulturhusPayment;
  if (!handler) {document.querySelector('[data-native-notice]').textContent = 'Kartenzahlung am Gerät nicht eingerichtet. Anderen Zahlungsweg wählen.'; return;}
  button.disabled = true;
  handler.postMessage({attempt: button.dataset.nativePayment, csrf: button.dataset.csrf});
}));
