import assert from "node:assert/strict";
import { readFile, mkdir } from "node:fs/promises";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const fixture = JSON.parse(
  await readFile(process.env.DVM_VIDEO_FIXTURE, "utf8"),
);
const bytes = await readFile(fixture.file);
const browser = await chromium.launch({
  executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || undefined,
});
let checks = 0,
  posts = 0;
const errors = [],
  outside = [];
const check = (condition, label) => {
  assert(condition, label);
  checks++;
  console.log("PASS " + label);
};
try {
  const context = await browser.newContext({
    viewport: { width: 390, height: 1000 },
    hasTouch: true,
  });
  context.on("request", (request) => {
    if (request.method() === "POST") posts++;
    if (!request.url().startsWith(fixture.origin + "/"))
      outside.push(request.url());
  });
  const page = await context.newPage();
  page.on("pageerror", (error) => errors.push(String(error)));
  const url = fixture.origin + "/?view=social&item=" + fixture.item;
  await page.goto(url);
  const panel = page.locator("#social-videos"),
    form = panel.locator(".social-video-upload form[method=post]");
  check(
    (await panel.locator(".badge").innerText()) === "8",
    "actual current private history exposes the full original count",
  );
  check(
    (await panel.locator(".social-video-versions").getAttribute("open")) ===
      null,
    "private videos start collapsed and do not demand a long page",
  );
  await panel.locator(".social-video-upload > summary").click();
  for (const width of [320, 390, 820])
    for (const zoom of [1, 2]) {
      await page.setViewportSize({ width, height: 1000 });
      await page.evaluate(
        (value) => (document.documentElement.style.zoom = String(value)),
        zoom,
      );
      const proof = await panel.evaluate((root) => ({
        fit: root.scrollWidth <= root.clientWidth + 1,
        controls: [
          ...root.querySelectorAll(
            ".social-video-upload form[method=post] input[type=file],.social-video-upload form[method=post] button",
          ),
        ].filter(node => node.getClientRects().length > 0).map((node) => ({
          h: node.getBoundingClientRect().height,
          font: parseFloat(getComputedStyle(node).fontSize),
        })),
      }));
      check(
        proof.fit,
        "actual private upload fits " + width + "px at " + zoom + "x zoom",
      );
      check(
        proof.controls.length === 3 &&
          proof.controls.every(
            (control) => control.h >= 44 * zoom - 1 && control.font >= 16,
          ),
        "actual file and save controls remain touchable and readable",
      );
    }
  await page.evaluate(() => (document.documentElement.style.zoom = "1"));
  await page.setViewportSize({ width: 390, height: 1000 });
  await panel.locator(".social-video-versions > summary").click();
  check(
    (await panel.locator(".social-video-grid video").count()) === 6,
    "actual first private history page contains only six bounded players",
  );
  check(
    await panel
      .locator(".social-video-grid video")
      .evaluateAll((nodes) =>
        nodes.every((node) => node.preload === "none" && !node.autoplay),
      ),
    "private players do not automatically load or play all video files",
  );
  await panel
    .getByRole("link", { name: "Weitere Videos", exact: true })
    .first()
    .click();
  await page.waitForFunction(
    () => document.querySelectorAll("#social-videos .social-video-grid video").length === 2,
  );
  check(
    true,
    "actual history navigation reaches the two exact remaining original versions",
  );
  await page.goto(url);
  const response = await context.request.get(
    fixture.origin + "/social-video/" + fixture.video,
    { headers: { Range: "bytes=0-31" } },
  );
  check(
    response.status() === 206 &&
      response.headers()["content-range"] === "bytes 0-31/" + bytes.length,
    "actual private MP4 endpoint returns an exact seekable byte range",
  );
  assert.deepEqual(await response.body(), bytes.subarray(0, 32));
  check(
    response.headers()["content-type"] === "video/mp4" &&
      response.headers()["x-content-type-options"] === "nosniff" &&
      response.headers()["cache-control"].includes("no-store"),
    "actual private range has MP4 and private no-store headers",
  );
  for (const range of ["bytes=-16", "bytes=60200-", "bytes=60200-999999"]) {
    const r = await context.request.get(
      fixture.origin + "/social-video/" + fixture.video,
      { headers: { Range: range } },
    );
    check(
      r.status() === 206,
      "actual suffix or bounded open range is accepted",
    );
    assert.deepEqual(
      await r.body(),
      bytes.subarray(range === "bytes=-16" ? bytes.length - 16 : 60200),
    );
  }
  for (const range of [
    "bytes=-0",
    "bytes=60207-",
    "bytes=7-4",
    "bytes=0-1,3-4",
    "bytes=9999999999999999999-",
    "garbage",
  ]) {
    const r = await context.request.get(
      fixture.origin + "/social-video/" + fixture.video,
      { headers: { Range: range } },
    );
    check(
      r.status() === 416 &&
        (await r.body()).length === 0 &&
        r.headers()["content-range"] === "bytes */" + bytes.length,
      "invalid private range returns a bounded empty 416 response",
    );
  }
  const head = await context.request.head(
    fixture.origin + "/social-video/" + fixture.video,
    { headers: { Range: "bytes=0-1" } },
  );
  check(
    head.status() === 206 &&
      head.headers()["content-length"] === "2" &&
      (await head.body()).length === 0,
    "actual HEAD reports the exact range without sending video bytes",
  );
  const notFound = await context.request.get(
    fixture.origin + "/social-video/" + "f".repeat(32),
  );
  check(
    notFound.status() >= 400,
    "unrelated private video identity is never substituted",
  );
  await panel.locator(".social-video-upload > summary").click();
  await form
    .locator("input[type=file]")
    .setInputFiles(fixture.upload_file || fixture.file);
  const savedResponse = page.waitForResponse(
    (r) => r.request().method() === "POST",
  );
  await form
    .getByRole("button", { name: "MP4 als private Videofassung speichern", exact: true })
    .click();
  check(
    (await savedResponse).status() === 200,
    "actual multipart MP4 save is accepted once",
  );
  await form.locator('..').locator('[data-video-upload-original]').waitFor({state:'visible'});
  check((await form.locator('input[type=file]').inputValue()) === '',
    'confirmed exact multipart save clears only its own submitted file choice');
  await panel.locator('.social-video-pager a[data-nav]').first().click();
  await page.waitForFunction(() => document.querySelector('#social-videos > h2 .badge')?.textContent === '9');
  check(true, 'conscious history navigation shows the exact newly saved private original');
  const csrf = await page
    .locator("#social-videos .social-video-upload form[method=post] [name=csrf]")
    .inputValue();
  const bad = await context.request.post(url, {
    headers: { Accept: "application/json" },
    multipart: {
      operation: "social-video-save",
      id: fixture.item,
      csrf: "bad",
      version: String(fixture.version),
      identity_version: String(fixture.identity_version),
      video_file: { name: "invalid.mp4", mimeType: "video/mp4", buffer: bytes },
    },
  });
  posts++;
  check(bad.status() >= 400, "actual invalid CSRF never saves another version");
  const stale = await context.request.post(url, {
    headers: { Accept: "application/json" },
    multipart: {
      operation: "social-video-save",
      id: fixture.item,
      csrf,
      video_request_key: "a".repeat(32),
      version: "1",
      identity_version: String(fixture.identity_version),
      video_file: { name: "stale.mp4", mimeType: "video/mp4", buffer: bytes },
    },
  });
  posts++;
  check(
    stale.status() >= 400,
    "actual stale upload version never promotes or creates a new file",
  );
  await page.goto(url);
  check(
    (await panel.locator(".badge").innerText()) === "9",
    "rejected HTTP uploads preserve the exact total after the one accepted version",
  );
  await panel.locator(".social-video-versions > summary").click();
  await mkdir(".local/browser-tests/videos", { recursive: true });
  await page.screenshot({
    path: ".local/browser-tests/videos/mobile.png",
    fullPage: false,
  });
  check(
    posts === 3 && errors.length === 0 && outside.length === 0,
    "native video flow performs exactly one accepted and two rejected own POSTs without provider calls or script errors",
  );
  console.log(
    JSON.stringify({
      suite: "videos-browser",
      checks,
      posts,
      external_requests: outside.length,
    }),
  );
} finally {
  await browser.close();
}
