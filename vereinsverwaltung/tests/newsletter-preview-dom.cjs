// Optional test dependency: jsdom 26.1.0. No resources/transport enabled.
const {JSDOM} = require('jsdom');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const script = fs.readFileSync(path.join(__dirname, '../public/newsletter-builder.js'), 'utf8');
let checks = 0;
for (const host of ['www.kulturhus.de', 'kulturhus.de']) {
  const other = host.startsWith('www.') ? 'kulturhus.de' : 'www.kulturhus.de';
  const address = `https://${other}/site/assets/files/123/synthetic.jpg?v=2`;
  const body = `<figure class="kh-newsletter-event"><img src="${address}" alt="Synthetische Veranstaltung"><figcaption><h2>Testveranstaltung</h2><p><a href="https://${other}/veranstaltungen/test/">Informationen</a></p></figcaption></figure>`;
  const dom = new JSDOM('<form><input name="fields[subject]" value="Test"><div data-newsletter-editor><button type="button" data-nl-open-preview>Vorschau</button><div data-nl-workspace><div id="kh-newsletter-inline-toolbar"></div><div class="kh-builder-mail"><div data-nl-canvas></div></div></div><textarea data-nl-output></textarea></div></form>', {url: `https://${host}/vereinsverwaltung/`, runScripts: 'outside-only'});
  try {
    const {window} = dom; const d = window.document;
    window.fetch = () => {throw new Error('Network prohibited');};
    window.HTMLDialogElement.prototype.showModal = function() {this.open = true;};
    window.HTMLDialogElement.prototype.close = function() {this.open = false;this.dispatchEvent(new window.Event('close'));};
    d.querySelector('textarea').value = body;
    window.eval(script);
    const output = () => d.querySelector('textarea').value;
    const canvas = d.querySelector('[data-nl-canvas]');
    assert.equal(canvas.querySelector('img').getAttribute('src'), '/site/assets/files/123/synthetic.jpg?v=2');++checks;
    assert.equal(output(), body);++checks;
    assert.equal(canvas.querySelector('a').href, `https://${other}/veranstaltungen/test/`);++checks;
    d.querySelector('[data-nl-open-preview]').click();
    assert.equal(d.querySelector('dialog img').getAttribute('src'), '/site/assets/files/123/synthetic.jpg?v=2');++checks;
    assert.equal(d.querySelectorAll('dialog .kh-builder-block-controls').length, 0);++checks;
    assert.equal(output(), body);++checks;
    d.querySelector('dialog button').click();
    canvas.querySelector('[data-nl-block-action="duplicate"]').click();
    assert.equal(canvas.querySelectorAll('img').length, 2);++checks;
    assert.equal(output(), body + '\n' + body);++checks;
    canvas.querySelectorAll('[data-nl-block-action="up"]')[1].click();
    assert.equal(output(), body + '\n' + body);++checks;
    const image = canvas.querySelector('img');
    image.setAttribute('src', '/site/assets/files/123/replacement.jpg');
    image.closest('[data-nl-content]').dispatchEvent(new window.Event('input', {bubbles:true}));
    assert.ok(output().includes('src="/site/assets/files/123/replacement.jpg"'));++checks;
    assert.ok(output().includes(`src="${address}"`));++checks;
    d.querySelector('form').dispatchEvent(new window.Event('submit', {cancelable:true}));
    assert.ok(output().includes('src="/site/assets/files/123/replacement.jpg"'));++checks;
    canvas.querySelector('[data-nl-block-action="delete"]').click();
    assert.equal(output(), body);++checks;
  } finally {dom.window.close();}
}
console.log(`${checks} synthetic editor round-trip checks passed; www/non-www, preview, duplicate, reorder, edit, submit, delete; no network/mail`);
