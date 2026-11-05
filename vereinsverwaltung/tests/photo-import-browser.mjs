import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { spawn } from "node:child_process";
import { createServer } from "node:net";
import { createHash } from "node:crypto";
import { fileURLToPath } from "node:url";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const root = fileURLToPath(new URL("../", import.meta.url)),
  fixtures = root + "tests/fixtures/photo-import/";
const heic = await readFile(fixtures + "own-camera.heic"),
  dng = await readFile(fixtures + "own-camera.dng"),
  jxl = await readFile(fixtures + "own-jxl-preview.dng");
const sha = (bytes) => createHash("sha256").update(bytes).digest("hex");
const originals = [sha(heic), sha(dng)];
const free = createServer();
await new Promise((resolve) => free.listen(0, "127.0.0.1", resolve));
const port = free.address().port;
await new Promise((resolve) => free.close(resolve));
const origin = "http://127.0.0.1:" + port;
const server = spawn(
  "php",
  ["-S", "127.0.0.1:" + port, root + "tests/support/photo-import-router.php"],
  {
    cwd: root,
    env: { ...process.env, KH_NATIVE_CI: "1" },
    stdio: ["ignore", "pipe", "pipe"],
  },
);
let serverLog = "";
server.stdout.on("data", (b) => (serverLog += b));
server.stderr.on("data", (b) => (serverLog += b));
let browser,
  checks = 0;
const errors = [],
  external = [];
