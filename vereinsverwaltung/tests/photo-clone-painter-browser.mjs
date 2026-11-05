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
    acceptDownloads: true,
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
  const snapshot = () => studio.evaluate((node) => node.studio.snapshot());
  await studio
    .locator("[data-studio-status]")
    .filter({ hasText: "Bildstudio bereit" })
    .waitFor();
  const original = await snapshot();
  assert.deepEqual(original, fixture.document);
  await studio.getByRole("tab", { name: "Bearbeiten", exact: true }).click();
  const expand = async () => {
    const group = studio.locator("[data-property-group=Retusche]");
    if ((await group.getAttribute("open")) === null)
      await group.locator(":scope > summary").click();
    const detail = studio.locator("[data-clone-controls]");
    if ((await detail.getAttribute("open")) === null)
      await detail.locator(":scope > summary").click();
    return detail;
  };
  let detail = await expand();
  await detail.locator("[data-clone-field=radius]").fill("10");
  const open = async () => {
    await detail
      .getByRole("button", {
        name: "Direkt auf dem Foto stempeln",
        exact: true,
      })
      .click();
    const dialog = page.getByRole("dialog", {
      name: "Foto retuschieren",
      exact: true,
    });
    await dialog
      .getByRole("status")
      .filter({ hasText: "Quelle im Foto antippen" })
      .waitFor();
    return dialog;
  };
  let dialog = await open();
  const geometry = async () => {
    const box = await dialog.locator("canvas").boundingBox();
    assert(box);
    return (x, y) => ({
      x: box.x + (box.width * x) / 100,
      y: box.y + (box.height * y) / 100,
    });
  };
  for (const width of [320, 390, 820])
    for (const zoom of [1, 2]) {
      await page.setViewportSize({ width, height: 1000 });
      await page.evaluate((value) => {
        document.documentElement.style.zoom = String(value);
        window.dispatchEvent(new Event("resize"));
      }, zoom);
      const box = await dialog.boundingBox();
      check(
        box &&
          box.x >= -1 &&
          box.y >= -1 &&
          box.x + box.width <= width + 1 &&
          box.y + box.height <= 1001,
        "actual retouch dialog remains within " +
          width +
          "px viewport at " +
          zoom +
          "x zoom",
      );
      const sizes = await dialog.evaluate((root) =>
        [...root.querySelectorAll("button,input")].map((element) => ({
          height: element.getBoundingClientRect().height,
          font: parseFloat(getComputedStyle(element).fontSize),
        })),
      );
      check(
        sizes.length === 7 &&
          sizes.every(
            (item) => item.height >= 44 * zoom - 1 && item.font >= 16,
          ),
        "actual direct retouch controls remain touchable and readable " +
          JSON.stringify(sizes),
      );
      check(
        await dialog.evaluate(
          (root) => root.scrollWidth <= root.clientWidth + 1,
        ),
        "actual retouch dialog has no horizontal overflow",
      );
    }
  await page.evaluate(() => {
    document.documentElement.style.zoom = "1";
    window.dispatchEvent(new Event("resize"));
  });
  await page.setViewportSize({ width: 390, height: 1000 });
  const cdp = await context.newCDPSession(page);
  const touch = async (type, target) =>
    cdp.send("Input.dispatchTouchEvent", {
      type,
      touchPoints: target
        ? [{ ...target, id: 1, radiusX: 2, radiusY: 2, force: 0.5 }]
        : [],
    });
  let coordinate = await geometry();
  const beforeSourceBox = await dialog.locator("canvas").boundingBox();
  await touch("touchStart", coordinate(25, 50));
  await touch("touchEnd");
  check(
    (await dialog
      .getByRole("button", { name: "Stempeln", exact: true })
      .getAttribute("aria-pressed")) === "true",
    "actual primary finger picks the original photo source and enters paint mode",
  );
  const afterSourceBox = await dialog.locator("canvas").boundingBox();
  check(
    ["x", "y", "width", "height"].every(
      (key) => Math.abs(beforeSourceBox[key] - afterSourceBox[key]) <= 0.5,
    ),
    "changing a retouch prompt never moves the photo underneath the finger",
  );
  await touch("touchStart", coordinate(65, 50));
  await dialog.locator("canvas").dispatchEvent("lostpointercapture", {
    pointerId: 99,
    pointerType: "touch",
    isPrimary: false,
  });
  await touch("touchMove", coordinate(80, 50));
  await touch("touchEnd");
  await dialog
    .getByRole("status")
    .filter({ hasText: "7 neue Stempel" })
    .waitFor();
  assert.deepEqual(await snapshot(), original);
  check(
    true,
    "actual finger stroke ignores another pointer capture loss and samples aligned source positions without mutating the main document before Apply",
  );
  await page
    .waitForFunction(() => {
      const canvas = document.querySelector(".studio-clone-dialog canvas"),
        pixel = canvas
          .getContext("2d")
          .getImageData(
            Math.round(canvas.width * 0.8),
            Math.round(canvas.height * 0.5),
            1,
            1,
          ).data;
      return pixel[0] === 240 && pixel[2] === 20;
    })
    .catch(async (error) => {
      const proof = await dialog.locator("canvas").evaluate((canvas) => ({
        width: canvas.width,
        height: canvas.height,
        pixels: [25, 65, 70, 75, 80].map((x) => [
          x,
          [
            ...canvas
              .getContext("2d")
              .getImageData(
                Math.round((canvas.width * x) / 100),
                Math.round(canvas.height / 2),
                1,
                1,
              ).data,
          ],
        ]),
      }));
      throw Error(
        error.message + "; actual paint pixels " + JSON.stringify(proof),
      );
    });
  check(
    true,
    "actual finger preview contains the expected original red source pixels",
  );
  await dialog
    .getByRole("button", { name: "Strich zurück", exact: true })
    .click();
  check(
    await dialog
      .getByRole("button", { name: "Anwenden", exact: true })
      .isDisabled(),
    "local stroke undo removes the complete gesture rather than one sampled dot",
  );
  await touch("touchStart", coordinate(65, 50));
  await touch("touchMove", coordinate(70, 50));
  await touch("touchCancel");
  await dialog
    .getByRole("status")
    .filter({ hasText: "Strich verworfen" })
    .waitFor();
  check(
    await dialog
      .getByRole("button", { name: "Anwenden", exact: true })
      .isDisabled(),
    "actual cancelled touch drops every point of only that unfinished stroke",
  );
  assert.deepEqual(await snapshot(), original);
  check(
    true,
    "cancelled touch preserves every exact current composition value",
  );
  await dialog.locator("canvas").evaluate((node) => {
    window.ownClosedPaintCanvas = node;
  });
  await dialog.getByRole("button", { name: "Verwerfen", exact: true }).click();
  check(
    await page.evaluate(
      () =>
        !window.ownClosedPaintCanvas.isConnected &&
        window.ownClosedPaintCanvas.width === 0 &&
        window.ownClosedPaintCanvas.height === 0,
    ),
    "closing direct retouch removes the actual dialog and releases its visible pixel buffer",
  );
  check(
    await detail
      .getByRole("button", {
        name: "Direkt auf dem Foto stempeln",
        exact: true,
      })
      .evaluate((button) => button === document.activeElement),
    "retouch close returns keyboard focus to its real opener",
  );
  dialog = await open();
  const pen = dialog.locator("canvas");
  await pen.focus();
  for (let i = 0; i < 5; i++) await pen.press("Shift+ArrowLeft");
  await pen.press("Enter");
  for (let i = 0; i < 8; i++) await pen.press("Shift+ArrowRight");
  await pen.press("Enter");
  await dialog
    .getByRole("status")
    .filter({ hasText: "1 neue Stempel" })
    .waitFor();
  check(
    !(await dialog
      .getByRole("button", { name: "Anwenden", exact: true })
      .isDisabled()),
    "actual keyboard selects a source and creates a usable local stamp",
  );
  await dialog
    .getByRole("button", { name: "Strich zurück", exact: true })
    .click();
  await pen.dispatchEvent("pointerdown", {
    pointerId: 99,
    pointerType: "touch",
    isPrimary: false,
    button: 0,
    clientX: 1,
    clientY: 1,
  });
  check(
    await dialog
      .getByRole("button", { name: "Anwenden", exact: true })
      .isDisabled(),
    "secondary pointer cannot start an unintended new stroke",
  );
  await dialog.locator("[data-paint-field=radius]").fill("0");
  await pen.press("Enter");
  await dialog
    .getByRole("status")
    .filter({ hasText: "Gültigen Radius" })
    .waitFor();
  check(
    await dialog
      .getByRole("button", { name: "Anwenden", exact: true })
      .isDisabled(),
    "invalid local brush setting creates no stamp and no main composition change",
  );
  await dialog.locator("[data-paint-field=radius]").fill("10");
  coordinate = await geometry();
  await cdp.send("Input.dispatchMouseEvent", {
    type: "mousePressed",
    ...coordinate(65, 50),
    button: "left",
    buttons: 1,
    clickCount: 1,
    pointerType: "pen",
    force: 0.5,
  });
  await cdp.send("Input.dispatchMouseEvent", {
    type: "mouseMoved",
    ...coordinate(80, 50),
    buttons: 1,
    pointerType: "pen",
    force: 0.5,
  });
  await cdp.send("Input.dispatchMouseEvent", {
    type: "mouseReleased",
    ...coordinate(80, 50),
    button: "left",
    buttons: 0,
    clickCount: 1,
    pointerType: "pen",
  });
  await dialog
    .getByRole("status")
    .filter({ hasText: "7 neue Stempel" })
    .waitFor();
  check(
    true,
    "actual Chromium pen pointer creates the same bounded aligned stroke as a finger",
  );
  await dialog
    .getByRole("button", { name: "Strich zurück", exact: true })
    .click();
  await dialog.locator("[data-paint-field=radius]").fill("1");
  coordinate = await geometry();
  for (let i = 0; i < 2; i++) {
    await touch("touchStart", coordinate(65, 50));
    await touch("touchMove", coordinate(89, 50));
    await touch("touchEnd");
  }
  await dialog
    .getByRole("status")
    .filter({ hasText: "Stempelgrenze erreicht" })
    .waitFor();
  check(
    true,
    "actual fast finger strokes stop at the per-photo limit with a retained explicit correction",
  );
  await dialog.getByRole("button", { name: "Verwerfen", exact: true }).click();
  assert.deepEqual(await snapshot(), original);
  check(
    true,
    "discarding a full-limit local draft leaves the exact original editor untouched",
  );
  const sourceUrl = await studio
    .locator("[data-canvas-seed]")
    .evaluate(
      (input, id) =>
        JSON.parse(input.value).media.find((photo) => photo.id === id).url,
      original.layers[0].media_ref,
    );
  const failedPage = await context.newPage();
  failedPage.on("pageerror", (error) => errors.push(String(error)));
  const imagePath = new URL(sourceUrl, page.url()).pathname;
  await failedPage.route("**/*", (route) =>
    new URL(route.request().url()).pathname === imagePath
      ? route.abort("failed")
      : route.continue(),
  );
  await failedPage.goto(page.url());
  const failedStudio = failedPage.locator("[data-canvas-editor]");
  await failedStudio
    .locator("[data-studio-status]")
    .filter({ hasText: "Ein Quellbild konnte nicht geladen" })
    .waitFor();
  await failedStudio
    .getByRole("tab", { name: "Bearbeiten", exact: true })
    .click();
  await failedStudio
    .locator("[data-property-group=Retusche] > summary")
    .click();
  await failedStudio.locator("[data-clone-controls] > summary").click();
  await failedStudio
    .getByRole("button", { name: "Direkt auf dem Foto stempeln", exact: true })
    .click();
  const failedDialog = failedPage.getByRole("dialog", {
    name: "Foto retuschieren",
    exact: true,
  });
  await failedDialog
    .getByRole("status")
    .filter({ hasText: "Ein Quellbild konnte nicht geladen" })
    .waitFor();
  check(
    (await failedDialog
      .getByRole("button", { name: "Anwenden", exact: true })
      .isDisabled()) &&
      (await failedDialog
        .getByRole("button", { name: "Verwerfen", exact: true })
        .isEnabled()),
    "actual failed private photo request leaves a readable local failure and usable exit without applying a draft",
  );
  assert.deepEqual(
    await failedStudio.evaluate((node) => node.studio.snapshot()),
    original,
  );
  await failedPage.keyboard.press("Escape");
  check(
    (await failedPage.getByRole("dialog").count()) === 0,
    "actual Escape closes failed retouch without saving or changing the original document",
  );
  await failedPage.close();
  dialog = await open();
  coordinate = await geometry();
  await touch("touchStart", coordinate(25, 50));
  await touch("touchEnd");
  await touch("touchStart", coordinate(65, 50));
  await touch("touchMove", coordinate(80, 50));
  await touch("touchEnd");
  await dialog
    .getByRole("status")
    .filter({ hasText: "7 neue Stempel" })
    .waitFor();
  await mkdir(".local/browser-tests/photo-clone-painter", { recursive: true });
  await page.screenshot({
    path: ".local/browser-tests/photo-clone-painter/mobile.png",
    fullPage: false,
  });
  await dialog.getByRole("button", { name: "Anwenden", exact: true }).click();
  check(
    await studio
      .locator("[data-studio=undo]")
      .evaluate((button) => button === document.activeElement),
    "explicit retouch Apply returns focus to the normal editor undo action",
  );
  assert.deepEqual(await snapshot(), fixture.expected);
  check(
    true,
    "actual Apply stores exactly the seven expected aligned source/target pairs and only their soft brush settings",
  );
  await studio.getByRole("button", { name: "Rückgängig", exact: true }).click();
  assert.deepEqual(await snapshot(), original);
  check(
    true,
    "one normal editor Undo removes the complete explicitly applied retouch draft",
  );
  await studio
    .getByRole("button", { name: "Wiederherstellen", exact: true })
    .click();
  assert.deepEqual(await snapshot(), fixture.expected);
  check(true, "normal editor Redo restores every exact editable stroke stamp");
  await studio.locator(".studio-export > summary").click();
  const download = page.waitForEvent("download");
  await studio.locator("[data-studio=download-png]").click();
  await (
    await download
  ).saveAs(".local/browser-tests/photo-clone-painter/painted.png");
  check(
    true,
    "actual direct retouch exports locally as a PNG without saving or provider requests",
  );
  const originalStudio = await studio.elementHandle();
  const response = page.waitForResponse(
    (result) => result.request().method() === "POST",
  );
  await studio
    .getByRole("button", { name: "Bildfassung speichern", exact: true })
    .click();
  check(
    (await response).status() === 200,
    "actual native save accepts the applied editable stroke document",
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
  assert.deepEqual(await snapshot(), fixture.expected);
  check(
    true,
    "exact editable aligned stroke document survives an actual native server reload",
  );
  check(
    posts === 1 && external.length === 0 && errors.length === 0,
    "all direct retouch creates exactly one own artifact and no provider request or script error",
  );
  console.log(
    JSON.stringify({
      suite: "photo-clone-painter-browser",
      checks,
      posts,
      external_requests: external.length,
    }),
  );
} finally {
  await browser.close();
}
