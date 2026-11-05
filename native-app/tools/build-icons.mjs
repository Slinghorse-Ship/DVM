import {readFile,writeFile,mkdir} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
import {deflateSync} from 'node:zlib';
function chunk(type,data){
 const tag=Buffer.from(type),payload=Buffer.concat([tag,data]);let crc=0xffffffff;for(const byte of payload){crc^=byte;for(let bit=0;bit<8;bit++)crc=(crc>>>1)^((crc&1)?0xedb88320:0);}const size=Buffer.alloc(4),checksum=Buffer.alloc(4);size.writeUInt32BE(data.length);checksum.writeUInt32BE((crc^0xffffffff)>>>0);return Buffer.concat([size,payload,checksum]);
}
function rgbPNG(encoded){const rgba=Buffer.from(encoded,'base64'),rows=Buffer.alloc(1024*(1+1024*3));for(let y=0;y<1024;y++)for(let x=0;x<1024;x++){const input=(y*1024+x)*4,output=y*(1+1024*3)+1+x*3;if(rgba[input+3]!==255)throw new Error('App icon must be opaque');rgba.copy(rows,output,input,input+3);}const header=Buffer.alloc(13);header.writeUInt32BE(1024,0);header.writeUInt32BE(1024,4);header[8]=8;header[9]=2;return Buffer.concat([Buffer.from([137,80,78,71,13,10,26,10]),chunk('IHDR',header),chunk('IDAT',deflateSync(rows)),chunk('IEND',Buffer.alloc(0))]);}
const root=fileURLToPath(new URL('../../',import.meta.url));
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const svg=await readFile(root+'vereinsverwaltung/public/dvm-icon.svg','utf8');
const browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE||'/usr/bin/chromium',args:['--no-sandbox']});
try{
 const page=await browser.newPage();
 const png=await page.evaluate(async svg=>{
  const image=new Image();image.src='data:image/svg+xml;charset=utf-8,'+encodeURIComponent(svg);await image.decode();
  const canvas=document.createElement('canvas');canvas.width=canvas.height=1024;const context=canvas.getContext('2d',{alpha:false});
  context.fillStyle='#102a4c';context.fillRect(0,0,1024,1024);context.drawImage(image,0,0,1024,1024);const pixels=context.getImageData(0,0,1024,1024).data;let binary='';for(let start=0;start<pixels.length;start+=32768)binary+=String.fromCharCode(...pixels.subarray(start,start+32768));return btoa(binary);
 },svg);
 const target=root+'native-app/ios/DVM/Assets.xcassets/AppIcon.appiconset/';await mkdir(target,{recursive:true});await writeFile(target+'AppIcon.png',rgbPNG(png));
 await writeFile(target+'Contents.json',JSON.stringify({images:[{filename:'AppIcon.png',idiom:'universal',platform:'ios',size:'1024x1024'}],info:{author:'xcode',version:1}},null,2)+'\n');
 await writeFile(root+'native-app/ios/DVM/Assets.xcassets/Contents.json',JSON.stringify({info:{author:'xcode',version:1}},null,2)+'\n');
 console.log('DVM app icon rendered from the existing product artwork: 1024×1024, opaque background.');
}finally{await browser.close();}
