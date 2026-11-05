import fs from 'node:fs';
import vm from 'node:vm';
import path from 'node:path';
import {fileURLToPath,pathToFileURL} from 'node:url';
import {createRequire} from 'node:module';
import zlib from 'node:zlib';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../../resources/photo-import');
const [kind,input,output]=process.argv.slice(2);if(!['heif','raw'].includes(kind)||!input||!output)throw Error('Invalid camera conversion');
const stat=fs.lstatSync(input);if(!stat.isFile()||stat.isSymbolicLink()||stat.size<8||stat.size>256*1024*1024)throw Error('Invalid camera source');const bytes=fs.readFileSync(input);
let pixels;
if(kind==='heif'){
 const factory=createRequire(import.meta.url)(path.join(root,'photo-libheif.js'));const heif=await(typeof factory==='function'?factory():factory);const images=new heif.HeifDecoder().decode(bytes);if(!images?.length||images.length>8)throw Error('Invalid HEIC');
 try{const image=images.find(i=>i.is_primary?.())??images[0];const width=image.get_width(),height=image.get_height();if(!Number.isInteger(width)||!Number.isInteger(height)||width<1||height<1||width*height>120000000)throw Error('Camera pixel limit');pixels={width,height,colors:4,data:new Uint8ClampedArray(width*height*4)};await new Promise((resolve,reject)=>image.display(pixels,data=>data?resolve():reject(Error('HEIC decode failed'))));}finally{for(const image of images)image.free?.();}
}else{
 let resolve,reject;const context=vm.createContext({URL,TextDecoder,TextEncoder,WebAssembly,Response,console:{log(){},error(){}},setTimeout,clearTimeout,WorkerGlobalScope:function(){},fetch:async url=>{const u=new URL(url);if(u.href!==pathToFileURL(path.join(root,'photo-libraw.wasm')).href)throw Error('Unknown codec');return new Response(fs.readFileSync(path.join(root,'photo-libraw.wasm')),{headers:{'Content-Type':'application/wasm'}});}});context.self=context;context.postMessage=message=>message.error?reject(Error(message.error)):resolve(message.out);
 const worker=fs.readFileSync(path.join(root,'photo-libraw-worker.js'),'utf8').replaceAll('import.meta.url',JSON.stringify(pathToFileURL(path.join(root,'photo-libraw-worker.js')).href));vm.runInContext(worker,context,{timeout:10000});
 const call=(fn,args=[])=>new Promise((yes,no)=>{resolve=yes;reject=no;context.onmessage({data:{id:1,fn,args}});});
 await call('open',[new Uint8Array(bytes),{halfSize:true,useCameraWb:true,outputBps:8,outputColor:1}]);const meta=await call('metadata');if(!meta||!Number.isInteger(meta.raw_width)||!Number.isInteger(meta.raw_height)||meta.raw_width<1||meta.raw_height<1||meta.raw_width*meta.raw_height>120000000)throw Error('RAW pixel limit');pixels=await call('imageData');if(pixels.bits!==8||pixels.colors!==3)throw Error('Unknown RAW pixels');
}
const {width,height,colors,data}=pixels;if(!Number.isInteger(width)||!Number.isInteger(height)||width<1||height<1||width*height>120000000||data.length!==width*height*colors)throw Error('Invalid decoded pixels');
const ratio=Math.min(1,1600/Math.max(width,height)),w=Math.max(1,Math.floor(width*ratio)),h=Math.max(1,Math.floor(height*ratio));const scan=Buffer.alloc((w*3+1)*h);
for(let y=0;y<h;y++)for(let x=0;x<w;x++){const source=(Math.min(height-1,Math.floor(y/ratio))*width+Math.min(width-1,Math.floor(x/ratio)))*colors,target=y*(w*3+1)+1+x*3,alpha=colors===4?data[source+3]/255:1;for(let c=0;c<3;c++)scan[target+c]=Math.round(data[source+c]*alpha+255*(1-alpha));}
function crc(bytes){let crc=0xffffffff;for(const byte of bytes){crc^=byte;for(let bit=0;bit<8;bit++)crc=(crc>>>1)^((crc&1)?0xedb88320:0);}return(crc^0xffffffff)>>>0;}
function chunk(type,data){const name=Buffer.from(type),size=Buffer.alloc(4),sum=Buffer.alloc(4);size.writeUInt32BE(data.length);sum.writeUInt32BE(crc(Buffer.concat([name,data])));return Buffer.concat([size,name,data,sum]);}
const header=Buffer.alloc(13);header.writeUInt32BE(w);header.writeUInt32BE(h,4);header[8]=8;header[9]=2;const png=Buffer.concat([Buffer.from([137,80,78,71,13,10,26,10]),chunk('IHDR',header),chunk('IDAT',zlib.deflateSync(scan)),chunk('IEND',Buffer.alloc(0))]);if(png.length>12*1024*1024)throw Error('Camera output limit');fs.writeFileSync(output,png,{flag:'wx',mode:0o600});
