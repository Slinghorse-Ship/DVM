import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const root = fileURLToPath(new URL("../../", import.meta.url));
const browser = await chromium.launch({ executablePath: "/usr/bin/chromium" });
let checks = 0;
const errors = [],
  external = [],
  writes = [];
const check = (ok, label) => {
  assert(ok, label);
  checks++;
  console.log("PASS " + label);
};
try {
  for (const standalone of [true, false]) {
    const name = standalone ? "standalone" : "integrated",
      origin = "http://127.0.0.1:" + (standalone ? 8107 : 8111);
    const f = JSON.parse(
      await readFile(
        root + ".local/browser-tests/photo-editor-" + name + ".json",
        "utf8",
      ),
    );
    const storage = standalone
      ? undefined
      : JSON.parse(
          await readFile(
            root + ".local/browser-tests/controls-sessions/admin.json",
            "utf8",
          ),
        );
    const context = await browser.newContext({
      storageState: storage,
      viewport: { width: 390, height: 850 },
      hasTouch: true,
    });
    await context.route("**/*", (r) => {
      if (new URL(r.request().url()).origin !== origin) {
        external.push(r.request().url());
        return r.abort();
      }
      if (r.request().method() === "POST") writes.push(r.request().url());
      return r.continue();
    });
    const page = await context.newPage();
    page.on("pageerror", (e) => errors.push(e.message));
    await page.goto(
      origin +
        (standalone
          ? "/?view=social&item="
          : "/vereinsverwaltung/?view=social&native_item=") +
        f.social,
      { waitUntil: "domcontentloaded" },
    );
    const studio = page.locator("[data-canvas-editor]");
    await page.waitForFunction(
      () => !!document.querySelector("[data-canvas-editor]")?.studio,
    );
    if (
      (await studio.locator("[data-studio-status]").textContent()).includes(
        "breiter",
      )
    ) {
      await studio
        .getByRole("tab", { name: "Bearbeiten", exact: true })
        .click();
      await studio.locator("[data-property=text]").fill("Eigener Kulturabend");
      await page.waitForFunction(
        () =>
          !document
            .querySelector("[data-studio-status]")
            ?.textContent.includes("breiter"),
      );
      check(
        true,
        name +
          " an overwide original title stays editable and resumes after a real user correction",
      );
    }
    await studio.getByRole("tab", { name: "Hinzufügen", exact: true }).click();
    const original = await studio.evaluate((e) =>
      JSON.stringify(e.studio.snapshot()),
    );
    for (const width of [320, 390, 820]) {
      await page.setViewportSize({ width, height: 850 });
      await studio
        .getByRole("button", { name: "Großansicht", exact: true })
        .click();
      const dialog = page.getByRole("dialog", {
        name: "Bildstudio · Großansicht",
      });
      const boxes = await Promise.all(
        [
          dialog,
          dialog.locator("canvas"),
          dialog.locator(".studio-controls"),
          dialog.getByRole("button", {
            name: "Bildfassung speichern",
            exact: true,
          }),
        ].map((e) => e.boundingBox()),
      );
      check(
        boxes.every(
          (b) =>
            b &&
            b.x >= -1 &&
            b.y >= -1 &&
            b.x + b.width <= width + 1 &&
            b.y + b.height <= 851,
        ),
        name +
          " " +
          width +
          " actual canvas, inspector and save fit the device viewport",
      );
      check(
        (await dialog
          .getByRole("tabpanel")
          .filter({ visible: true })
          .count()) === 1,
        name + " " + width + " keeps exactly one active tool panel",
      );
      await page.keyboard.press("Escape");
      check(
        (await page.getByRole("dialog").count()) === 0 &&
          (await studio.evaluate((e) =>
            JSON.stringify(e.studio.snapshot()),
          )) === original,
        name + " " + width + " view and escape preserve every artwork value",
      );
    }
    await page.setViewportSize({ width: 390, height: 850 });
    await studio
      .getByRole("button", { name: "Großansicht", exact: true })
      .click();
    let dialog = page.getByRole("dialog");
    const photoValue = await dialog
      .locator("[data-studio-photo] option")
      .evaluateAll(
        (options, prefix) =>
          options.find((o) => o.textContent.includes(prefix + "library"))
            ?.value,
        f.prefix,
      );
    check(
      !!photoValue,
      name + " chooses only the new own camera fixture photo",
    );
    await dialog.locator("[data-studio-photo]").selectOption(photoValue);
    check(
      (await dialog
        .getByRole("tab", { name: "Bearbeiten", exact: true })
        .getAttribute("aria-selected")) === "true",
      name + " selecting a photo opens its compact properties",
    );
    check(
      await dialog
        .locator(".studio-property-group")
        .evaluateAll((es) => es.length >= 3 && es.every((e) => !e.open)),
      name + " photo geometry, colors and crop start collapsed",
    );
    await dialog.getByRole("tab", { name: "Hinzufügen", exact: true }).click();
    await dialog.getByRole("button", { name: "＋ Text", exact: true }).click();
    await dialog
      .locator("[data-property=text]")
      .fill("Unser eigener Kulturabend");
    const edited = await studio.evaluate((e) =>
      JSON.stringify(e.studio.snapshot()),
    );
    await dialog
      .getByRole("button", { name: "Schließen", exact: true })
      .click();
    check(
      (await studio.evaluate((e) => JSON.stringify(e.studio.snapshot()))) ===
        edited,
      name + " closing preserves the actual unsaved text and photo layers",
    );
    check(
      await studio
        .getByRole("button", { name: "Großansicht", exact: true })
        .evaluate((e) => e === document.activeElement),
      name + " close restores keyboard focus to its original launcher",
    );
    const history = page.locator(".social-image-history img[data-original]");
    const before = (await history.count())
      ? await history.first().getAttribute("data-original")
      : "";
    await studio
      .getByRole("button", { name: "Großansicht", exact: true })
      .click();
    dialog = page.getByRole("dialog");
    const response = page.waitForResponse(
      (r) =>
        r.request().method() === "POST" && new URL(r.url()).origin === origin,
    );
    await dialog
      .getByRole("button", { name: "Bildfassung speichern", exact: true })
      .click();
    check(
      (await response).status() < 400,
      name +
        " explicit original canvas form really saves the new private artwork",
    );
    await page.waitForFunction(
      (before) => {
        const image = document.querySelector(
          ".social-image-history img[data-original]",
        );
        return image && image.dataset.original !== before;
      },
      before,
      { timeout: 60000 },
    );
    const saved = page.locator("[data-canvas-editor]");
    await saved
      .locator("[data-studio-status]")
      .filter({ hasText: "Bildstudio bereit" })
      .waitFor();
    assert.deepEqual(
      await saved.evaluate((e) => e.studio.snapshot()),
      JSON.parse(edited),
    );
    check(
      true,
      name + " exact layer values survive the authenticated server round trip",
    );
    check(
      (await page.getByRole("dialog").count()) === 0 &&
        (await page.evaluate(
          () =>
            !document.documentElement.classList.contains("studio-modal-lock"),
        )),
      name + " completed save removes the modal and scroll lock",
    );
    const url = await page
      .locator(".social-image-history img[data-original]")
      .first()
      .getAttribute("data-original");
    const pixelsResponse = await context.request.get(
      new URL(url, page.url()).href,
    );
    const bytes = await pixelsResponse.body();
    check(
      pixelsResponse.status() === 200 &&
        bytes
          .subarray(0, 8)
          .equals(Buffer.from([137, 80, 78, 71, 13, 10, 26, 10])) &&
        bytes.readUInt32BE(16) === 1080 &&
        bytes.readUInt32BE(20) === 1350,
      name + " stored original is a real authorized 1080 by 1350 PNG",
    );
    const color = await page.evaluate(
      async (data) => {
        const im = await createImageBitmap(
          new Blob([new Uint8Array(data)], { type: "image/png" }),
        );
        const c = document.createElement("canvas");
        c.width = im.width;
        c.height = im.height;
        const x = c.getContext("2d");
        x.drawImage(im, 0, 0);
        return [...x.getImageData(300, 600, 1, 1).data];
      },
      [...bytes],
    );
    check(
      Math.abs(color[0] - 35) < 12 &&
        Math.abs(color[1] - 140) < 12 &&
        Math.abs(color[2] - 210) < 12,
      name + " persisted artwork contains the actual own HEIC source colors",
    );
    await context.close();
  }
  check(
    writes.length === 2,
    "only two explicit private artwork saves; view and tool changes send nothing",
  );
  check(
    errors.length === 0,
    "no studio layout runtime errors: " + errors.join("; "),
  );
  check(external.length === 0, "no external studio layout requests");
  console.log(
    JSON.stringify({
      checks,
      writes: writes.length,
      external_service_calls: false,
      own_artwork: true,
    }),
  );
} finally {
  await browser.close();
}
