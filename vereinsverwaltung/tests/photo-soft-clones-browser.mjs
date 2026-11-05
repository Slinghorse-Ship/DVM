import assert from "node:assert/strict";
import { readFile, mkdir } from "node:fs/promises";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const fixture = JSON.parse(
  await readFile(process.env.DVM_PHOTO_SOFT_CLONES_FIXTURE, "utf8"),
);
assert(/^http:\/\/127\.0\.0\.1:\d{4,5}$/.test(fixture.origin));
const browser = await chromium.launch({
  executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || undefined,
});
let checks = 0,
  posts = 0;
const errors = [],
  external = [];
const check = (ok, label) => {
  assert(ok, label);
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
      external.push(request.url());
  });
  const page = await context.newPage();
  page.on("pageerror", (error) => errors.push(String(error)));
  await page.goto(fixture.origin + "/?view=social&item=" + fixture.item);
  const studio = page.locator("[data-canvas-editor]");
  await studio
    .locator("[data-studio-status]")
    .filter({ hasText: "Bildstudio bereit" })
    .waitFor();
  const snapshot = () => studio.evaluate((node) => node.studio.snapshot());
  const proof = await page.evaluate(() => {
    const create = () => {
      const c = document.createElement("canvas");
      c.width = 200;
      c.height = 100;
      const g = c.getContext("2d");
      g.fillStyle = "rgb(240,20,20)";
      g.fillRect(0, 0, 100, 100);
      g.fillStyle = "rgb(20,20,240)";
      g.fillRect(100, 0, 100, 100);
      return c;
    };
    const stamp = {
      source_x: 25,
      source_y: 50,
      target_x: 75,
      target_y: 50,
      radius: 20,
    };
    const old = create(),
      zero = create();
    KulturhusPhotoClones.apply(old, [stamp]);
    KulturhusPhotoClones.apply(zero, [{ ...stamp, softness: 0 }]);
    const a = old.getContext("2d").getImageData(0, 0, 200, 100).data,
      b = zero.getContext("2d").getImageData(0, 0, 200, 100).data;
    const legacy = a.every((value, i) => value === b[i]);
    const c = create(),
      g = c.getContext("2d");
    KulturhusPhotoClones.apply(c, [{ ...stamp, softness: 50 }]);
    const colors = [150, 167, 169, 175].map((x) => [
      ...g.getImageData(x, 50, 1, 1).data,
    ]);
    const chain = create();
    KulturhusPhotoClones.apply(chain, [
      { ...stamp, softness: 50 },
      { ...stamp, source_x: 75, target_x: 25, softness: 50 },
    ]);
    const independent = [50, 150].map((x) => [
      ...chain.getContext("2d").getImageData(x, 50, 1, 1).data,
    ]);
    const malformed = [];
    for (const softness of [-1, 100.1, NaN, Infinity, "50", null]) {
      try {
        KulturhusPhotoClones.validate([{ ...stamp, softness }]);
        malformed.push(false);
      } catch {
        malformed.push(true);
      }
    }
    const target = create(),
      made = [],
      native = document.createElement.bind(document);
    document.createElement = function (tag, ...args) {
      const node = native(tag, ...args);
      if (tag === "canvas") made.push(node);
      return node;
    };
    try {
      KulturhusPhotoClones.apply(target, [{ ...stamp, softness: 50 }]);
    } finally {
      document.createElement = native;
    }
    return {
      legacy,
      colors,
      independent,
      malformed,
      released:
        made.length === 2 &&
        made.every((node) => node.width === 0 && node.height === 0),
    };
  });
  check(
    proof.legacy,
    "every legacy hard-stamp pixel is byte identical to an explicit zero-softness stamp",
  );
  check(
    proof.colors[0][0] === 240 && proof.colors[0][2] === 20,
    "feather preserves the exact opaque original source center",
  );
  check(
    proof.colors[1][0] > proof.colors[2][0] &&
      proof.colors[1][2] < proof.colors[2][2] &&
      proof.colors[2][0] > 20 &&
      proof.colors[2][2] < 240,
    "actual feather alpha blends monotonically toward the original target",
  );
  check(
    proof.colors[3][0] === 20 && proof.colors[3][2] === 240,
    "pixels outside the round feather retain exact original target bytes",
  );
  check(
    proof.independent[0][2] === 240 && proof.independent[1][0] === 240,
    "multiple soft stamps sample the immutable original instead of a previously retouched patch",
  );
  check(
    proof.malformed.every(Boolean),
    "browser rejects every malformed feather value",
  );
  check(
    proof.released,
    "actual mask patch and immutable sample release their pixel buffers after applying",
  );
  await studio.getByRole("tab", { name: "Bearbeiten", exact: true }).click();
  const group = studio.locator("[data-property-group=Retusche]");
  if ((await group.getAttribute("open")) === null)
    await group.locator(":scope > summary").click();
  let detail = studio.locator("[data-clone-controls]");
  if ((await detail.getAttribute("open")) === null)
    await detail.locator(":scope > summary").click();
  check(
    (await detail.locator("[data-clone-field=softness]").inputValue()) === "50",
    "new manual stamp starts with an explicitly editable soft edge",
  );
  for (const width of [320, 390, 820])
    for (const zoom of [1, 2]) {
      await page.setViewportSize({ width, height: 1000 });
      await page.evaluate((value) => {
        document.documentElement.style.zoom = String(value);
      }, zoom);
      const geometry = await detail.evaluate((root) => ({
        fits: root.scrollWidth <= root.clientWidth + 1,
        controls: [...root.querySelectorAll("input,button")]
          .filter((node) => node.getClientRects().length)
          .map((node) => ({
            height: node.getBoundingClientRect().height,
            font: parseFloat(getComputedStyle(node).fontSize),
          })),
      }));
      check(
        geometry.fits,
        "actual compact retouch controls fit " +
          width +
          "px at " +
          zoom +
          "x zoom",
      );
      for (const control of geometry.controls)
        check(
          control.height >= 44 * zoom - 1 && control.font >= 16,
          "actual retouch control remains touchable and readable",
        );
    }
  await page.evaluate(() => {
    document.documentElement.style.zoom = "1";
  });
  await page.setViewportSize({ width: 390, height: 1000 });
  for (const [key, value] of Object.entries({
    source_x: 25,
    source_y: 50,
    target_x: 75,
    target_y: 50,
    radius: 20,
    softness: 50,
  }))
    await detail.locator("[data-clone-field=" + key + "]").fill(String(value));
  await detail
    .getByRole("button", { name: "Klonstempel anwenden", exact: true })
    .click();
  assert.deepEqual(await snapshot(), fixture.expected);
  check(true, "actual editor stores only the intended explicit feather stamp");
  await page.waitForFunction(() => {
    const c = document.querySelector("[data-canvas-editor] canvas"),
      p = c.getContext("2d").getImageData(720, 300, 1, 1).data;
    return p[0] > 40 && p[0] < 180 && p[2] > 80 && p[2] < 225;
  });
  check(
    true,
    "actual preview renders a blended edge in the expected original photo coordinates",
  );
  await studio.getByRole("button", { name: "Rückgängig", exact: true }).click();
  assert.deepEqual(await snapshot(), fixture.document);
  check(true, "actual undo restores every exact original document value");
  await studio
    .getByRole("button", { name: "Wiederherstellen", exact: true })
    .click();
  assert.deepEqual(await snapshot(), fixture.expected);
  check(true, "actual redo restores the exact editable feather document");
  if ((await group.getAttribute("open")) === null)
    await group.locator(":scope > summary").click();
  detail = studio.locator("[data-clone-controls]");
  if ((await detail.getAttribute("open")) === null)
    await detail.locator(":scope > summary").click();
  await detail.locator("[data-clone-field=softness]").fill("101");
  await detail
    .getByRole("button", { name: "Klonstempel anwenden", exact: true })
    .click();
  check(
    (await detail.getByRole("alert").innerText()).length > 0,
    "invalid manual edge keeps a short local correction",
  );
  check(
    await detail
      .getByRole("alert")
      .evaluate((node) => node === document.activeElement),
    "invalid draft focuses its local correction",
  );
  check(
    await detail
      .locator("[data-clone-field=softness]")
      .evaluate((input) => input.form === null && !input.checkValidity()),
    "invalid unapplied draft validates locally and does not block the saved composition form",
  );
  assert.deepEqual(await snapshot(), fixture.expected);
  check(
    true,
    "invalid edge never changes the current original-bound composition",
  );
  await mkdir(".local/browser-tests/photo-soft-clones", { recursive: true });
  await page.screenshot({
    path: ".local/browser-tests/photo-soft-clones/mobile.png",
    fullPage: false,
  });
  await studio.locator(".studio-export > summary").click();
  const download = page.waitForEvent("download");
  await studio.locator("[data-studio=download-png]").click();
  const exported = await download;
  await exported.saveAs(".local/browser-tests/photo-soft-clones/feathered.png");
  check(
    exported.suggestedFilename().endsWith(".png"),
    "actual feathered image exports locally as a real PNG",
  );
  const originalStudio = await studio.elementHandle();
  const savedResponse = page.waitForResponse(
    (response) => response.request().method() === "POST",
  );
  await studio
    .getByRole("button", { name: "Bildfassung speichern", exact: true })
    .click();
  const saved = await savedResponse;
  const data = await saved.json();
  check(
    saved.status() === 200,
    "actual native retouch save is accepted: " + (data.error || "saved"),
  );
  await studio
    .locator("[data-studio-status]")
    .filter({ hasText: "Bildfassung gespeichert. Ungespeicherte Beitragstexte und weitere Gestaltungen bleiben erhalten." })
    .waitFor();
  check(await originalStudio.evaluate(node => node.isConnected),
    "accepted save retains the same live studio and its editable document");
  assert.deepEqual(await snapshot(), fixture.expected);
  await page.reload();
  await page
    .locator("[data-canvas-editor] [data-studio-status]")
    .filter({ hasText: "Bildstudio bereit" })
    .waitFor();
  assert.deepEqual(
    await page
      .locator("[data-canvas-editor]")
      .evaluate((node) => node.studio.snapshot()),
    fixture.expected,
  );
  check(true, "actual editable feather document survives native server reload");
  check(
    posts === 1 && errors.length === 0 && external.length === 0,
    "actual retouch saves exactly one new own artifact and sends no provider request",
  );
  console.log(
    JSON.stringify({
      suite: "photo-soft-clones-browser",
      checks,
      posts,
      external_requests: external.length,
    }),
  );
  await context.close();
} finally {
  await browser.close();
}
