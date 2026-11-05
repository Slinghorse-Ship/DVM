import assert from "node:assert/strict";
import { readFile, mkdir } from "node:fs/promises";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const fixture = JSON.parse(
  await readFile(process.env.DVM_DOCUMENT_QUOTA_FIXTURE, "utf8"),
);
assert(/^http:\/\/127\.0\.0\.1:\d{4,5}$/.test(fixture.origin));
const browser = await chromium.launch({
  executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || undefined,
});
const address = fixture.origin + "/?view=media&section=storage";
let checks = 0,
  posts = 0;
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
  throw Error("Actual native storage styles required");
};
const current = async (page, version, mib) => {
  await page
    .locator('#document-quota [name="quota_version"][value="' + version + '"]')
    .waitFor({ state: "attached" });
  if ((await page.locator("#document-quota").getAttribute("open")) === null)
    await page.locator("#document-quota summary").click();
  check(
    (await page.locator("#document-quota [name=limit_mib]").inputValue()) ===
      String(mib),
    "actual current form has its exact saved version and limit",
  );
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
      (await page.locator("#document-quota").getAttribute("open")) === null &&
        (await page.locator("[data-storage-group]").count()) === 0,
      "actual " + js + " storage starts compact without an automatic inventory",
    );
    await page.locator("#document-quota summary").focus();
    await page.keyboard.press("Enter");
    await page.locator("#document-quota [name=limit_mib]").waitFor();
    for (const width of [320, 390, 820])
      for (const zoom of [1, 2]) {
        await page.setViewportSize({ width, height: 900 });
        await page.evaluate((value) => {
          document.documentElement.style.zoom = String(value);
        }, zoom);
        await ready(page);
        const geometry = await page
          .locator("#document-quota")
          .evaluate((root) => ({
            fits:
              document.documentElement.scrollWidth <=
              document.documentElement.clientWidth + 1,
            controls: [
              ...root.querySelectorAll("summary,input[type=number],button,a"),
            ]
              .filter((node) => node.getClientRects().length)
              .map((node) => ({
                height: node.getBoundingClientRect().height,
                font: parseFloat(getComputedStyle(node).fontSize),
              })),
          }));
        check(
          geometry.fits,
          "actual " +
            js +
            " filled PDF setting fits " +
            width +
            "px at " +
            zoom +
            "x zoom",
        );
        for (const control of geometry.controls)
          check(
            control.height >= 44 * zoom - 1 && control.font >= 16,
            "actual " +
              js +
              " PDF control retains touch size and readable text",
          );
      }
    await page.evaluate(() => {
      document.documentElement.style.zoom = "1";
    });
    await page.setViewportSize({ width: 390, height: 900 });
    if (js) {
      await mkdir(".local/browser-tests/document-quota", { recursive: true });
      await page.screenshot({
        path: ".local/browser-tests/document-quota/mobile.png",
        fullPage: false,
      });
      const field = page.locator("#document-quota [name=limit_mib]");
      await field.fill("2");
      await page.locator("#document-quota button").click();
      await current(page, 2, 2);
      const form = page.locator("#document-quota form");
      const values = await form.evaluate((node) =>
        Object.fromEntries(new FormData(node)),
      );
      posts++;
      const other = await context.request.post(address, {
        form: { ...values, limit_mib: "3" },
        headers: { Origin: fixture.origin, Accept: "application/json" },
      });
      check(
        other.status() === 200,
        "actual independent current policy write has genuine own CSRF and advances one version",
      );
      await field.fill("8");
      await page.locator("#document-quota button").click();
      const error = page.locator("#document-quota [data-media-removal-error]");
      await error.waitFor();
      check(
        (await error.evaluate((node) => document.activeElement === node)) &&
          (await field.inputValue()) === "8" &&
          (await page
            .locator("#document-quota [name=quota_version]")
            .inputValue()) === "2",
        "actual stale save focuses its local short correction and keeps the old version and typed limit",
      );
      const read = page.locator("#document-quota [data-media-removal-check]");
      await read.click();
      await page.locator("#discard").waitFor();
      await page.locator("#discard [data-choice=stay]").click();
      check(
        (await field.inputValue()) === "8",
        "keeping unsaved correction retains its actual typed limit",
      );
      await read.click();
      await page.locator("#discard").waitFor();
      await page.locator("#discard [data-choice=discard]").click();
      await current(page, 3, 3);
      let lost = 0;
      await page.route("**/?view=media*", async (route) => {
        if (route.request().method() !== "POST" || lost)
          return route.continue();
        lost++;
        const response = await route.fetch();
        assert.equal(response.status(), 200);
        await route.abort("failed");
      });
      await field.fill("4");
      await page.locator("#document-quota button").click();
      const unknown = page.locator(
        "#document-quota [data-media-removal-error]",
      );
      await unknown.waitFor();
      check(
        lost === 1 &&
          (await field.inputValue()) === "4" &&
          (await page
            .locator("#document-quota [name=quota_version]")
            .inputValue()) === "3",
        "actual lost acknowledgement never silently promotes a form or repeats a policy write",
      );
      await page.locator("#document-quota [data-media-removal-check]").click();
      await page.locator("#discard").waitFor();
      await page.locator("#discard [data-choice=discard]").click();
      await current(page, 4, 4);
      check(
        lost === 1,
        "explicit current read resolves the already committed exact choice without another write",
      );
    } else {
      await page.locator("#document-quota [name=limit_mib]").fill("0");
      await Promise.all([
        page.waitForNavigation({ waitUntil: "domcontentloaded" }),
        page.locator("#document-quota button").click(),
      ]);
      await current(page, 5, 0);
      check(
        await page
          .locator("#document-quota")
          .innerText()
          .then((text) => text.includes("Ohne Speichergrenze")),
        "actual native form explicitly disables the optional limit and retains simple uploads",
      );
    }
    await context.close();
  }
  check(
    posts === 5 && external.length === 0 && errors.length === 0,
    "actual browser has exactly four explicit own writes and one rejected stale write, no automatic repeats or outside requests",
  );
  console.log(
    JSON.stringify({
      suite: "document-quota-browser",
      checks,
      posts,
      external_requests: external.length,
    }),
  );
} finally {
  await browser.close();
}
