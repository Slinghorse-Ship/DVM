/* Local image encoding shared by editor downloads; never changes saved artwork. */
(() => {
  'use strict';
  const formats=Object.freeze({'image/png':'png','image/jpeg':'jpg','image/webp':'webp'});
  function configuration(width,height,mime,quality=.92) {
    width=Number(width);height=Number(height);
    if(!Number.isInteger(width)||!Number.isInteger(height)||width<32||height<32||width>8192||height>8192||width*height>16000000)
      throw new Error('Exportgröße prüfen: 32–8192 Pixel je Seite und höchstens 16 Megapixel.');
    if(!Object.hasOwn(formats,mime))throw new Error('PNG, JPEG oder WebP als Exportformat wählen.');
    if(!Number.isFinite(quality)||quality<=0||quality>1)throw new Error('Bildqualität prüfen.');
    return Object.freeze({width,height,mime,quality,extension:formats[mime]});
  }
  async function encode(source,options,signal) {
    const selected=configuration(options.width,options.height,options.mime,options.quality??.92);
    const active=()=>{if(signal?.aborted)throw new DOMException('Bildexport abgebrochen.','AbortError');};
    active();
    const surface=document.createElement('canvas');
    surface.width=selected.width;surface.height=selected.height;
    try {
      const context=surface.getContext('2d');
      if(!context)throw new Error('Bildexport in diesem Browser nicht verfügbar.');
      context.imageSmoothingEnabled=true;context.imageSmoothingQuality='high';
      context.drawImage(source,0,0,selected.width,selected.height);
      const result=await new Promise((resolve,reject)=>{
        const abort=()=>reject(new DOMException('Bildexport abgebrochen.','AbortError'));
        signal?.addEventListener('abort',abort,{once:true});
        surface.toBlob(blob=>{signal?.removeEventListener('abort',abort);resolve(blob);},selected.mime,selected.quality);
      });
      active();
      if(!result||result.type!==selected.mime)throw new Error('Das gewählte Bildformat wird von diesem Browser nicht unterstützt.');
      if(result.size>5*1024*1024)throw new Error('Export größer als 5 MiB. Kleinere Maße wählen.');
      return result;
    } finally {surface.width=surface.height=0;}
  }
  window.KulturhusImageExport=Object.freeze({configuration,encode});
})();
