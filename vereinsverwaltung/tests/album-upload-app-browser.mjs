import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { createHash } from "node:crypto";
import { fileURLToPath } from "node:url";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const root = fileURLToPath(new URL("../../", import.meta.url));
const browser = await chromium.launch({
  executablePath:
    process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || "/usr/bin/chromium",
});
const session = JSON.parse(
  await readFile(
    root + ".local/browser-tests/controls-sessions/admin.json",
    "utf8",
  ),
);
let checks = 0;
const external = [],
  writes = [];
const check = (ok, label) => {
  assert(ok, label);
  checks++;
  console.log("PASS " + label);
};
try {
  for (const standalone of [true, false]) {
    const name = standalone ? "standalone" : "integrated",
      origin = "http://127.0.0.1:" + (standalone ? 8107 : 8111),
      f = JSON.parse(
        await readFile(
          root + ".local/browser-tests/album-upload-" + name + ".json",
          "utf8",
        ),
      );
    const context = await browser.newContext({
      javaScriptEnabled: false,
      hasTouch: true,
      viewport: { width: 320, height: 880 },
      storageState: standalone ? undefined : session,
    });
    await context.route("**/*", (r) => {
      if (new URL(r.request().url()).origin !== origin) {
        external.push(r.request().url());
        return r.abort();
      }
      if (r.request().method() === "POST") writes.push(r.request().url());
      return r.continue();
    });
    const p = await context.newPage();
    p.setDefaultTimeout(30000);
    const route =
      origin +
      (standalone ? "/?" : "/vereinsverwaltung/?") +
      new URLSearchParams(
        standalone
          ? { view: "media", item: f.album }
          : { view: "website", tab: "gallery", native_item: f.album },
      );
    const photos = async (suffix, bad = false) =>
      Promise.all(
        ["a", bad ? "bad" : "b"].map(async (key, i) => ({
          name: f.prefix + "-" + suffix + "-" + i + ".png",
          mimeType: "image/png",
          buffer: await readFile(f.sources[key].path),
        })),
      );
    for (const source of Object.values(f.sources))
      check(
        createHash("sha256")
          .update(await readFile(source.path))
          .digest("hex") === source.sha256,
        name + " exact own selected source bytes",
      );
    let version = f.version;
    const upload = async (files, expected) => {
      await p.locator("[data-album-upload]").setInputFiles(files);
      const response = p.waitForResponse(
        (r) =>
          r.request().method() === "POST" && new URL(r.url()).origin === origin,
      );
      await p
        .getByRole("button", { name: "Bilder hochladen", exact: true })
        .click();
      const r = await response;
      check(
        r.status() === expected,
        name + " genuine no-JavaScript upload HTTP " + expected,
      );
      await p.waitForLoadState("domcontentloaded");
    };
    for (const width of [320, 390, 820]) {
      await p.setViewportSize({ width, height: 880 });
      await p.goto(route, { waitUntil: "domcontentloaded" });
      check(
        await p
          .locator("[data-album-upload]")
          .evaluate((input) => input.name === "image[]" && input.multiple),
        name + " complete native multiple field at " + width,
      );
      await upload(await photos("width-" + width), 303);
      version++;
      await p.waitForFunction(
        (v) =>
          Number(
            document.querySelector("[data-album-version]")?.dataset
              .albumVersion,
          ) === v,
        version,
      );
      check(
        (await p.locator(".album-image").count()) === (version - f.version) * 2,
        name + " both selected images appear exactly once at " + width,
      );
      check(
        (await p.locator(".album-title").textContent()) ===
          f.prefix + " exact album Ä",
        name + " saved title remains exact",
      );
      check(
        await p
          .locator("[data-album-upload]")
          .evaluate((input) => !input.files.length && !input.disabled),
        name + " native field is ready for another selection",
      );
      await p.evaluate(() => (document.body.style.zoom = "2"));
      // JavaScript is disabled in the tested page; poll from the driver after navigation.
      let styled = false;
      for (let attempt = 0; attempt < 100; attempt++) {
        styled = await p.evaluate(() =>
          [...document.querySelectorAll('link[rel="stylesheet"]')].every(
            (link) => link.sheet,
          ),
        );
        if (styled) break;
        await new Promise((resolve) => setTimeout(resolve, 100));
      }
      assert(styled, name + " actual native styles finish loading");
      check(
        await p.evaluate(
          () =>
            document.documentElement.scrollWidth <=
            document.documentElement.clientWidth + 2,
        ),
        name + " album native upload fits " + width + " at 200 percent",
      );
      check(
        await p
          .getByRole("button", { name: "Bilder hochladen", exact: true })
          .evaluate((b) => {
            const r = b.getBoundingClientRect();
            return r.width >= 44 && r.height >= 44;
          }),
        name + " native batch action has a touch target",
      );
      await p.evaluate(() => (document.body.style.zoom = ""));
    }
    await upload(await photos("rejected", true), 422);
    check(
      (await p.locator("[data-album-upload]").count()) === 1,
      name + " failed batch keeps the actual album upload form",
    );
    check(
      (await p.locator(".album-image").count()) === 6 &&
        Number(
          await p
            .locator("[data-album-version]")
            .getAttribute("data-album-version"),
        ) === version,
      name + " failed second photo leaves the whole album unchanged",
    );
    check(
      (await p.getByRole("alert").count()) > 0,
      name + " correction shows the actual upload error",
    );
    await upload(await photos("retry"), 303);
    version++;
    await p.waitForFunction(
      (v) =>
        Number(
          document.querySelector("[data-album-version]")?.dataset.albumVersion,
        ) === v,
      version,
    );
    check(
      (await p.locator(".album-image").count()) === 6 &&
        (await p.locator("[data-album-photo-count]").textContent()) === "8",
      name + " corrected complete selection succeeds without leaving the album",
    );
    const pagination = p.locator(".album-image-pager").first();
    await pagination
      .getByLabel("Bilderseite", { exact: true })
      .selectOption("2");
    await pagination
      .getByRole("button", { name: "Anzeigen", exact: true })
      .click();
    await p.waitForLoadState("domcontentloaded");
    check(
      (await p.locator(".album-image").count()) === 2,
      name + " actual native page choice reaches both final saved photos",
    );
    await context.close();
  }
  check(
    external.length === 0,
    "native batch browser makes no external request",
  );
  check(writes.length === 10, "only ten deliberate native batch submissions");
  console.log(
    JSON.stringify({
      checks,
      writes: writes.length,
      external_service_calls: false,
      no_javascript: true,
    }),
  );
} finally {
  await browser.close();
}
