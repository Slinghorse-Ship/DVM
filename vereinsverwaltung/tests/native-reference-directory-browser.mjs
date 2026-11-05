import assert from "node:assert/strict";
import { readFile, mkdir } from "node:fs/promises";
import { fileURLToPath } from "node:url";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const root = fileURLToPath(new URL("../../", import.meta.url)),
  output = root + ".local/browser-tests";
const fixture = JSON.parse(
  await readFile(output + "/reference-directory-browser.json", "utf8"),
);
const origin = "http://127.0.0.1:8107",
  editor = fixture.rows[68].id;
let checks = 0;
const check = (ok, label) => {
  assert(ok, label);
  checks++;
  console.log("PASS " + label);
};
await mkdir(output, { recursive: true, mode: 0o700 });
const browser = await chromium.launch({
  executablePath: process.env.CHROMIUM_EXECUTABLE || "/usr/bin/chromium",
});
try {
  const context = await browser.newContext(),
    page = await context.newPage(),
    errors = [],
    external = [],
    posts = [];
  await context.route("**/*", (route) =>
    new URL(route.request().url()).origin === origin
      ? route.continue()
      : (external.push(route.request().url()), route.abort()),
  );
  page.on("pageerror", (error) => errors.push(error.message));
  page.on("request", (request) => {
    if (request.method() === "POST") posts.push(request.url());
  });
  const original = await context.request.get(origin + "/site/start"),
    bytes = await original.body();
  check(original.status() === 200, "standalone original public page available");
  const last = Math.ceil(fixture.directory.length / 25);
  for (const width of [1440, 820, 390]) {
    await page.setViewportSize({ width, height: 1050 });
    let ids = [];
    for (let index = 1; index <= last; index++) {
      await page.goto(
        origin +
          "/?" +
          new URLSearchParams({
            view: "website",
            item: editor,
            sidebar_page: String(index),
          }),
      );
      const actual = await page
        .locator("[data-page-directory]")
        .evaluateAll((rows) => rows.map((row) => row.dataset.pageDirectory));
      check(
        JSON.stringify(actual) ===
          JSON.stringify(fixture.directory.slice((index - 1) * 25, index * 25)),
        "standalone exact directory page " + index + " " + width,
      );
      ids.push(...actual);
      check(
        (await page.locator(".page-sidebar-count").innerText()).includes(
          fixture.directory.length +
            " Inhalte · Seite " +
            index +
            " von " +
            last,
        ),
        "standalone exact count and page " + index + " " + width,
      );
      if (index < last) {
        const next = page.getByRole("link", {
          name: "Weitere Seiten",
          exact: true,
        });
        check(
          new URL(await next.getAttribute("href"), origin).searchParams.get(
            "item",
          ) === editor,
          "standalone next directory link retains editor " +
            index +
            " " +
            width,
        );
        await next.click();
        await page.waitForURL(
          (url) => url.searchParams.get("sidebar_page") === String(index + 1),
        );
        await page.waitForFunction(
          (expected) =>
            document
              .querySelector(".page-sidebar-count")
              ?.innerText.includes("Seite " + expected + " von"),
          index + 1,
        );
        check(
          (await page.locator("input[name=id]").first().inputValue()) ===
            editor,
          "standalone directory navigation retains original editor " +
            index +
            " " +
            width,
        );
      }
    }
    check(
      new Set(ids).size === fixture.directory.length &&
        ids.length === fixture.directory.length,
      "standalone every page remains reachable exactly once " + width,
    );
    const parent = page.locator("[data-reference-picker]");
    await page.getByText("Navigation, SEO und Bilder", { exact: true }).click();
    await parent
      .locator("[data-reference-query]")
      .fill("Own directory page 67");
    await parent.locator("[data-reference-query]").press("Enter");
    await parent
      .locator("[data-reference-status]")
      .filter({ hasText: "1 Treffer · Seite 1 von 1" })
      .waitFor();
    check(
      (await parent
        .locator("select option")
        .filter({ hasText: "Own directory page 67" })
        .getAttribute("value")) === fixture.rows[67].id,
      "standalone shared parent search finds later original " + width,
    );
    check(
      (await parent.locator("select").inputValue()) === "",
      "standalone searching preserves empty original parent " + width,
    );
    check(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth + 1,
      ),
      "standalone reference search and full directory fit " + width,
    );
    await page.screenshot({
      path: output + "/dvm-reference-directory-" + width + ".png",
      fullPage: true,
    });
  }
  const head = await context.request.head(
    origin + "/reference-options?kind=page&q=Own+directory+page",
  );
  check(
    head.status() === 200 && (await head.body()).length === 0,
    "standalone reference HTTP HEAD has no body",
  );
  const data = await (
    await context.request.get(
      origin + "/reference-options?kind=page&q=Own+directory+page&page=3",
    )
  ).json();
  check(
    data.total === 69 && data.page === 3 && data.rows.length === 19,
    "standalone actual bounded reference HTTP metadata",
  );
  check(
    (
      await context.request.post(origin + "/reference-options?kind=page")
    ).status() === 405,
    "standalone reference HTTP refuses POST",
  );
  check(
    (await (await context.request.get(origin + "/site/start")).body()).equals(
      bytes,
    ),
    "standalone directory and lookup retain exact public original bytes",
  );
  check(
    posts.length === 0,
    "standalone searches and paging never submit content",
  );
  check(errors.length === 0, "no standalone reference script errors");
  check(external.length === 0, "standalone workflows stay entirely local");
  console.log(
    JSON.stringify({
      suite: "native-reference-directory-browser",
      checks,
      widths: [1440, 820, 390],
      external_requests: 0,
      private_saves: 0,
    }),
  );
} finally {
  await browser.close();
}
