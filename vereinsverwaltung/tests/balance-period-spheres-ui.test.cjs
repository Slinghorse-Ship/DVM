'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../public/balance-period-spheres.js'), 'utf8');
let checks = 0;
const check = (value, expected) => { assert.equal(value, expected); checks++; };
function fixture(loading = false) {
  const groups = {'[data-sphere-value]': [1], '[data-sphere-life]': [1, 2]};
  for (const key of Object.keys(groups)) {
    const inputs = groups[key].map(() => ({disabled: false, required: false, value: '123'}));
    groups[key] = {hidden: false, inputs, querySelectorAll: () => inputs};
  }
  const listeners = [];
  const choice = {value: '', addEventListener: (type, fn) => listeners.push(fn)};
  const form = {dataset: {}, querySelector: selector => selector === '[name="sphere_case"]' ? choice : groups[selector]};
  const ready = {};
  const document = {readyState: loading ? 'loading' : 'complete', querySelectorAll: () => [form], addEventListener: (type, fn) => ready[type] = fn};
  return {document, form, choice, groups, listeners, ready};
}
const f = fixture();
vm.runInNewContext(source, {document: f.document});
vm.runInNewContext(source, {document: f.document});
check(f.listeners.length, 1);
for (const kind of ['', 'exempt_to_exempt', 'taxable_value', 'exempt_book', 'exempt_value', 'taxable_return', 'invalid']) {
  f.choice.value = kind; f.listeners[0]();
  for (const [selector, shown] of [['[data-sphere-value]', ['taxable_value', 'exempt_book', 'exempt_value', 'taxable_return'].includes(kind)], ['[data-sphere-life]', kind === 'taxable_value']]) {
    const group = f.groups[selector]; check(group.hidden, !shown);
    for (const input of group.inputs) {check(input.disabled, !shown); check(input.required, shown); check(input.value, '123');}
  }
}
const delayed = fixture(true); vm.runInNewContext(source, {document: delayed.document});
check(delayed.listeners.length, 0); delayed.ready.DOMContentLoaded(); check(delayed.listeners.length, 1);
vm.runInNewContext(source, {document: {readyState: 'complete', querySelectorAll: () => []}});
console.log(`${checks} sphere-change field visibility checks passed; no browser, network or database`);
