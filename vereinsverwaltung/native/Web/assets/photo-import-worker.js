"use strict";
const MAX_PIXELS = 120000000;
const dimensions = (width, height) => {
  if (
    !Number.isInteger(width) ||
    !Number.isInteger(height) ||
    width < 1 ||
    height < 1 ||
    width > 30000 ||
    height > 30000 ||
    width * height > MAX_PIXELS
  )
    throw Error("Bild ist für die Verarbeitung zu groß.");
};
let heif;
async function heifPixels(buffer) {
  if (!heif) {
    importScripts(new URL("photo-libheif.js", self.location.href).href);
    heif = await (typeof self.libheif === "function"
      ? self.libheif()
      : self.libheif);
  }
  const decoder = new heif.HeifDecoder(),
    images = decoder.decode(new Uint8Array(buffer));
  if (!images?.length) throw Error("HEIC-Datei konnte nicht gelesen werden.");
  try {
    const image =
        images.find((candidate) => candidate.is_primary?.()) || images[0],
      width = image.get_width(),
      height = image.get_height();
    dimensions(width, height);
    const pixels = {
      width,
      height,
      data: new Uint8ClampedArray(width * height * 4),
    };
    await new Promise((resolve, reject) =>
      image.display(pixels, (data) =>
        data
          ? resolve()
          : reject(Error("HEIC-Datei konnte nicht umgewandelt werden.")),
      ),
    );
    return pixels;
  } finally {
    for (const image of images) image.free?.();
  }
}
async function jpeg(pixels, maxSide, maxBytes) {
  dimensions(pixels.width, pixels.height);
  const source = new OffscreenCanvas(pixels.width, pixels.height),
    sourceContext = source.getContext("2d", { alpha: false });
  if (!sourceContext)
    throw Error("Bildverarbeitung ist in diesem Browser nicht verfügbar.");
  sourceContext.putImageData(
    new ImageData(pixels.data, pixels.width, pixels.height),
    0,
    0,
  );
  let side = maxSide,
    quality = 0.92;
  for (let attempt = 0; attempt < 10; attempt++) {
    const scale = Math.min(1, side / Math.max(source.width, source.height)),
      width = Math.max(1, Math.round(source.width * scale)),
      height = Math.max(1, Math.round(source.height * scale));
    const canvas = new OffscreenCanvas(width, height),
      context = canvas.getContext("2d", { alpha: false });
    context.fillStyle = "#fff";
    context.fillRect(0, 0, width, height);
    context.drawImage(source, 0, 0, width, height);
    const blob = await canvas.convertToBlob({ type: "image/jpeg", quality });
    canvas.width = canvas.height = 1;
    if (blob.size <= maxBytes) {
      source.width = source.height = 1;
      return {
        buffer: await blob.arrayBuffer(),
        width,
        height,
        mime: "image/jpeg",
      };
    }
    if (quality > 0.76) quality -= 0.06;
    else side = Math.floor(side * 0.82);
  }
  throw Error("Bild konnte nicht in einer passenden Größe gespeichert werden.");
}
async function raster(buffer,mime,maxSide,maxBytes){
  if(!['image/jpeg','image/png','image/webp'].includes(mime))throw Error('Bildformat prüfen.');
  const image=await createImageBitmap(new Blob([buffer],{type:mime}),{imageOrientation:'from-image'});
  try{
    dimensions(image.width,image.height);
    if(Math.max(image.width,image.height)<=maxSide&&buffer.byteLength<=maxBytes)return {unchanged:true};
    let side=maxSide,quality=.92;
    for(let attempt=0;attempt<10;attempt++){
      const scale=Math.min(1,side/Math.max(image.width,image.height));
      const width=Math.max(1,Math.round(image.width*scale)),height=Math.max(1,Math.round(image.height*scale));
      const canvas=new OffscreenCanvas(width,height),context=canvas.getContext('2d',{alpha:mime!=='image/jpeg'});
      if(!context)throw Error('Bildverarbeitung ist in diesem Browser nicht verfügbar.');
      try{
        if(mime==='image/jpeg'){context.fillStyle='#fff';context.fillRect(0,0,width,height);}
        context.drawImage(image,0,0,width,height);
        const blob=await canvas.convertToBlob({type:mime,quality});
        if(!['image/jpeg','image/png','image/webp'].includes(blob.type))throw Error('Bildformat konnte nicht gespeichert werden.');
        if(mime!=='image/jpeg'&&blob.type==='image/jpeg')throw Error('Transparenz konnte nicht erhalten werden.');
        if(blob.size<=maxBytes)return {buffer:await blob.arrayBuffer(),width,height,mime:blob.type};
      }finally{canvas.width=canvas.height=1;}
      if(mime!=='image/png'&&quality>.76)quality-=.06;else side=Math.floor(side*.82);
    }
    throw Error('Bild konnte nicht in einer passenden Größe gespeichert werden.');
  }finally{image.close();}
}
self.onmessage = async ({ data }) => {
  try {
    const {
      id,
      kind,
      buffer,
      width,
      height,
      orientation = 1,
      maxSide = 2400,
      maxBytes = 1700000,
      mime,
    } = data;
    if (
      !Number.isInteger(id) ||
      !["heif", "rgb", "jpeg", "raster"].includes(kind) ||
      !(buffer instanceof ArrayBuffer) ||
      !Number.isInteger(maxSide) ||
      maxSide < 320 ||
      maxSide > 3200 ||
      !Number.isInteger(maxBytes) ||
      maxBytes < 100000 ||
      maxBytes > 1700000
    )
      throw Error("Bildvorgang prüfen.");
    if(kind==='raster'){
      const result=await raster(buffer,mime,maxSide,maxBytes);
      self.postMessage({id,result},result.buffer?[result.buffer]:[]);return;
    }
    let pixels;
    if (kind === "heif") pixels = await heifPixels(buffer);
    else if (kind === "jpeg") {
      dimensions(width, height);
      if (
        width * height > 16000000 ||
        !Number.isInteger(orientation) ||
        orientation < 1 ||
        orientation > 8
      )
        throw Error("Fotovorschau prüfen.");
      const image = await createImageBitmap(
        new Blob([buffer], { type: "image/jpeg" }),
        { imageOrientation: "none" },
      );
      try {
        if (image.width !== width || image.height !== height)
          throw Error("Fotovorschau-Abmessungen prüfen.");
        const rotated = orientation >= 5,
          canvas = new OffscreenCanvas(
            rotated ? height : width,
            rotated ? width : height,
          ),
          context = canvas.getContext("2d");
        const transforms = [
          [1, 0, 0, 1, 0, 0],
          [-1, 0, 0, 1, width, 0],
          [-1, 0, 0, -1, width, height],
          [1, 0, 0, -1, 0, height],
          [0, 1, 1, 0, 0, 0],
          [0, 1, -1, 0, height, 0],
          [0, -1, -1, 0, height, width],
          [0, -1, 1, 0, 0, width],
        ];
        context.setTransform(...transforms[orientation - 1]);
        context.drawImage(image, 0, 0);
        pixels = {
          width: canvas.width,
          height: canvas.height,
          data: context.getImageData(0, 0, canvas.width, canvas.height).data,
        };
        canvas.width = canvas.height = 1;
      } finally {
        image.close();
      }
    } else {
      dimensions(width, height);
      if (buffer.byteLength !== width * height * 3)
        throw Error("RAW-Bilddaten konnten nicht gelesen werden.");
      const rgb = new Uint8Array(buffer),
        rgba = new Uint8ClampedArray(width * height * 4);
      for (
        let source = 0, target = 0;
        source < rgb.length;
        source += 3, target += 4
      ) {
        rgba[target] = rgb[source];
        rgba[target + 1] = rgb[source + 1];
        rgba[target + 2] = rgb[source + 2];
        rgba[target + 3] = 255;
      }
      pixels = { width, height, data: rgba };
    }
    const result = await jpeg(pixels, maxSide, maxBytes);
    self.postMessage({ id, result }, [result.buffer]);
  } catch (error) {
    self.postMessage({
      id: data?.id,
      error: error.message || "Bild konnte nicht umgewandelt werden.",
    });
  }
};
