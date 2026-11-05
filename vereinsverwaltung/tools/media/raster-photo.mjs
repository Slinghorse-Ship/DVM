import fs from 'node:fs';
import crypto from 'node:crypto';
import {fileURLToPath} from 'node:url';
import {initializeImageMagick,ImageMagick,MagickFormat,MagickReadSettings,MagickGeometry,ResourceLimits,ConfigurationFiles} from '../../resources/raster-photo/magick.mjs';

// No network, external delegates or filename-selected image coders.
try {
  const [mime,input,output,hash,widthText,heightText,edgeText]=process.argv.slice(2);
  const formats={'image/jpeg':MagickFormat.Jpeg,'image/png':MagickFormat.Png,'image/webp':MagickFormat.WebP};
  const width=Number(widthText),height=Number(heightText),edge=Number(edgeText);
  if(process.argv.length!==9||!Object.hasOwn(formats,mime)||!hash?.match(/^[a-f0-9]{64}$/)||![width,height,edge].every(Number.isSafeInteger)||width<1||height<1||Math.max(width,height)>12000||width*height>64000000||edge<64||edge>1600)throw Error('arguments');
  const stat=fs.lstatSync(input);
  if(!stat.isFile()||stat.isSymbolicLink()||stat.size<1||stat.size>256*1024*1024||fs.existsSync(output))throw Error('source');
  const bytes=fs.readFileSync(input);
  if(crypto.createHash('sha256').update(bytes).digest('hex')!==hash)throw Error('changed source');
  const configuration=ConfigurationFiles.default;
  configuration.policy.data='<?xml version="1.0"?><policymap><policy domain="delegate" rights="none" pattern="*"/><policy domain="coder" rights="none" pattern="*"/><policy domain="coder" rights="read|write" pattern="{JPEG,PNG,WEBP}"/><policy domain="path" rights="none" pattern="@*"/></policymap>';
  await initializeImageMagick(fs.readFileSync(fileURLToPath(new URL('../../resources/raster-photo/magick.wasm',import.meta.url))),configuration);
  ResourceLimits.width=12000n;ResourceLimits.height=12000n;ResourceLimits.area=64000000n;
  ResourceLimits.memory=384n*1024n*1024n;ResourceLimits.disk=0n;
  ResourceLimits.maxMemoryRequest=256n*1024n*1024n;ResourceLimits.maxProfileSize=1024n*1024n;
  ResourceLimits.listLength=4n;ResourceLimits.time=40n;
  const settings=new MagickReadSettings({format:formats[mime],frameIndex:0,frameCount:1});
  ImageMagick.read(bytes,settings,image=>{
    if(image.width!==width||image.height!==height)throw Error('dimensions');
    image.autoOrient();
    const geometry=new MagickGeometry(edge,edge);geometry.greater=true;image.resize(geometry);
    image.strip();image.quality=88;
    image.write(formats[mime],result=>{
      if(result.length<1||result.length>12*1024*1024||Math.max(image.width,image.height)>edge)throw Error('output');
      fs.writeFileSync(output,result,{flag:'wx',mode:0o600});
    });
  });
}catch{
  console.error('Local raster preparation failed.');
  process.exitCode=1;
}
