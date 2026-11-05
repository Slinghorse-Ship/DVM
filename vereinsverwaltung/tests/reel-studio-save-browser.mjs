import assert from "node:assert/strict";
import { readFile, mkdir } from "node:fs/promises";
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
  outside = [];
const check = (ok, label) => {
  assert(ok, label);
  checks++;
  console.log("PASS " + label);
};
try {
  const context = await browser.newContext({
    viewport: { width: 390, height: 1000 },
    hasTouch: true,
    acceptDownloads: true,
  });
  context.on("request", (r) => {
    if (r.method() === "POST") posts++;
    if (
      !r.url().startsWith(fixture.origin + "/") &&
      !r.url().startsWith("blob:" + fixture.origin + "/")
    )
      outside.push(r.url());
  });
  await context.route("**/native-aac-encoder.mjs", (route) => route.abort());
  const page = await context.newPage();
  page.on("pageerror", (e) => errors.push(String(e)));
  page.on("download", () => downloads++);
  const url = fixture.origin + "/?view=social&item=" + fixture.item;
  await page.goto(url);
  const section = page.locator(".native-video-editor"),
    studio = section.locator("[data-video-studio]"),
    status = studio.locator("[data-video-status]"),
    form = studio.locator("[data-native-video-form]");
  check(
    (await section.getAttribute("open")) === null,
    "reel editor starts compact instead of adding a full controls page",
  );
  await section.locator(":scope > summary").click();
  await page.locator('[data-video-studio][data-video-encoder="avc"]').waitFor();
  check(true, "actual local H.264 encoder is available");
  check(
    (await form.locator("input[name=id]").inputValue()) === fixture.item &&
      (await form.locator("input[name=version]").inputValue()) ===
        String(fixture.version),
    "native editor save is bound to the actual original item and version",
  );
  for (const width of [320, 390, 820])
    for (const zoom of [1, 2]) {
      await page.setViewportSize({ width, height: 1000 });
      await page.evaluate(
        (z) => (document.documentElement.style.zoom = String(z)),
        zoom,
      );
      const proof = await studio.evaluate((root) => ({
        fit: root.scrollWidth <= root.clientWidth + 1,
        width: root.clientWidth,
        overflow: [...root.querySelectorAll("*")]
          .filter(
            (n) =>
              n.getBoundingClientRect().right >
                root.getBoundingClientRect().right + 1 ||
              n.scrollWidth > n.clientWidth + 1,
          )
          .map((n) => ({
            tag: n.tagName,
            classes: n.className,
            width: n.getBoundingClientRect().width,
            scroll: n.scrollWidth,
            client: n.clientWidth,
          })),
        targets: [
          ...root.querySelectorAll(
            "[data-video-save],[data-video-export],[data-video-cancel]",
          ),
        ].map((n) => ({
          h: n.getBoundingClientRect().height,
          font: parseFloat(getComputedStyle(n).fontSize),
        })),
      }));
      if (!proof.fit) console.log(JSON.stringify(proof));
      check(
        proof.fit,
        "actual video editor fits " + width + "px/" + zoom + "x",
      );
      check(
        proof.targets.every((t) => t.h >= 43.5 && t.font >= 15.5),
        "real save/download/cancel targets remain touchable at " +
          width +
          "px/" +
          zoom +
          "x",
      );
    }
  await page.evaluate(() => (document.documentElement.style.zoom = "1"));
  await page.setViewportSize({ width: 390, height: 1000 });
  await studio.locator("[data-video-file]").setInputFiles(fixture.file);
  await status.filter({ hasText: "Video geladen" }).waitFor();
  const unavailableAudio =
    (await studio.getAttribute("data-video-aac")) === "0";
  if (unavailableAudio) {
    check(
      (await studio.locator("[data-video-save]").isDisabled()) &&
        (await studio.locator("[data-video-export]").isDisabled()),
      "unavailable AAC never silently removes actual original sound",
    );
    await studio.locator("[data-video-audio]").uncheck();
    check(
      await studio.locator("[data-video-save]").isEnabled(),
      "explicitly removing sound enables the supported encoding path",
    );
    await studio.locator("[data-video-audio]").check();
  }
  await studio.locator("[data-video-file]").setInputFiles(fixture.studio_file);
  await status.filter({ hasText: "Video geladen" }).waitFor();
  check(
    await studio.locator("[data-video-save]").isEnabled(),
    "real silent original can be saved without inventing an audio track",
  );
  await studio.locator("[data-video-caption] > summary").click();
  await studio.locator("[data-video-text]").fill("Own real native saved reel");
  await studio.locator("[data-video-trim] > summary").click();
  await studio.locator("[data-video-start]").fill("0");
  await studio.locator("[data-video-end]").fill("3");
  await studio.locator("[data-video-export]").click();
  await status.filter({ hasText: "MP4-Export:" }).waitFor();
  await studio.locator("[data-video-cancel]").click();
  await status.filter({ hasText: "Export abgebrochen." }).waitFor();
  check(
    posts === 0 &&
      downloads === 0 &&
      (await studio.locator("[data-video-save]").isEnabled()),
    "real encoder cancellation leaves no saved source or fake download and permits retry",
  );
  const downloadEvent = page.waitForEvent("download", { timeout: 120000 });
  await studio.locator("[data-video-export]").click();
  const download = await downloadEvent;
  await mkdir(".local/browser-tests/reel-studio", { recursive: true });
  const downloadPath =
    ".local/browser-tests/reel-studio/own-actual-download.mp4";
  await download.saveAs(downloadPath);
  await status.filter({ hasText: "MP4 ohne Ton geprüft und heruntergeladen." }).waitFor();
  const downloadedBytes = await readFile(downloadPath);
  check(
    posts === 0 &&
      downloads === 1 &&
      downloadedBytes.subarray(4, 8).toString() === "ftyp",
    "separate download still returns actual encoder bytes without a native save",
  );
  await studio.locator("[data-video-end]").fill("1");
  await studio.locator("[data-video-save]").click();
  check(
    (await status.innerText()).includes("3–90 Sekunden") &&
      posts === 0 &&
      downloads === 1,
    "invalid trim neither saves nor downloads any fabricated output",
  );
  await studio.locator("[data-video-end]").fill("3");
  const response = page.waitForResponse(
    (r) => r.request().method() === "POST",
    { timeout: 120000 },
  );
  const originalEditor = await studio.elementHandle();
  await form.locator('[data-native-video-file]').setInputFiles(fixture.file);
  await studio.locator("[data-video-save]").click();
  const savedResponse = await response;
  if (savedResponse.status() !== 200) console.log(JSON.stringify({video_save_failure:{status:savedResponse.status(),body:await savedResponse.text()}}));
  check(
    savedResponse.status() === 200,
    "actual encoder output is submitted once through native multipart save",
  );
  await studio.locator('[data-video-upload-original]').waitFor({state:'visible'});
  check(
    posts === 1 && downloads === 1 &&
      await studio.locator('[data-video-text]').inputValue() === 'Own real native saved reel' &&
      await form.locator('[data-native-video-file]').evaluate(n => n.files[0]?.size === 60207),
    "one confirmed generated save preserves local caption and separately chosen ready MP4 without another download",
  );
  const retained = await originalEditor.evaluate(root => root.reelStudio.state());
  check(!retained.disposed && retained.hasInput && !retained.busy,
    'confirmed save retains the original decoder and local input until conscious navigation');
  await page.locator('#social-videos .social-video-pager a[data-nav]').first().click();
  await page.locator('#discard[open] [data-choice=discard]').click();
  await page.waitForFunction(() => document.querySelector('#social-videos > h2 .badge')?.textContent === '9');
  const disposedEditor = await originalEditor.evaluate(root => root.reelStudio.state());
  check(disposedEditor.disposed && !disposedEditor.busy && !disposedEditor.hasInput && disposedEditor.objectUrls === 0,
    'only conscious navigation disposes the decoder and all original object URLs');
  const card = page.locator("#social-videos article").first();
  await card.locator(".social-video-card-tools > summary").click();
  check(
    (await card.innerText()).includes("vereins-reel.mp4") &&
      (await card.innerText()).includes("Ohne Ton"),
    "actual saved original MP4 appears with explicit preserved silent profile",
  );
  const source = await card.locator("video").getAttribute("src");
  const file = await context.request.get(new URL(source, fixture.origin).href);
  const bytes = await file.body();
  check(
    file.status() === 200 &&
      bytes.subarray(4, 8).toString() === "ftyp" &&
      file.headers()["content-type"] === "video/mp4",
    "native original route returns actual encoded MP4 bytes",
  );
  check(
    !(await page.evaluate(() => window.KulturhusVideoStudio.guarded())),
    "saved page has no abandoned input or encoder guard after fragment disposal",
  );
  await page.locator(".native-video-editor > summary").click();
  await page.locator('[data-video-studio][data-video-encoder="avc"]').waitFor();
  check(
    await page.locator("[data-video-save]").isDisabled(),
    "actual new fragment mounts one empty editor rather than reviving an old upload",
  );
  await page
    .locator(".native-video-editor")
    .screenshot({ path: ".local/browser-tests/reel-studio/editor-mobile.png" });
  await mkdir(".local/browser-tests/reel-studio", { recursive: true });
  await page
    .locator("#social-videos")
    .screenshot({ path: ".local/browser-tests/reel-studio/mobile.png" });
  if (errors.length || outside.length)
    console.log(JSON.stringify({ errors, outside }));
  check(
    errors.length === 0 && outside.length === 0,
    "direct save has no script error or external request",
  );
  console.log(
    JSON.stringify({
      suite: "reel-studio-save-browser",
      checks,
      posts,
      downloads,
      live_transport: false,
    }),
  );
  await context.close();
} finally {
  await browser.close();
}
