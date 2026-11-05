// Render the existing website mark; no separate app branding or runtime dependency.
// Run with Node.js and sharp available on NODE_PATH.
const fs = require('node:fs');
const path = require('node:path');
const sharp = require('sharp');
const root = path.resolve(__dirname, '../..');
const website = path.join(root, 'vereinsverwaltung/native/Web/assets/site');
const manager = path.join(root, 'vereinsverwaltung/public');
const source = fs.readFileSync(path.join(website, 'favicon.svg'), 'utf8');
const artwork = source.slice(source.indexOf('<g '), source.lastIndexOf('</svg>'));
const canvas = scale => Buffer.from(`<svg xmlns="http://www.w3.org/2000/svg" width="512" height="512" viewBox="0 0 512 512"><rect width="512" height="512" fill="#f3eddf"/><g transform="translate(${256 * (1 - scale)} ${256 * (1 - scale)}) scale(${scale})">${artwork}</g></svg>`);
(async () => {
  fs.writeFileSync(path.join(manager, 'push-icon.svg'), source);
  for (const [size, filename] of [[180, 'apple-touch-icon.png'], [192, 'push-icon-192.png'], [512, 'push-icon-512.png']]) {
    const png = await sharp(canvas(1), { density: 192 }).resize(size, size).png().toBuffer();
    fs.writeFileSync(path.join(manager, filename), png);
    fs.writeFileSync(path.join(website, filename.replace('push-icon-', 'app-icon-')), png);
  }
  const masked = await sharp(canvas(0.74), { density: 192 }).resize(512, 512).png().toBuffer();
  for (const dir of [website, manager]) {
    fs.writeFileSync(path.join(dir, dir === manager ? 'push-icon-maskable-512.png' : 'app-icon-maskable-512.png'), masked);
    const favicon = await sharp(Buffer.from(source), { density: 192 }).resize(32, 32).png().toBuffer();
    fs.writeFileSync(path.join(dir, 'favicon-32.png'), favicon);
    // PNG-compressed ICO entries are supported by current browsers and Windows.
    const images = await Promise.all([16, 32, 48].map(size => sharp(Buffer.from(source), { density: 192 }).resize(size, size).png().toBuffer()));
    const directory = Buffer.alloc(6 + images.length * 16);
    directory.writeUInt16LE(1, 2); directory.writeUInt16LE(images.length, 4);
    let offset = directory.length;
    images.forEach((data, index) => {
      const entry = 6 + index * 16;
      directory[entry] = [16, 32, 48][index]; directory[entry + 1] = directory[entry];
      directory.writeUInt16LE(1, entry + 4); directory.writeUInt16LE(32, entry + 6);
      directory.writeUInt32LE(data.length, entry + 8); directory.writeUInt32LE(offset, entry + 12);
      offset += data.length;
    });
    fs.writeFileSync(path.join(dir, 'favicon.ico'), Buffer.concat([directory, ...images]));
  }
  console.log('Website and manager icons rendered from the existing Kulturhus mark.');
})().catch(error => { console.error(error.message); process.exitCode = 1; });
