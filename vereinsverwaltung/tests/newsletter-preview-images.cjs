const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../public/newsletter-builder.js'), 'utf8');
const marker = "  dashboard.querySelectorAll('[data-newsletter-editor]')";
assert.equal(source.split(marker).length, 2);
const sandbox = {URL, document: {querySelectorAll: () => [],addEventListener:()=>{}}};
vm.runInNewContext(source.replace(marker, '  globalThis.previewImageUrl = previewImageUrl;\n' + marker), sandbox);
const preview = sandbox.previewImageUrl;
const page = 'https://www.kulturhus.de/vereinsverwaltung/?view=website&tab=newsletter';
let count = 0;
const check = (input, expected, where = page) => {assert.equal(preview(input, where), expected, input); ++count;};
check('https://kulturhus.de/site/assets/files/1027/event.jpg', '/site/assets/files/1027/event.jpg');
check('https://www.kulturhus.de/site/assets/files/1027/event.jpg?v=2#photo', '/site/assets/files/1027/event.jpg?v=2#photo');
check('https://WWW.KULTURHUS.DE/site/assets/files/a.jpg', '/site/assets/files/a.jpg', 'https://kulturhus.de/vereinsverwaltung/');
check('https://verein.example/site/assets/files/a.jpg', '/site/assets/files/a.jpg', 'https://www.verein.example/manager/');
for (const input of ['/site/assets/files/a.jpg', '//kulturhus.de/site/assets/files/a.jpg',
  'https://evil.example/site/assets/files/a.jpg', 'https://kulturhus.de.evil.example/site/assets/files/a.jpg',
  'https://cdn.kulturhus.de/site/assets/files/a.jpg', 'http://kulturhus.de/site/assets/files/a.jpg',
  'https://kulturhus.de:8443/site/assets/files/a.jpg', 'https://user:preview@example.invalid/site/assets/files/a.jpg',
  'https://kulturhus.de/vereinsverwaltung/?document_file=secret', 'https://kulturhus.de/site/assets/files/../../../config.php',
  'https://kulturhus.de/site/assets/files-not/a.jpg', 'data:image/png;base64,a', 'javascript:alert(1)', '']) check(input, input);
const absolute = 'https://kulturhus.de/site/assets/files/a.jpg';
check(absolute, absolute, 'http://127.0.0.1:8092/vereinsverwaltung/');
check(absolute, absolute, 'https://kulturhus.de:8443/vereinsverwaltung/');
check(absolute, absolute, 'https://other.example/vereinsverwaltung/');
assert.ok(source.includes('previewImageSources.get(originals[index])'));
assert.ok(source.includes('createBlock(serializeContent(content)'));
assert.ok(source.includes('content.append(fragment.content)'));
console.log(`${count} image URL checks passed; no browser/network/mail or stored content changes`);

assert.equal(preview("/site/assets/files/demo.jpg", "http://127.0.0.1:8091/vereinsverwaltung/", "http://127.0.0.1:8092/"), "http://127.0.0.1:8092/site/assets/files/demo.jpg");
assert.equal(preview("/site/assets/files/demo.jpg", "https://www.kulturhus.de/vereinsverwaltung/", "http://127.0.0.1:8092/"), "/site/assets/files/demo.jpg");
assert.equal(preview("/site/assets/files/../../../site/config.php", "http://127.0.0.1:8091/vereinsverwaltung/", "http://127.0.0.1:8092/"), "/site/assets/files/../../../site/config.php");
console.log("3 isolated preview origin checks passed");
