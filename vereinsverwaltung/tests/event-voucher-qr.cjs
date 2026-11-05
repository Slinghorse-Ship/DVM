// Pure local synthetic encode/decode interoperability, with the production decoder.
const assert = require('node:assert/strict');
const {spawnSync} = require('node:child_process');
const path = require('node:path');
const {PNG} = require('pngjs');
const jsQR = require('../public/voucher-jsqr-1.4.0.js');
const fs = require('node:fs');
const root = path.resolve(__dirname, '../..');
for (const code of ['0123456789ABCDEF','FFFFFFFFFFFFFFFF','CAFEBABE12345678']) {
  const payload = 'KH:1:' + 'A'.repeat(32) + ':' + code;
  const php = `require 'vereinsverwaltung/src/Events/EventVoucherQr.php';echo Kulturhus\\Verein\\EventVoucherQr::png(str_repeat('a',32),'${code}');`;
  const run = spawnSync(path.join(root,'.local/php-8.5.10/php.exe'), ['-r',php], {cwd:root});
  assert.equal(run.status,0,run.stderr.toString());
  const png = PNG.sync.read(run.stdout);
  const result = jsQR(new Uint8ClampedArray(png.data),png.width,png.height);
  assert.equal(result?.data,payload);
  console.log('PASS PHP PNG decoded by production scanner: '+code);
}
const rendered = path.join(root,'.local/event-voucher-qa/business-card-test-1.png');
if (fs.existsSync(rendered)) {
  const page = PNG.sync.read(fs.readFileSync(rendered));
  for (let card=0;card<10;card++) {
    const left=Math.floor((17.5+(card%2)*90+52)*page.width/210);
    const top=Math.floor((30+Math.floor(card/2)*55)*page.height/297);
    const width=Math.ceil(29*page.width/210),height=Math.ceil(29*page.height/297);
    const crop=Buffer.alloc(width*height*4);
    for(let y=0;y<height;y++)page.data.copy(crop,y*width*4,((top+y)*page.width+left)*4,((top+y)*page.width+left+width)*4);
    assert.equal(jsQR(new Uint8ClampedArray(crop),width,height)?.data,'KH:1:'+'A'.repeat(32)+':0123456789ABCDEF');
  }
  console.log('PASS all 10 QR codes decode from rendered 85x55mm PDF cards');
}
