import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const fixture = JSON.parse(
  await readFile(process.env.DVM_VIDEO_FIXTURE, "utf8"),
);
const browser = await chromium.launch({
  executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || undefined,
});
let checks = 0,
  posts = 0,
  downloads = 0;
const errors = [],
  outside = [],
  aacRequests = [];
const check = (ok, label) => {
  assert(ok, label);
  checks++;
  console.log("PASS " + label);
};
async function audioProof(page, bytes = null) {
  return await page.evaluate(async (data) => {
    const media = await import("/assets/native-mediabunny.mjs");
    const file = data
      ? new Blob([new Uint8Array(data)], { type: "video/mp4" })
      : document.querySelector("[data-video-file]").files[0];
    const input = new media.Input({
      source: new media.BlobSource(file),
      formats: [media.MP4],
    });
    try {
      const track = await input.getPrimaryAudioTrack(),
        sink = new media.AudioBufferSink(track);
      let samples = 0;
      const squares = Array(track.numberOfChannels).fill(0),
        peaks = Array(track.numberOfChannels).fill(0);
      for await (const part of sink.buffers(0, 3)) {
        samples += part.buffer.length;
        for (let channel = 0; channel < track.numberOfChannels; channel++)
          for (const value of part.buffer.getChannelData(channel)) {
            squares[channel] += value * value;
            peaks[channel] = Math.max(peaks[channel], Math.abs(value));
          }
      }
      return {
        codec: await track.getCodec(),
        rate: track.sampleRate,
        channels: track.numberOfChannels,
        samples,
        rms: Math.sqrt(squares[0] / samples),
        peak: peaks[0],
        channel_rms: squares.map((square) => Math.sqrt(square / samples)),
      };
    } finally {
      input.dispose();
    }
  }, bytes);
}
try {
  const context = await browser.newContext({
    viewport: { width: 390, height: 1000 },
    hasTouch: true,
  });
  await context.addInitScript(() => {
    const encoder = window.AudioEncoder;
    if (encoder) {
      const native = encoder.isConfigSupported.bind(encoder);
      encoder.isConfigSupported = (config) =>
        String(config.codec).startsWith("mp4a")
          ? Promise.resolve({ supported: false, config })
          : native(config);
    }
    const NativeWorker = window.Worker;
    window.audioWorkers = { created: 0, terminated: 0, active: 0 };
    window.Worker = class extends NativeWorker {
      constructor(...args) {
        super(...args);
        this.tracked = String(args[0]).endsWith("/native-aac-worker.js");
        this.finished = false;
        if (this.tracked) {
          audioWorkers.created++;
          audioWorkers.active++;
        }
      }
      terminate() {
        if (this.tracked && !this.finished) {
          this.finished = true;
          audioWorkers.terminated++;
          audioWorkers.active--;
        }
        return super.terminate();
      }
    };
  });
  await context.route("**/native-aac-libav.mjs", async (route) => {
    await new Promise((ok) => setTimeout(ok, 200));
    await route.continue();
  });
  context.on("request", (request) => {
    if (request.method() === "POST") posts++;
    const url = request.url();
    if (url.includes("/native-aac-")) aacRequests.push(url);
    if (
      !url.startsWith(fixture.origin + "/") &&
      !url.startsWith("blob:" + fixture.origin + "/")
    )
      outside.push(url);
  });
  const page = await context.newPage();
  page.on("pageerror", (error) => errors.push(String(error)));
  page.on("download", () => downloads++);
  const url = fixture.origin + "/?view=social&item=" + fixture.item;
  await page.goto(url);
  await page.locator(".native-video-editor > summary").click();
  const studio = page.locator("[data-video-studio]"),
    status = studio.locator("[data-video-status]");
  await page.locator("[data-video-studio][data-video-encoder=avc]").waitFor();
  check(
    (await studio.getAttribute("data-video-aac")) === "0" &&
      aacRequests.length === 0,
    "native AAC is unavailable and no fallback is loaded before an actual audio selection",
  );
  await studio.locator("[data-video-file]").setInputFiles(fixture.file);
  await status.filter({ hasText: "Video geladen" }).waitFor();
  check(
    (await studio.getAttribute("data-video-aac")) === "1" &&
      (await studio.locator("[data-video-audio]").isChecked()) &&
      (await studio.locator("[data-video-save]").isEnabled()),
    "actual sound selection registers the local fallback without removing original audio",
  );
  check(
    aacRequests.filter((url) => url.endsWith("/native-aac-encoder.mjs"))
      .length === 1 &&
      !aacRequests.some((url) => url.endsWith("/native-aac-worker.js")),
    "only the small fallback source loads on selection; no encoder worker starts before export",
  );
  const original = await audioProof(page);
  check(
    original.codec === "aac" &&
      original.rate === 48000 &&
      original.channels === 2 &&
      original.rms > 0.02 &&
      original.samples > 140000,
    "original fixture has actual decoded audible stereo AAC samples",
  );
  await studio
    .locator("[data-video-file]")
    .setInputFiles(fixture.unsupported_audio_file);
  await status.filter({ hasText: "Video geladen" }).waitFor();
  check(
    (await studio.getAttribute("data-video-audio-channels")) === "8" &&
      (await studio.locator("[data-video-audio]").isChecked()) &&
      (await studio.locator("[data-video-save]").isDisabled()),
    "unsupported eight-channel layout is never silently reduced to its first two channels",
  );
  await studio.locator("[data-video-audio]").uncheck();
  check(
    await studio.locator("[data-video-save]").isEnabled(),
    "explicitly choosing no sound allows the actual video even when its original audio layout is unsupported",
  );
  await studio
    .locator("[data-video-file]")
    .setInputFiles(fixture.surround_file);
  await status.filter({ hasText: "Video geladen" }).waitFor();
  await studio.locator("[data-video-audio]").check();
  check(
    (await studio.getAttribute("data-video-audio-channels")) === "6" &&
      (await studio.locator("[data-video-save]").isEnabled()) &&
      (await status.innerText()).includes("Mehrkanalton wird als Stereo"),
    "real six-channel source is accepted with an explicit standard stereo downmix",
  );
  const surround = await audioProof(page);
  check(
    surround.channels === 6 &&
      surround.channel_rms[0] < 0.0001 &&
      surround.channel_rms[1] < 0.0001 &&
      surround.channel_rms[2] > 0.1,
    "actual surround fixture contains audible center-channel signal while both original stereo channels are silent",
  );
  for (const [name, csp] of [
    ["native-aac-worker.js", true],
    ["native-aac-libav.mjs", false],
  ]) {
    const response = await context.request.head(
        fixture.origin + "/assets/" + name,
      ),
      headers = response.headers();
    check(
      response.status() === 200 &&
        headers["content-type"].startsWith("text/javascript") &&
        headers["x-content-type-options"] === "nosniff",
      "actual " + name + " HEAD resolves as a bounded local script",
    );
    check(
      csp
        ? headers["content-security-policy"].includes("'wasm-unsafe-eval'") &&
            headers["content-security-policy"].includes("connect-src 'none'") &&
            headers["content-security-policy"].includes("worker-src 'none'")
        : !headers["content-security-policy"].includes("'wasm-unsafe-eval'"),
      "WASM permission is confined to the actual fixed audio worker response",
    );
  }
  const invalid = await context.request.post(
    fixture.origin + "/assets/native-aac-worker.js",
  );
  check(
    invalid.status() === 405,
    "actual codec endpoint rejects POST without processing any user payload",
  );
  // This endpoint probe is a deliberate rejected POST; no application write.
  const initialPosts = posts;
  await studio.locator("[data-video-save]").click();
  await page.waitForFunction(() => audioWorkers.created === 1);
  await studio.locator("[data-video-cancel]").click();
  await status.filter({ hasText: "Export abgebrochen." }).waitFor();
  const cancelled = await page.evaluate(() => audioWorkers);
  check(
    cancelled.created === 1 &&
      cancelled.active === 0 &&
      cancelled.terminated === 1 &&
      posts === initialPosts &&
      downloads === 0,
    "cancelling an actual AAC worker leaves no upload or silent replacement and terminates its worker",
  );
  check(
    (await studio.locator("[data-video-audio]").isChecked()) &&
      (await studio.locator("[data-video-save]").isEnabled()),
    "original sound stays selected and real audio export can be retried after cancellation",
  );
  const oldEditor = await studio.elementHandle();
  const response = page.waitForResponse(
    (response) =>
      response.request().method() === "POST" &&
      new URL(response.url()).pathname === "/",
    { timeout: 120000 },
  );
  await studio.locator("[data-video-save]").click();
  const savedResponse = await response;
  if (savedResponse.status() !== 200) console.log(JSON.stringify({video_save_failure:{status:savedResponse.status(),body:await savedResponse.text()}}));
  check(
    savedResponse.status() === 200,
    "actual H.264/AAC encoder output is saved through one native multipart request",
  );
  await studio.locator('[data-video-upload-original]').waitFor({state:'visible'});
  const completed = await page.evaluate(() => audioWorkers),
    retained = await oldEditor.evaluate(root => root.reelStudio.state());
  check(posts === initialPosts + 1 && downloads === 0 && completed.created === 2 &&
    completed.active === 0 && completed.terminated === 2 && !retained.disposed && retained.hasInput,
    'actual successful save terminates its AAC worker while retaining the local original decoder');
  await page.locator('#social-videos .social-video-pager a[data-nav]').first().click();
  await page.locator('#discard[open] [data-choice=discard]').click();
  await page.waitForFunction(() => document.querySelector('#social-videos > h2 .badge')?.textContent === '9');
  const disposed = await oldEditor.evaluate(root => root.reelStudio.state());
  check(disposed.disposed && !disposed.hasInput && disposed.objectUrls === 0,
    'conscious navigation releases the saved reel original and object URLs');
  const card = page.locator("#social-videos article").first();
  await card.locator(".social-video-card-tools > summary").click();
  check(
    (await card.innerText()).includes("vereins-reel.mp4") &&
      (await card.innerText()).includes("Mit Ton"),
    "new exact private version is visibly identified as containing original sound",
  );
  const source = await card.locator("video").getAttribute("src"),
    file = await context.request.get(new URL(source, fixture.origin).href),
    bytes = await file.body();
  check(
    file.status() === 200 &&
      file.headers()["content-type"] === "video/mp4" &&
      bytes.subarray(4, 8).toString() === "ftyp",
    "native private route supplies actual saved MP4 bytes",
  );
  const saved = await audioProof(page, Array.from(bytes));
  check(
    saved.codec === "aac" &&
      saved.rate === 48000 &&
      saved.channels === 2 &&
      saved.samples > 140000 &&
      saved.peak > 0.03 &&
      saved.channel_rms.every(
        (rms) =>
          rms / surround.channel_rms[2] > 0.65 &&
          rms / surround.channel_rms[2] < 0.75,
      ),
    "independent decoding confirms the original center channel reaches both actual stereo output channels instead of becoming silent",
  );
  if (errors.length || outside.length)
    console.log(JSON.stringify({ errors, outside }));
  check(
    errors.length === 0 && outside.length === 0,
    "real local AAC fallback has no script error or external network request",
  );
  console.log(
    JSON.stringify({
      suite: "reel-studio-audio-browser",
      checks,
      posts: posts - initialPosts,
      downloads,
      original_audio: original,
      original_surround: surround,
      saved_audio: saved,
      live_transport: false,
    }),
  );
  await context.close();
} finally {
  await browser.close();
}
