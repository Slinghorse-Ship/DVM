import assert from "node:assert/strict";
import { readFile, mkdir } from "node:fs/promises";
import { createHash } from "node:crypto";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const fixture = JSON.parse(
  await readFile(process.env.DVM_MEDIA_STORAGE_FIXTURE, "utf8"),
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
const address = fixture.origin + "/?view=media&section=storage";
const ready = async (page) => {
  for (let i = 0; i < 100; i++) {
    if (
      await page.evaluate(() =>
        [...document.querySelectorAll('link[rel="stylesheet"]')].every(
          (link) => link.sheet,
        ),
      )
    )
      return;
    await new Promise((resolve) => setTimeout(resolve, 100));
  }
  throw Error("Actual native styles required");
};
try {
  for (const js of [true, false]) {
    const context = await browser.newContext({
      javaScriptEnabled: js,
      hasTouch: true,
      viewport: { width: 390, height: 900 },
    });
    context.on("request", (request) => {
      if (request.method() === "POST") posts++;
      if (!request.url().startsWith(fixture.origin + "/"))
        external.push(request.url());
    });
    const page = await context.newPage();
    page.on("pageerror", (error) => errors.push(String(error)));
    await page.goto(address);
    await ready(page);
    check(
      (await page.locator("[data-storage-group]").count()) === 0,
      "unchecked " + js + " view loads no file groups or previews",
    );
    if (js) {
      let checkRequests = 0;
      await page.route("**/*storage_check=1*", async (route) => {
        checkRequests++;
        if (checkRequests === 1)
          return route.fulfill({
            status: 503,
            contentType: "application/json",
            body: JSON.stringify({
              error: "Speicherprüfung unterbrochen. Erneut prüfen.",
            }),
          });
        return route.continue();
      });
      await page
        .getByRole("button", { name: "Speicher prüfen", exact: true })
        .click();
      await page.locator("#notice.error").waitFor();
      check(
        checkRequests === 1 &&
          (await page.locator("[data-storage-group]").count()) === 0 &&
          !page.url().includes("storage_check=1"),
        "failed deliberate read retains the unchecked view and never invents a successful empty scan or automatic retry",
      );
      check(
        await page
          .getByRole("button", { name: "Speicher prüfen", exact: true })
          .isEnabled(),
        "failed deliberate read leaves its own explicit retry available",
      );
      await page
        .getByRole("button", { name: "Speicher prüfen", exact: true })
        .focus();
      await page.keyboard.press("Enter");
      await page.waitForURL(/storage_check=1/);
    } else {
      await Promise.all([
        page.waitForNavigation({ waitUntil: "domcontentloaded" }),
        page
          .getByRole("button", { name: "Speicher prüfen", exact: true })
          .click(),
      ]);
    }
    await page.locator("[data-storage-group]").first().waitFor();
    check(
      (await page.locator("[data-storage-group]").count()) === 6,
      "deliberate " + js + " check shows exactly six own groups",
    );
    for (const width of [320, 390, 820])
      for (const zoom of [1, 2]) {
        await page.setViewportSize({ width, height: 900 });
        await page.evaluate((value) => {
          document.documentElement.style.zoom = String(value);
        }, zoom);
        await ready(page);
        const geometry = await page
          .locator("#media-storage")
          .evaluate((root) => ({
            fits:
              document.documentElement.scrollWidth <=
              document.documentElement.clientWidth + 1,
            controls: [...root.querySelectorAll("button,summary,a")]
              .filter((node) => node.getClientRects().length)
              .map((node) => ({
                height: node.getBoundingClientRect().height,
                font: parseFloat(getComputedStyle(node).fontSize),
              })),
            groups: root.querySelectorAll("[data-storage-group]").length,
          }));
        check(
          geometry.fits && geometry.groups === 6,
          "actual " +
            js +
            " layout fits " +
            width +
            "px at " +
            zoom +
            "x zoom with bounded groups",
        );
        for (const control of geometry.controls)
          check(
            control.height >= 44 * zoom - 1 && control.font >= 16,
            "actual " +
              js +
              " storage control retains touch size and readable text",
          );
      }
    await page.evaluate(() => {
      document.documentElement.style.zoom = "1";
    });
    await page.setViewportSize({ width: 390, height: 900 });
    await page
      .locator("[data-storage-group]")
      .first()
      .locator("summary")
      .click();
    check(
      (await page
        .locator("[data-storage-group]")
        .first()
        .locator("details")
        .getAttribute("open")) !== null &&
        (await page
          .locator("[data-storage-group]")
          .first()
          .locator("li")
          .count()) === 2,
      "actual " +
        js +
        " names open locally without another request or long default content",
    );
    const response = await context.request.get(
      fixture.origin + "/media-storage/" + fixture.ids[0] + "/thumb",
    );
    check(
      response.status() === 200 &&
        createHash("sha256")
          .update(await response.body())
          .digest("hex") === fixture.thumb_sha256,
      "actual " + js + " private image has exact independent original pixels",
    );
    const image = page.locator("[data-storage-group]").first().locator("img");
    await image.scrollIntoViewIfNeeded();
    for (let i = 0; i < 100; i++) {
      if (
        await image.evaluate((node) => node.complete && node.naturalWidth > 0)
      )
        break;
      await new Promise((resolve) => setTimeout(resolve, 50));
    }
    check(
      await image.evaluate(
        (node) => node.naturalWidth === 40 && node.naturalHeight === 32,
      ),
      "actual " + js + " small preview decodes in the browser",
    );
    if (js) {
      await mkdir(".local/browser-tests/media-storage", { recursive: true });
      await page.screenshot({
        path: ".local/browser-tests/media-storage/mobile.png",
        fullPage: false,
      });
    }
    await page
      .getByRole("link", { name: "Nächste Dateigruppen →", exact: true })
      .first()
      .click();
    await page.waitForURL(new RegExp("storage_after=" + fixture.ids[5]));
    await page
      .locator('[data-storage-group="' + fixture.ids[6] + '"]')
      .waitFor();
    check(
      (await page.locator("[data-storage-group]").count()) === 6 &&
        (await page.locator("[data-storage-group] img").count()) === 2,
      "actual " +
        js +
        " second page skips unsafe thumbnail bytes without hiding exact file groups",
    );
    await page
      .getByRole("link", { name: "Nächste Dateigruppen →", exact: true })
      .first()
      .click();
    await page.waitForURL(new RegExp("storage_after=" + fixture.ids[11]));
    await page
      .locator('[data-storage-group="' + fixture.ids[12] + '"]')
      .waitFor();
    check(
      (await page.locator("[data-storage-group]").count()) === 1 &&
        (await page.locator("[data-storage-group] img").count()) === 0 &&
        (await page
          .getByRole("link", { name: "Nächste Dateigruppen →", exact: true })
          .count()) === 0,
      "actual " +
        js +
        " last page retains inaccessible originals without a false preview or endless continuation",
    );
    await page
      .getByRole("link", { name: "Zum Anfang", exact: true })
      .first()
      .click();
    await page.waitForURL(/storage_after=$/);
    await page
      .locator('[data-storage-group="' + fixture.ids[0] + '"]')
      .waitFor();
    check(
      (await page.locator("[data-storage-group]").count()) === 6,
      "actual " +
        js +
        " bounded navigation returns directly to the first six groups",
    );
    await context.close();
  }
  check(
    posts === 0 && external.length === 0 && errors.length === 0,
    "actual JS and native forms send no writes, external traffic or page errors",
  );
  console.log(
    JSON.stringify({
      suite: "media-storage-browser",
      checks,
      posts,
      external_requests: external.length,
    }),
  );
} finally {
  await browser.close();
}
