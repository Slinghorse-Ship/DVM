import assert from "node:assert/strict";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const browser = await chromium.launch({
  executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE,
});
const origin = process.env.DVM_PUBLIC_ALIAS_ORIGIN;
assert.match(origin, /^http:\/\/127\.0\.0\.1:\d+$/);
let checks = 0,
  writes = 0;
const errors = [],
  external = [];
const check = (ok, label) => {
  assert(ok, label);
  checks++;
  console.log("PASS " + label);
};
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
  throw Error("Actual alias styles did not load");
};
try {
  for (const js of [true, false]) {
    const mode = js ? "JS" : "native";
    const context = await browser.newContext({
      javaScriptEnabled: js,
      hasTouch: true,
      viewport: { width: 320, height: 880 },
    });
    await context.route("**/*", (route) => {
      if (new URL(route.request().url()).origin !== origin) {
        external.push(route.request().url());
        return route.abort();
      }
      if (!["GET", "HEAD"].includes(route.request().method())) writes++;
      return route.continue();
    });
    const page = await context.newPage();
    page.on("pageerror", (error) => errors.push(error.message));
    for (const slug of [
      "kontakt",
      "impressum",
      "datenschutz",
      "datenloeschung",
      "nutzungsbedingungen",
      "satzung",
      "verein",
      "verein/team/kontakt",
      "veranstaltungen",
      "veranstaltungen/own-original",
      "aktuelles",
      "aktuelles/own-original",
      "galerie",
      "galerie/own-original",
    ]) {
      const response = await page.goto(origin + "/" + slug + "/", {
        waitUntil: "domcontentloaded",
      });
      await ready(page);
      check(
        response.ok() && new URL(page.url()).pathname === "/" + slug + "/",
        mode +
          " actual anonymous " +
          slug +
          " retains its original direct address",
      );
      check(
        (await page
          .locator('link[href*="bootstrap-6.0.0-alpha.1.min.css"]')
          .count()) === 1,
        mode + " direct " + slug + " uses the local public base once",
      );
      check(
        !(await page.content()).includes("PRIVATE ALIAS") &&
          !(await page.content()).includes("FOREIGN ALIAS"),
        mode + " direct " + slug + " retains the exact public boundary",
      );
      for (const width of [320, 390, 820]) {
        await page.setViewportSize({ width, height: 880 });
        await page.evaluate(() => {
          document.body.style.zoom = "2";
        });
        check(
          await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth + 1,
          ),
          mode +
            " actual direct " +
            slug +
            " fits " +
            width +
            " at 200 percent",
        );
      }
      check(
        await page
          .locator(".site-footer a")
          .evaluateAll((elements) =>
            elements.every(
              (element) => element.getBoundingClientRect().height >= 44,
            ),
          ),
        mode + " direct " + slug + " keeps usable footer targets",
      );
      await page.evaluate(() => {
        document.body.style.zoom = "1";
      });
    }
    await page.goto(origin + "/kontakt/", { waitUntil: "domcontentloaded" });
    await ready(page);
    const link = page.locator('.site-footer a[href="/site/datenloeschung"]');
    await link.focus();
    check(
      await link.evaluate((element) => element === document.activeElement),
      mode + " actual private-free footer is keyboard reachable",
    );
    await Promise.all([
      page.waitForURL(origin + "/site/datenloeschung", {
        waitUntil: "domcontentloaded",
      }),
      page.keyboard.press("Enter"),
    ]);
    check(
      (await page.locator("main").textContent()).includes(
        "Ungültiger synthetischer Testtext",
      ),
      mode + " actual footer opens only its published own text",
    );
    await context.close();
  }
  check(writes === 0, "all actual public alias browser requests are read-only");
  check(errors.length === 0, "actual public aliases have no JavaScript errors");
  check(
    external.length === 0,
    "all original alias requests stay on the owned runtime",
  );
  console.log(
    JSON.stringify({
      suite: "public-aliases-browser",
      checks,
      writes,
      external_requests: 0,
    }),
  );
} finally {
  await browser.close();
}
