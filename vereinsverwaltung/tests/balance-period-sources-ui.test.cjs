'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../public/balance-period-sources.js'), 'utf8');
let checks = 0;
const check = (value, expected) => { assert.equal(value, expected); checks++; };
function fixture(loading = false, present = true, hasResult = true) {
  const controls = Array.from({length: 4}, () => ({disabled: false}));
  const result = {hidden: false, querySelectorAll: () => controls};
  const notice = {hidden: true};
  const listeners = {}, ready = {};
  const section = {dataset: {}, querySelector: key => key === '[data-period-source-result]' ? (hasResult ? result : null) : notice,
    addEventListener: (type, handler) => (listeners[type] ??= []).push(handler)};
  const document = {readyState: loading ? 'loading' : 'complete', getElementById: () => present ? section : null,
    addEventListener: (type, handler) => ready[type] = handler};
  return {document, section, result, notice, controls, listeners, ready};
}
for (const type of ['input', 'change']) {
  const f = fixture(); const context = vm.createContext({document: f.document});
  vm.runInContext(source, context); vm.runInContext(source, context);
  check(f.listeners[type].length, 1); // Reinitialisation cannot add duplicate handlers.
  const fire = method => f.listeners[type][0]({target: {closest: () => method === 'get' ? {} : null}});
  fire('post'); check(f.result.hidden, false); check(f.notice.hidden, true);
  fire('get'); check(f.result.hidden, true); check(f.notice.hidden, false);
  check(f.controls.every(control => control.disabled), true);
}
const loading = fixture(true); vm.runInNewContext(source, {document: loading.document});
check(loading.section.dataset.sourceReady, undefined); loading.ready.DOMContentLoaded();
check(loading.section.dataset.sourceReady, '1');
for (const f of [fixture(false, false), fixture(false, true, false)]) {
  vm.runInNewContext(source, {document: f.document}); check(Object.keys(f.listeners).length, 0);
}
console.log(`${checks} dated-source UI checks passed; no browser, network or database writes`);