const check = (ok, label) => {
  assert(ok, label);
  checks++;
  console.log("PASS " + label);
};
async function session(width = 390) {
  const context = await browser.newContext({
    viewport: { width, height: 800 },
    hasTouch: true,
  });
  await context.route("**/*", (route) =>
    new URL(route.request().url()).origin === origin
      ? route.continue()
      : (external.push(route.request().url()), route.abort()),
  );
  const page = await context.newPage();
  page.on("pageerror", (e) => errors.push(e.message));
  await page.goto(origin);
  await page.waitForFunction(() => !!window.KulturhusPhotos);
  return { context, page };
}
async function api(page, name, mime, bytes) {
  return page.evaluate(
    async ({ name, mime, bytes }) => {
      const file = new File([new Uint8Array(bytes)], name, {
          type: mime,
          lastModified: 123456,
        }),
        kind = await KulturhusPhotos.format(file),
        result = await KulturhusPhotos.convert(file);
      const pixels = await createImageBitmap(result),
        canvas = document.createElement("canvas");
      canvas.width = pixels.width;
      canvas.height = pixels.height;
      const context = canvas.getContext("2d");
      context.drawImage(pixels, 0, 0);
      const color = [
        ...context.getImageData(
          Math.floor(canvas.width / 2),
          Math.floor(canvas.height / 2),
          1,
          1,
        ).data,
      ];
      pixels.close();
      return {
        kind,
        name: result.name,
        mime: result.type,
        size: result.size,
        width: canvas.width,
        height: canvas.height,
        color,
        originalSize: file.size,
        lastModified: result.lastModified,
      };
    },
    { name, mime, bytes: [...bytes] },
  );
}
try {
  for (let i = 0; i < 100; i++) {
    try {
      if ((await fetch(origin)).ok) break;
    } catch {}
    if (server.exitCode !== null)
      throw Error("Private photo server failed: " + serverLog);
    await new Promise((resolve) => setTimeout(resolve, 50));
  }
  browser = await chromium.launch(
    process.env.CHROMIUM_EXECUTABLE
      ? { executablePath: process.env.CHROMIUM_EXECUTABLE }
      : process.env.PLAYWRIGHT_MODULE
        ? { executablePath: "/usr/bin/chromium" }
        : {},
  );
  const { context, page } = await session();
  const main = await context.request.get(origin);
  check(
    !main.headers()["content-security-policy"].includes("unsafe-eval"),
    "application keeps strict script CSP",
  );
  for (const name of [
    "photo-import.js",
    "photo-import.css",
    "photo-editor.js",
    "photo-editor.css",
    "photo-import-worker.js",
    "photo-raw-preview.mjs",
    "photo-libraw.mjs",
    "photo-libraw-worker.js",
    "photo-libraw.wasm",
    "photo-libheif.js",
  ]) {
    const get = await context.request.get(origin + "/assets/" + name),
      head = await context.request.head(origin + "/assets/" + name),
      post = await context.request.post(origin + "/assets/" + name);
    check(
      get.status() === 200 &&
        head.status() === 200 &&
        Number(head.headers()["content-length"]) ===
          (await get.body()).length &&
        (await head.body()).length === 0 &&
        post.status() === 405 &&
        post.headers().allow === "GET, HEAD",
      name + " uses actual bounded GET/HEAD and rejects writes",
    );
    if (name.endsWith(".wasm"))
      check(
        get.headers()["content-type"] === "application/wasm",
        "WASM has its actual media type",
      );
    if (name.endsWith("-worker.js"))
      check(
        get.headers()["content-security-policy"].includes("worker-src 'none'"),
        "decoder worker cannot create child workers",
      );
  }
  check(
    (await context.request.get(origin + "/assets/provenance.json")).status() ===
      404 &&
      (
        await context.request.get(
          origin + "/assets/..%2F..%2Fsrc%2Fbootstrap.php",
        )
      ).status() === 404,
    "asset allowlist does not expose sources or traversed files",
  );
  for (const [name, mime, bytes, width, height, color, kind] of [
    ["Own.HEIC", "", heic, 96, 64, [35, 140, 210], "heif"],
    ["Own.jpg", "image/jpeg", heic, 96, 64, [35, 140, 210], "heif"],
    ["Own.DNG", "image/x-adobe-dng", dng, 64, 48, [255, 55, 95], "raw"],
    ["Own", "", dng, 64, 48, [255, 55, 95], "raw"],
  ]) {
    const result = await api(page, name, mime, bytes);
    check(
      result.kind === kind &&
        result.mime === "image/jpeg" &&
        result.name.endsWith(".jpg") &&
        result.width === width &&
        result.height === height &&
        result.size < 1700000 &&
        result.lastModified === 123456 &&
        result.color.slice(0, 3).every((v, i) => Math.abs(v - color[i]) <= 12),
      name +
        " is decoded to actual JPEG dimensions and original fixture colors",
    );
  }
  const fallback = await api(page, "Own-new-compression.DNG", "", jxl);
  check(
    fallback.kind === "raw" &&
      fallback.mime === "image/jpeg" &&
      fallback.width === 480 &&
      fallback.height === 640 &&
      fallback.name === "Own-new-compression-vorschau.jpg" &&
      fallback.lastModified === 123456,
    "unsupported real JPEG XL RAW automatically uses the largest declared camera preview with its exact portrait orientation",
  );
  const previewProof = await page.evaluate(
    async (bytes) => {
      const file = new File([new Uint8Array(bytes)], "Own.DNG"),
        { rawPreview } = await import("/assets/photo-raw-preview.mjs"),
        preview = await rawPreview(file);
      const converted = await KulturhusPhotos.convert(file),
        bitmap = await createImageBitmap(converted),
        canvas = document.createElement("canvas");
      canvas.width = bitmap.width;
      canvas.height = bitmap.height;
      const x = canvas.getContext("2d");
      x.drawImage(bitmap, 0, 0);
      return {
        width: preview.width,
        height: preview.height,
        orientation: preview.orientation,
        top: [...x.getImageData(240, 160, 1, 1).data],
        bottom: [...x.getImageData(240, 480, 1, 1).data],
        unchanged: [...new Uint8Array(await file.arrayBuffer())].every(
          (v, i) => v === bytes[i],
        ),
      };
    },
    [...jxl],
  );
  check(
    previewProof.width === 640 &&
      previewProof.height === 480 &&
      previewProof.orientation === 6 &&
      previewProof.top
        .slice(0, 3)
        .every((v, i) => Math.abs(v - [255, 55, 95][i]) < 12) &&
      previewProof.bottom
        .slice(0, 3)
        .every((v, i) => Math.abs(v - [45, 90, 220][i]) < 12) &&
      previewProof.unchanged,
    "fallback exports the actual declared red/blue preview pixels and keeps every original RAW byte unchanged",
  );
  const previewGuards = await page.evaluate(
    async ({ bytes, uncompressed }) => {
      const { rawPreview } = await import("/assets/photo-raw-preview.mjs");
      const outcomes = [];
      const reject = async (bytes, label, signal) => {
        try {
          await rawPreview(new File([new Uint8Array(bytes)], "Own.DNG"), {
            signal,
          });
          outcomes.push({ label, rejected: false });
        } catch (error) {
          outcomes.push({
            label,
            rejected: true,
            abort: error.name === "AbortError",
          });
        }
      };
      const data = new Uint8Array(bytes),
        root = new DataView(data.buffer),
        ifd = root.getUint32(4, true),
        n = root.getUint16(ifd, true);
      const tag = (view, offset, tag) => {
        const count = view.getUint16(offset, true);
        for (let i = 0; i < count; i++) {
          const p = offset + 2 + i * 12;
          if (view.getUint16(p, true) === tag) return p;
        }
        throw Error("Own TIFF test field missing.");
      };
      const subTag = tag(root, ifd, 330),
        array = root.getUint32(subTag + 8, true),
        large = root.getUint32(array + 4, true),
        stripTag = tag(root, large, 273),
        lengthTag = tag(root, large, 279),
        jpegOffset = root.getUint32(stripTag + 8, true),
        jpegLength = root.getUint32(lengthTag + 8, true);
      let copy = data.slice(),
        view = new DataView(copy.buffer);
      view.setUint32(stripTag + 8, copy.length + 100, true);
      await reject(copy, "out of file preview offset");
      copy = data.slice();
      view = new DataView(copy.buffer);
      view.setUint32(tag(view, large, 256) + 8, 639, true);
      await reject(
        copy,
        "declared width does not match actual JPEG; tiny thumbnail cannot replace it",
      );
      copy = data.slice();
      view = new DataView(copy.buffer);
      view.setUint16(ifd, 65535, true);
      await reject(copy, "oversized TIFF directory");
      copy = data.slice();
      view = new DataView(copy.buffer);
      view.setUint32(tag(view, ifd, 256) + 8, 30000, true);
      view.setUint32(tag(view, ifd, 257) + 8, 30000, true);
      await reject(copy, "preview cannot bypass an oversized RAW sensor");
      copy = data.slice();
      view = new DataView(copy.buffer);
      let markerOffset = jpegOffset + 2;
      while (markerOffset < jpegOffset + jpegLength) {
        if (copy[markerOffset] !== 255) throw Error("Own JPEG marker missing");
        const marker = copy[markerOffset + 1],
          length = view.getUint16(markerOffset + 2, false);
        if ([192, 193, 194].includes(marker)) {
          view.setUint16(markerOffset + 5, 10000, false);
          view.setUint16(markerOffset + 7, 10000, false);
          break;
        }
        markerOffset += 2 + length;
      }
      await reject(
        copy,
        "JPEG pixel bomb rejected before any bitmap allocation",
      );

      const embedded = data.slice(jpegOffset, jpegOffset + jpegLength);
      await reject(
        [...uncompressed, ...embedded],
        "unlinked JPEG trailer is never guessed as a camera preview",
      );
      const controller = new AbortController();
      controller.abort();
      await reject(data, "cancelled preview read", controller.signal);
      const exif = new Uint8Array([
        255, 225, 0, 34, 69, 120, 105, 102, 0, 0, 73, 73, 42, 0, 8, 0, 0, 0, 1,
        0, 18, 1, 3, 0, 1, 0, 0, 0, 6, 0, 0, 0, 0, 0, 0, 0,
      ]);
      const added = new Uint8Array(data.length + exif.length);
      added.set(data.subarray(0, jpegOffset + 2));
      added.set(exif, jpegOffset + 2);
      added.set(data.subarray(jpegOffset + 2), jpegOffset + 2 + exif.length);
      view = new DataView(added.buffer);
      view.setUint32(lengthTag + 8, jpegLength + exif.length, true);
      const p = await rawPreview(new File([added], "Own-exif.DNG"));
      outcomes.push({
        label:
          "JPEG EXIF orientation is removed before the one explicit TIFF rotation",
        rejected: p.orientation === 6 && p.buffer.byteLength === jpegLength,
      });
      const converted = await KulturhusPhotos.convert(
          new File([added], "Own-exif.DNG"),
        ),
        image = await createImageBitmap(converted);
      outcomes.push({
        label: "matching EXIF/TIFF portrait metadata is applied exactly once",
        rejected: image.width === 480 && image.height === 640,
      });
      image.close();
      return outcomes;
    },
    { bytes: [...jxl], uncompressed: [...dng] },
  );
  for (const outcome of previewGuards)
    check(
      outcome.rejected &&
        (outcome.label !== "cancelled preview read" || outcome.abort),
      outcome.label,
    );
  const jpeg = await page.evaluate(
    async (bytes) => [
      ...new Uint8Array(
        await (
          await KulturhusPhotos.convert(
            new File([new Uint8Array(bytes)], "own.heic"),
          )
        ).arrayBuffer(),
      ),
    ],
    [...heic],
  );
  const before = page.url();
  let lazy = 0;
  page.on("request", (request) => {
    if (/photo-lib(?:heif|raw)/.test(request.url())) lazy++;
  });
  const renamed = await api(page, "Own.heic", "image/heic", Buffer.from(jpeg));
  check(
    renamed.kind === "jpeg" &&
      renamed.width === 96 &&
      renamed.name === "Own.jpg" &&
      lazy === 0,
    "a JPEG carrying a HEIC filename is identified without loading a decoder",
  );
  const raster = await page.evaluate(async()=>{
    const picture=async(mime,width,height)=>{const canvas=new OffscreenCanvas(width,height),ctx=canvas.getContext('2d');ctx.fillStyle='#e03030';ctx.fillRect(0,0,width/2,height);ctx.fillStyle='#2060d0';ctx.fillRect(width/2,0,width/2,height);if(mime!=='image/jpeg'){ctx.clearRect(0,0,width,height/4);}return new File([await canvas.convertToBlob({type:mime,quality:.9})],'Own-'+width+(mime==='image/jpeg'?'.jpg':mime==='image/webp'?'.webp':'.png'),{type:mime,lastModified:123456});};
    const sample=async(file,options)=>{const old=await file.arrayBuffer(),result=await KulturhusPhotos.convert(file,options),bitmap=await createImageBitmap(result),canvas=new OffscreenCanvas(bitmap.width,bitmap.height),ctx=canvas.getContext('2d');ctx.drawImage(bitmap,0,0);bitmap.close();const pixel=(x,y)=>[...ctx.getImageData(x,y,1,1).data];return{name:result.name,type:result.type,size:result.size,width:canvas.width,height:canvas.height,top:pixel(Math.floor(canvas.width/4),0),left:pixel(Math.floor(canvas.width/4),Math.floor(canvas.height/2)),right:pixel(Math.floor(3*canvas.width/4),Math.floor(canvas.height/2)),sameObject:file===result,sameBytes:await (async()=>{const now=new Uint8Array(await result.arrayBuffer()),before=new Uint8Array(old);return before.length===now.length&&before.every((b,i)=>b===now[i]);})(),lastModified:result.lastModified};};
    const outcomes=[];for(const mime of['image/jpeg','image/png','image/webp']){const large=await picture(mime,5200,3600);outcomes.push({mime,large:await sample(large),chat:await sample(large,{maxSide:2000}),small:await sample(await picture(mime,64,48))});}
    const raw=await (await picture('image/jpeg',3200,1600)).arrayBuffer(),exif=new Uint8Array([0xff,0xe1,0,34,69,120,105,102,0,0,73,73,42,0,8,0,0,0,1,0,18,1,3,0,1,0,0,0,6,0,0,0,0,0,0,0]),rotated=new Uint8Array(raw.byteLength+exif.length);rotated.set(new Uint8Array(raw).subarray(0,2));rotated.set(exif,2);rotated.set(new Uint8Array(raw).subarray(2),2+exif.length);const orientation=await sample(new File([rotated],'Own-oriented.jpg',{type:'image/jpeg'}));const abort=new AbortController();abort.abort();let cancelled=false;try{await KulturhusPhotos.convert(await picture('image/png',3200,1800),{signal:abort.signal});}catch(error){cancelled=error.name==='AbortError';}
    return {outcomes,orientation,cancelled};
  });
  for(const outcome of raster.outcomes){
    check(outcome.large.width===2400&&outcome.large.height===1662&&outcome.large.size<=1700000&&outcome.large.type===outcome.mime,'large '+outcome.mime+' fits real common upload bounds without changing its format');
    check(outcome.chat.width===2000&&outcome.chat.width*outcome.chat.height<=4000000,'large '+outcome.mime+' fits the existing private chat photo limit');
    check(outcome.small.sameObject&&outcome.small.sameBytes&&outcome.small.lastModified===123456,'small '+outcome.mime+' keeps exact original object bytes and timestamp');
    check(outcome.large.left[0]>150&&outcome.large.right[2]>120,'large '+outcome.mime+' keeps original independent left/right colors');
    if(outcome.mime!=='image/jpeg')check(outcome.large.top[3]===0,'large '+outcome.mime+' retains transparent source pixels');
  }
  check(raster.orientation.width===1200&&raster.orientation.height===2400,'ordinary JPEG EXIF orientation is applied once before fitting the upload');
  check(raster.cancelled,'cancelled ordinary raster preparation never returns a converted selection');
  const mislabeled=await page.evaluate(async()=>{const canvas=new OffscreenCanvas(3600,2800),ctx=canvas.getContext('2d');ctx.fillStyle='#80c030';ctx.fillRect(0,0,canvas.width,canvas.height);const blob=await canvas.convertToBlob({type:'image/png'}),file=new File([blob],'Own-phone.jpg',{type:'image/jpeg'}),kind=await KulturhusPhotos.format(file),result=await KulturhusPhotos.convert(file);return{kind,name:result.name,mime:result.type};});
  check(mislabeled.kind==='png'&&mislabeled.mime==='image/png'&&mislabeled.name==='Own-phone.png','large mislabelled ordinary photo uses actual PNG bytes and a matching prepared filename');
  const oldBrowser=await page.evaluate(async bytes=>{const worker=window.Worker;try{window.Worker=undefined;const file=new File([new Uint8Array(bytes)],'Own-standard.jpg',{type:'image/jpeg'}),result=await KulturhusPhotos.convert(file);return result===file;}finally{window.Worker=worker;}},jpeg);
  check(oldBrowser,'ordinary supported upload remains its exact original when the local worker API is unavailable');
  await context.close();
  for (const width of [320, 390, 820]) {
    const { context, page } = await session(width),
      input = page.locator('[name="image[]"]');
    check(
      (await input.getAttribute("accept")).includes(".heic") &&
        (await input.getAttribute("accept")).includes(".dng"),
      width + " native picker accepts Apple and RAW files",
    );
    await page
      .locator("[name=note]")
      .fill("Eigener unveränderter Text " + width);
    await input.setInputFiles([
      { name: "own.heic", mimeType: "", buffer: heic },
      {
        name: "own-bad.HEIC",
        mimeType: "image/heic",
        buffer: Buffer.from("Own broken file"),
      },
      { name: "own.dng", mimeType: "", buffer: dng },
    ]);
    await page.waitForFunction(() => window.photoChanges.length === 1);
    check(
      (await input.evaluate(
        (e) =>
          e.files.length === 2 &&
          [...e.files].every((f) => f.type === "image/jpeg"),
      )) &&
        (await page.locator("[name=note]").inputValue()) ===
          "Eigener unveränderter Text " + width,
      width + " partial conversion preserves two good files and the form text",
    );
    check(
      (await page
        .locator("#photo-import-message")
        .innerText()
        .then((t) => t.includes("own-bad.HEIC"))) &&
        (await page.evaluate(
          () => document.documentElement.scrollWidth <= innerWidth + 2,
        )),
      width + " partial error is visible and fits the viewport",
    );
    check(
      await page
        .locator("#photo-import-message button")
        .boundingBox()
        .then((b) => b.height >= 44 && b.width >= 44),
      width + " error dismissal has a usable touch target",
    );
    check(
      (await page
        .getByRole("button", { name: "Vorher deaktiviert" })
        .isDisabled()) &&
        (await page
          .getByRole("button", { name: "Hochladen", exact: true })
          .isEnabled()),
      width + " conversion restores original button states",
    );
    await page.getByRole("button", { name: "Hochladen", exact: true }).click();
    await page.waitForFunction(() => window.photoUpload);
    const result = await page.evaluate(() => window.photoUpload);
    check(
      result.images.length === 2 &&
        result.images[0].mime === "image/jpeg" &&
        result.images[0].width === 96 &&
        result.images[1].width === 64 &&
        result.note === "Eigener unveränderter Text " + width,
      width +
        " real multipart upload receives actual converted pixels exactly once",
    );
    await context.close();
  }
  const mixed=await session(390);
  const rasterBytes=await mixed.page.evaluate(async()=>{const result=[];for(const type of['image/jpeg','image/png','image/webp']){const canvas=new OffscreenCanvas(5000,3600),ctx=canvas.getContext('2d');ctx.fillStyle='#29a060';ctx.fillRect(0,0,canvas.width,canvas.height);if(type!=='image/jpeg')ctx.clearRect(0,0,canvas.width,canvas.height/3);result.push({name:'Own-large'+(type==='image/jpeg'?'.jpg':type==='image/png'?'.png':'.webp'),type,bytes:[...new Uint8Array(await (await canvas.convertToBlob({type,quality:.9})).arrayBuffer())]});}return result;});
  await mixed.page.locator('[name=note]').fill('Own original mixed photo upload text ä🙂');
  await mixed.page.locator('[name="image[]"]').setInputFiles([...rasterBytes.map(f=>({name:f.name,mimeType:f.type,buffer:Buffer.from(f.bytes)})),{name:'Own-mixed.heic',mimeType:'',buffer:heic},{name:'Own-mixed.dng',mimeType:'',buffer:dng}]);await mixed.page.waitForFunction(()=>photoChanges.length===1);
  check(await mixed.page.locator('[name="image[]"]').evaluate(e=>e.files.length===5&&KulturhusPhotos.originals(e).length===5),'real mixed picker preserves five original selections and prepares all raster/Apple/RAW files');
  await mixed.page.locator('button[type=submit]:not([disabled])').click();await mixed.page.waitForFunction(()=>!!window.photoUpload);const uploaded=await mixed.page.evaluate(()=>window.photoUpload);
  check(uploaded.images.length===5&&uploaded.images.slice(0,3).every(f=>f.width===2400&&f.height===1728)&&uploaded.note==='Own original mixed photo upload text ä🙂','real multipart receives five prepared large raster/HEIC/RAW files and exact user text together');
  check(uploaded.images[0].mime==='image/jpeg'&&uploaded.images[1].mime==='image/png'&&uploaded.images[2].mime==='image/webp'&&uploaded.images[3].width===96&&uploaded.images[4].width===64,'actual mixed multipart retains raster types and original Apple/RAW decoded dimensions');
  for(const name of['image','images[]','receipt_file','document_file','document_files[]','inventory_file','chat_file','gema_file','signature','logo']){
    await mixed.page.evaluate(name=>{const input=document.createElement('input');input.type='file';input.name=name;input.dataset.rasterOwn='1';document.querySelector('form').append(input);},name);const input=mixed.page.locator('[data-raster-own]');await input.setInputFiles({name:rasterBytes[0].name,mimeType:rasterBytes[0].type,buffer:Buffer.from(rasterBytes[0].bytes)});await mixed.page.waitForFunction(()=>!document.querySelector('.photo-import-progress'));const size=await input.evaluate(async e=>{const bitmap=await createImageBitmap(e.files[0]);const result={width:bitmap.width,height:bitmap.height,count:e.files.length};bitmap.close();return result;});check(size.width===(name==='chat_file'?2000:2400)&&size.count===1,'actual shared '+name+' input prepares a large ordinary phone photo to its own limits');await input.evaluate(e=>e.remove());
  }
  await mixed.context.close();
  const rasterCancel=await session();let heldWorker;
  await rasterCancel.context.route('**/photo-import-worker.js',route=>{heldWorker=route;});await rasterCancel.page.locator('[name=note]').fill('Own retained ordinary cancel text');await rasterCancel.page.locator('[name="image[]"]').setInputFiles({name:rasterBytes[0].name,mimeType:rasterBytes[0].type,buffer:Buffer.from(rasterBytes[0].bytes)});await rasterCancel.page.locator('.photo-import-progress').waitFor();await rasterCancel.page.locator('.photo-import-progress button').click();await rasterCancel.page.waitForFunction(()=>!document.querySelector('.photo-import-progress'));
  check((await rasterCancel.page.locator('[name=note]').inputValue())==='Own retained ordinary cancel text'&&!await rasterCancel.page.locator('[name="image[]"]').isDisabled()&&await rasterCancel.page.locator('[name="image[]"]').evaluate(e=>e.files.length===0),'actual in-flight ordinary worker cancellation keeps user text and restores the original active input without a selection');
  check(await rasterCancel.page.evaluate(()=>window.photoChanges.length===0&&!window.photoUpload&&KulturhusPhotos.originals(document.querySelector('[name="image[]"]')).length===1),'actual cancelled ordinary preparation neither replays a converted selection nor submits and keeps its raw original reference');if(heldWorker)await heldWorker.abort().catch(()=>{});await rasterCancel.context.close();
  const normal = await session();
  let downloads = 0;
  normal.page.on("request", (r) => {
    if (/photo-lib(?:raw|heif)/.test(r.url())) downloads++;
  });
  await normal.page
    .locator('[name="image[]"]')
    .setInputFiles([
      { name: "Own.jpg", mimeType: "image/jpeg", buffer: Buffer.from(jpeg) },
    ]);
  await normal.page.waitForFunction(() => window.photoChanges.length === 1);
  check(
    downloads === 0 &&
      (await normal.page
        .locator('[name="image[]"]')
        .evaluate((e) => e.files[0].size)) === jpeg.length,
    "ordinary JPEG selection leaves bytes intact and downloads no decoder",
  );
  await normal.page.evaluate(() => {
    const input = document.createElement("input");
    input.type = "file";
    input.name = "document_file";
    document.querySelector("form").append(input);
  });
  await normal.page.locator("[name=document_file]").setInputFiles([
    {
      name: "Own.pdf",
      mimeType: "application/pdf",
      buffer: Buffer.from("%PDF-own unchanged source"),
    },
  ]);
  await normal.page.waitForFunction(() => window.photoChanges.length === 2);
  check(
    (await normal.page
      .locator("[name=document_file]")
      .evaluate(
        async (e) => (await e.files[0].text()) === "%PDF-own unchanged source",
      )) && downloads === 0,
    "ordinary PDF retains its exact source bytes",
  );
  await normal.context.close();
  const dynamic = await session();
  await dynamic.page.evaluate(() => {
    const group = document.createElement("fieldset");
    group.id = "conditional-photo";
    group.hidden = true;
    const label = document.createElement("label");
    label.textContent = "Eigenes bedingtes Foto";
    const input = document.createElement("input");
    input.type = "file";
    input.name = "story_photo";
    input.accept = "image/jpeg,image/png";
    input.disabled = true;
    label.append(input);
    group.append(label);
    document.querySelector("form").append(group);
  });
  await dynamic.page.waitForFunction(() =>
    document
      .querySelector("[name=story_photo]")
      ?.hasAttribute("data-photo-import"),
  );
  check(
    (await dynamic.page
      .locator("#conditional-photo .photo-edit-launch")
      .count()) === 0,
    "initially disabled hidden photo field does not offer an unusable editor",
  );
  await dynamic.page.evaluate(() => {
    document.querySelector("[name=story_photo]").disabled = false;
  });
  check(
    (await dynamic.page
      .locator("#conditional-photo .photo-edit-launch")
      .count()) === 0,
    "enabling a still hidden photo field keeps its editor out of the active flow",
  );
  await dynamic.page.evaluate(() => {
    document.querySelector("#conditional-photo").hidden = false;
  });
  await dynamic.page.locator("#conditional-photo .photo-edit-launch").waitFor();
  check(
    (await dynamic.page
      .locator("#conditional-photo .photo-edit-launch")
      .count()) === 1,
    "revealed conditional photo field receives exactly one optional editor",
  );
  for (let i = 0; i < 3; i++) {
    await dynamic.page.evaluate(() => {
      const input = document.querySelector("[name=story_photo]");
      input.disabled = true;
      document.querySelector("#conditional-photo").hidden = true;
    });
    await dynamic.page.evaluate(() => {
      document.querySelector("#conditional-photo").hidden = false;
      document.querySelector("[name=story_photo]").disabled = false;
    });
  }
  check(
    (await dynamic.page
      .locator("#conditional-photo .photo-edit-launch")
      .count()) === 1,
    "repeated field activation never duplicates editor controls",
  );
  check(
    (await dynamic.page.locator("[name=story_photo]").getAttribute("accept"))
      .split(",")
      .filter((value) => value === ".heic").length === 1,
    "repeated field activation preserves a single camera-format extension list",
  );
  await dynamic.page
    .locator("[name=story_photo]")
    .setInputFiles([
      { name: "own-conditional.HEIC", mimeType: "", buffer: heic },
    ]);
  await dynamic.page.waitForFunction(() => window.photoChanges.length === 1);
  check(
    (await dynamic.page
      .locator("[name=story_photo]")
      .evaluate((input) => input.files[0].type)) === "image/jpeg",
    "activated conditional field actually decodes its own HEIC before the module sees it",
  );
  await dynamic.context.close();
  const hidden = await session();
  let hiddenRoute;
  await hidden.context.route("**/photo-libraw.wasm", (route) => {
    hiddenRoute = route;
  });
  await hidden.page.evaluate(() => {
    const group = document.createElement("fieldset");
    group.id = "hide-during-decode";
    const label = document.querySelector('[name="image[]"]').closest("label"),
      edit = label.nextElementSibling;
    label.parentElement.insertBefore(group, label);
    group.append(label);
    if (edit?.matches(".photo-edit-launch")) group.append(edit);
  });
  await hidden.page
    .locator('[name="image[]"]')
    .setInputFiles([{ name: "own-hidden.dng", mimeType: "", buffer: dng }]);
  await hidden.page.waitForFunction(
    () => !!document.querySelector(".photo-import-progress"),
  );
  await hidden.page.evaluate(() => {
    document.querySelector("#hide-during-decode").hidden = true;
  });
  await hidden.page.waitForFunction(
    () => !document.querySelector(".photo-import-progress"),
  );
  check(
    await hidden.page
      .locator('[name="image[]"]')
      .evaluate((input) => input.disabled && input.files.length === 0),
    "hiding a converting photo area promptly aborts RAW and keeps its input inactive",
  );
  check(
    await hidden.page.evaluate(
      () => window.photoChanges.length === 0 && !window.photoUpload,
    ),
    "hidden photo area cannot replay a converted selection or submit an upload",
  );
  if (hiddenRoute) await hiddenRoute.abort().catch(() => {});
  await hidden.context.close();
  const cancel = await session();
  let blocked;
  await cancel.context.route("**/photo-libraw.wasm", (r) => {
    blocked = r;
  });
  await cancel.page
    .locator('[name="image[]"]')
    .setInputFiles([{ name: "own.dng", mimeType: "", buffer: dng }]);
  await cancel.page.waitForFunction(
    () => !!document.querySelector(".photo-import-progress"),
  );
  const start = Date.now();
  await cancel.page
    .getByRole("button", { name: "Abbrechen", exact: true })
    .click();
  await cancel.page.waitForFunction(
    () => !document.querySelector(".photo-import-progress"),
  );
  check(
    Date.now() - start < 2000 &&
      (await cancel.page
        .locator('[name="image[]"]')
        .evaluate((e) => !e.disabled && e.files.length === 0)) &&
      (await cancel.page.evaluate(
        () => window.photoChanges.length === 0 && !window.photoUpload,
      )),
    "cancelling a real pending RAW decode restores the form promptly and uploads nothing",
  );
  if (blocked) await blocked.abort();
  await cancel.context.close();
  const switched = await session();
  let detachedRoute;
  await switched.context.route("**/photo-libraw.wasm", (r) => {
    detachedRoute = r;
  });
  await switched.page
    .locator('[name="image[]"]')
    .setInputFiles([{ name: "own.dng", mimeType: "", buffer: dng }]);
  await switched.page.waitForFunction(
    () => !!document.querySelector(".photo-import-progress"),
  );
  await switched.page.evaluate(() => document.querySelector("form").remove());
  await switched.page.waitForFunction(
    () => !document.querySelector(".photo-import-progress"),
  );
  check(
    await switched.page.evaluate(
      () => window.photoChanges.length === 0 && !window.photoUpload,
    ),
    "switching away from a converting form aborts its worker and submits nothing",
  );
  if (detachedRoute) await detachedRoute.abort();
  await switched.context.close();
  const bounded = await session();
  await bounded.page.locator('[name="image[]"]').setInputFiles(
    Array.from({ length: 51 }, (_, i) => ({
      name: "own-" + i + ".heic",
      mimeType: "",
      buffer: heic,
    })),
  );
  await bounded.page.waitForFunction(() =>
    document.querySelector("#photo-import-message")?.textContent.includes("50"),
  );
  check(
    await bounded.page.evaluate(
      () =>
        !document.querySelector('[name="image[]"]').disabled &&
        document.querySelector('[name="image[]"]').files.length === 0 &&
        window.photoChanges.length === 0,
    ),
    "oversized selection is bounded before decoder work or any upload",
  );
  await bounded.context.close();
  check(
    sha(await readFile(fixtures + "own-camera.heic")) === originals[0] &&
      sha(await readFile(fixtures + "own-camera.dng")) === originals[1],
    "camera fixture originals remain byte-identical",
  );
  check(errors.length === 0, "no browser runtime errors: " + errors.join("; "));
  check(external.length === 0, "no external browser requests");
  console.log(
    JSON.stringify({
      checks,
      external_service_calls: false,
      own_camera_fixtures: true,
    }),
  );
} finally {
  await browser?.close();
  server.kill("SIGTERM");
  await new Promise((resolve) => {
    if (server.exitCode !== null) resolve();
    else server.once("exit", resolve);
  });
}
