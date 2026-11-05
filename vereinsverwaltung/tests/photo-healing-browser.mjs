import assert from "node:assert/strict";
import { readFile, mkdir } from "node:fs/promises";
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE);
const fixture = JSON.parse(
  await readFile(process.env.DVM_PHOTO_HEALING_FIXTURE, "utf8"),
);
assert.equal(new URL(fixture.origin).hostname, "127.0.0.1");
const browser = await chromium.launch({
  executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE,
});
let checks = 0,
  posts = 0;
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
    if (!r.url().startsWith(fixture.origin + "/")) outside.push(r.url());
  });
  const page = await context.newPage();
  page.on("pageerror", (e) => errors.push(String(e)));
  await page.goto(fixture.origin + "/?view=social&item=" + fixture.item);
  const studio = page.locator("[data-canvas-editor]");
  await studio
    .locator("[data-studio-status]")
    .filter({ hasText: "Bildstudio bereit" })
    .waitFor();
  const snapshot = () => studio.evaluate((root) => root.studio.snapshot());
  assert.deepEqual(await snapshot(), fixture.document);
  const pixelProof = await page.evaluate(() => {
    const original = () => {
      const canvas = document.createElement("canvas");
      canvas.width = 200;
      canvas.height = 100;
      const context = canvas.getContext("2d");
      context.fillStyle = "rgb(60,80,100)";
      context.fillRect(0, 0, 100, 100);
      context.fillStyle = "rgb(120,150,180)";
      context.fillRect(100, 0, 100, 100);
      context.fillStyle = "rgb(90,110,130)";
      context.fillRect(48, 48, 5, 5);
      context.fillStyle = "rgb(0,0,0)";
      context.fillRect(145, 45, 11, 11);
      return canvas;
    };
    const stamp = {
      source_x: 25,
      source_y: 50,
      target_x: 75,
      target_y: 50,
      radius: 20,
      softness: 50,
    };
    const healed = original(),
      cloned = original();
    KulturhusPhotoClones.apply(healed, [{ ...stamp, mode: "heal" }]);
    KulturhusPhotoClones.apply(cloned, [stamp]);
    const pixels = (surface) =>
      [150, 140, 175, 50].map((x) => [
        ...surface.getContext("2d").getImageData(x, 50, 1, 1).data,
      ]);
    const legacy = original(),
      zero = original();
    const hard = { ...stamp };
    delete hard.softness;
    KulturhusPhotoClones.apply(legacy, [hard]);
    KulturhusPhotoClones.apply(zero, [{ ...hard, softness: 0 }]);
    const left = legacy.getContext("2d").getImageData(0, 0, 200, 100).data,
      right = zero.getContext("2d").getImageData(0, 0, 200, 100).data;
    const invalid = [];
    for (const mode of ["clone", "ai", 0, true, null, []]) {
      try {
        KulturhusPhotoClones.validate([{ ...stamp, mode }]);
        invalid.push(false);
      } catch {
        invalid.push(true);
      }
    }
    const made = [],
      make = document.createElement.bind(document);
    document.createElement = (type, ...args) => {
      const node = make(type, ...args);
      if (type === "canvas") made.push(node);
      return node;
    };
    const target = original();
    made.length = 0;
    try {
      KulturhusPhotoClones.apply(target, [{ ...stamp, mode: "heal" }]);
    } finally {
      document.createElement = make;
    }
    const hardRepair = original();
    hardRepair.getContext("2d").fillStyle = "rgb(220,20,20)";
    hardRepair.getContext("2d").fillRect(31, 31, 2, 2);
    KulturhusPhotoClones.apply(hardRepair, [
      { ...stamp, mode: "heal", softness: 0 },
    ]);
    const hardOutside = [
      ...hardRepair.getContext("2d").getImageData(131, 31, 1, 1).data,
    ];
    const transparent = document.createElement("canvas");
    transparent.width = 200;
    transparent.height = 100;
    const context = transparent.getContext("2d");
    context.fillStyle = "rgb(60,80,100)";
    context.fillRect(0, 0, 100, 100);
    context.fillStyle = "rgba(120,150,180,.5)";
    context.fillRect(100, 0, 100, 100);
    const beforeAlpha = context.getImageData(0, 0, 200, 100).data;
    KulturhusPhotoClones.apply(transparent, [{ ...stamp, mode: "heal" }]);
    const afterAlpha = context.getImageData(0, 0, 200, 100).data;
    const alphaExact = beforeAlpha.every(
      (value, index) => index % 4 !== 3 || value === afterAlpha[index],
    );
    return {
      alphaExact,
      hardOutside,
      healed: pixels(healed),
      cloned: pixels(cloned),
      legacy: left.every((n, i) => n === right[i]),
      invalid,
      released:
        made.length === 2 && made.every((c) => c.width === 0 && c.height === 0),
    };
  });
  check(
    JSON.stringify(pixelProof.healed[0]) ===
      JSON.stringify([150, 180, 210, 255]),
    "actual repair retains source texture while matching the original target colour",
  );
  check(
    JSON.stringify(pixelProof.healed[1]) ===
      JSON.stringify([120, 150, 180, 255]),
    "actual repair matches healthy target pixels around the defect",
  );
  check(
    JSON.stringify(pixelProof.healed[2]) ===
      JSON.stringify([120, 150, 180, 255]) &&
      JSON.stringify(pixelProof.hardOutside) ===
        JSON.stringify([120, 150, 180, 255]),
    "soft and hard repair masks preserve exact outside pixels",
  );
  check(
    JSON.stringify(pixelProof.healed[3]) ===
      JSON.stringify([90, 110, 130, 255]),
    "actual repair never changes the sampled original source",
  );
  check(
    pixelProof.legacy &&
      JSON.stringify(pixelProof.cloned[0]) ===
        JSON.stringify([90, 110, 130, 255]),
    "existing hard and soft cloning remain exact and distinct from repair",
  );
  check(
    pixelProof.invalid.every(Boolean),
    "browser rejects every invalid repair mode without a hidden effect",
  );
  check(
    pixelProof.alphaExact,
    "actual repair preserves every original transparent and partially transparent alpha byte",
  );
  check(
    pixelProof.released,
    "actual repair releases every generated patch and immutable sample buffer",
  );
  await studio.getByRole("tab", { name: "Bearbeiten", exact: true }).click();
  const group = studio.locator("[data-property-group=Retusche]");
  if (!(await group.getAttribute("open")))
    await group.locator(":scope > summary").click();
  const detail = studio.locator("[data-clone-controls]");
  if (!(await detail.getAttribute("open")))
    await detail.locator(":scope > summary").click();
  await detail.locator("[data-clone-mode]").selectOption("heal");
  await detail.locator("[data-clone-field=radius]").fill("20");
  await detail
    .getByRole("button", {
      name: "Direkt auf dem Foto reparieren",
      exact: true,
    })
    .click();
  const dialog = page.locator(".studio-clone-dialog");
  await dialog
    .getByRole("status")
    .filter({ hasText: "Quelle im Foto antippen." })
    .waitFor();
  check(
    (await dialog.locator("[data-paint-mode]").inputValue()) === "heal",
    "actual native studio opens the explicit local repair brush",
  );
  for (const width of [320, 390, 820])
    for (const zoom of [1, 2]) {
      await page.setViewportSize({ width, height: 1000 });
      await page.evaluate((z) => {
        document.documentElement.style.zoom = String(z);
        window.dispatchEvent(new Event("resize"));
      }, zoom);
      const proof = await dialog.evaluate((root) => ({
        fit: root.scrollWidth <= root.clientWidth + 1,
        bounds: root.getBoundingClientRect().height <= innerHeight + 1,
        targets: [...root.querySelectorAll("input,select,button")]
          .filter((n) => n.getBoundingClientRect().height > 0)
          .map((n) => ({
            height: n.getBoundingClientRect().height,
            font: parseFloat(getComputedStyle(n).fontSize),
          })),
      }));
      if (
        !proof.fit ||
        !proof.bounds ||
        proof.targets.some((t) => t.height < 43.5 || t.font < 15.5)
      )
        console.log(JSON.stringify({ width, zoom, proof }));
      check(
        proof.fit && proof.bounds,
        "actual repair dialog fits " + width + "px/" + zoom + "x",
      );
      check(
        proof.targets.every((t) => t.height >= 43.5 && t.font >= 15.5),
        "actual repair tool and controls remain touchable and readable at " +
          width +
          "px/" +
          zoom +
          "x",
      );
    }
  await page.setViewportSize({ width: 390, height: 1000 });
  await page.evaluate(() => {
    document.documentElement.style.zoom = "1";
    window.dispatchEvent(new Event("resize"));
  });
  const canvas = dialog.locator("canvas");
  await canvas.scrollIntoViewIfNeeded();
  let box = await canvas.boundingBox();
  const cdp = await context.newCDPSession(page);
  const point = (x, y) => ({
    x: box.x + (box.width * x) / 100,
    y: box.y + (box.height * y) / 100,
    id: 1,
    radiusX: 2,
    radiusY: 2,
    force: 0.5,
  });
  const touch = (type, value) =>
    cdp.send("Input.dispatchTouchEvent", {
      type,
      touchPoints: value ? [value] : [],
    });
  await touch("touchStart", point(25, 50));
  await touch("touchEnd");
  await touch("touchStart", point(75, 50));
  check(
    await dialog.locator("[data-paint-mode]").isDisabled(),
    "actual finger gesture freezes its chosen repair mode until completion",
  );
  await touch("touchCancel");
  check(
    await dialog
      .getByRole("button", { name: "Anwenden", exact: true })
      .isDisabled(),
    "actual cancelled finger repair drops only the unfinished draft",
  );
  assert.deepEqual(await snapshot(), fixture.document);
  check(true, "cancelled repair retains every exact original editable value");
  await touch("touchStart", point(75, 50));
  await touch("touchEnd");
  await dialog.getByRole("button", { name: "Anwenden", exact: true }).click();
  assert.deepEqual(await snapshot(), fixture.expected);
  check(
    true,
    "actual native editor history retains the exact editable repair source, target, radius and mode",
  );
  await studio.getByRole("button", { name: "Rückgängig", exact: true }).click();
  assert.deepEqual(await snapshot(), fixture.document);
  check(
    true,
    "actual repair undo retains the exact original editable composition",
  );
  await studio
    .getByRole("button", { name: "Wiederherstellen", exact: true })
    .click();
  assert.deepEqual(await snapshot(), fixture.expected);
  check(true, "actual repair redo restores exactly one committed gesture");
  await mkdir(".local/browser-tests/photo-healing", { recursive: true });
  await studio.locator(".studio-export > summary").click();
  const downloading = page.waitForEvent("download");
  await studio.locator("[data-studio=download-png]").click();
  const downloaded = await downloading;
  await downloaded.saveAs(".local/browser-tests/photo-healing/healed.png");
  check(
    downloaded.suggestedFilename().endsWith(".png"),
    "actual repair exports as an independent local PNG without saving",
  );
  const response = page.waitForResponse((r) => r.request().method() === "POST");
  await studio
    .getByRole("button", { name: "Bildfassung speichern", exact: true })
    .click();
  const saved = await response;
  const data = await saved.json();
  check(
    saved.status() === 200 && !data.error,
    "actual repaired native PNG saves through its real current boundary",
  );
  await page
    .locator("[data-canvas-editor] [data-studio-status]")
    .filter({ hasText: "Bildstudio bereit" })
    .waitFor();
  assert.deepEqual(await snapshot(), fixture.expected);
  check(
    true,
    "actual server reload retains the exact editable repair mode and geometry",
  );
  check(
    posts === 1 && outside.length === 0 && errors.length === 0,
    "actual repair stores one private artifact with no external or provider request",
  );
  console.log(
    JSON.stringify({
      suite: "photo-healing-browser",
      checks,
      posts,
      external_transport: false,
    }),
  );
  await context.close();
} finally {
  await browser.close();
}
