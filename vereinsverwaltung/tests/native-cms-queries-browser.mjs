import assert from "node:assert/strict";
import { readFile, mkdir } from "node:fs/promises";
import { fileURLToPath } from "node:url";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const root = fileURLToPath(new URL("../../", import.meta.url)),
  base = root + ".local/native-cms-integration/integrated-manager-runtime/";
const credentials = JSON.parse(
  await readFile(base + "browser-credentials.json", "utf8"),
);
const fixture = JSON.parse(
  await readFile(base + "cms-queries-browser.json", "utf8"),
);
const origin = "http://127.0.0.1:8111",
  output = root + ".local/browser-tests";
await mkdir(output, { recursive: true, mode: 0o700 });
let n = 0;
const check = (ok, label) => {
  assert(ok, label);
  n++;
  console.log("PASS " + label);
};
const url = (args = {}) =>
  origin +
  "/vereinsverwaltung/?" +
  new URLSearchParams({
    view: "website",
    tab: "pages",
    q: "Own bounded browser",
    ...args,
  });
const browser = await chromium.launch({
  executablePath: process.env.CHROMIUM_EXECUTABLE || "/usr/bin/chromium",
});
try {
  const context = await browser.newContext(),
    page = await context.newPage(),
    errors = [],
    external = [];
  await context.route("**/*", (r) =>
    new URL(r.request().url()).origin === origin
      ? r.continue()
      : (external.push(r.request().url()), r.abort()),
  );
  page.on("pageerror", (e) => errors.push(e.message));
  await page.goto(origin + "/login/");
  await page.locator("[name=email]").fill(credentials.admin.email);
  await page.locator("[name=password]").fill(credentials.admin.password);
  await Promise.all([
    page.waitForURL("**/vereinsverwaltung/**"),
    page
      .getByRole("button", {
        name: "Anmelden und Vereinsmanager öffnen",
        exact: true,
      })
      .click(),
  ]);
  const originalResponse = await context.request.get(
      origin + "/site/" + fixture[0].slug + "/",
    ),
    originalBody = await originalResponse.text();
  check(
    originalResponse.status() === 200 &&
      originalBody.includes("Own <strong>readable body</strong> 00"),
    "actual published original available before listing",
  );
  const rows = () => page.locator("[data-website-row]");
  const ids = () =>
    rows().evaluateAll((nodes) => nodes.map((node) => node.dataset.websiteRow));
  const count = () => page.locator(".website-result-count");
  for (const width of [1440, 820, 390]) {
    await page.setViewportSize({ width, height: 1050 });
    await page.goto(url());
    check(
      (await rows().count()) === 25,
      "first actual SQL page contains 25 rows at " + width,
    );
    check(
      (await count().innerText()).includes(
        "57 Seiten gefunden · Seite 1 von 3",
      ),
      "exact SQL count and pagination at " + width,
    );
    check(
      JSON.stringify(await ids()) ===
        JSON.stringify(fixture.slice(0, 25).map((p) => p.id)),
      "original title ordering at " + width,
    );
    const filters = page.locator(".website-filters");
    check(
      (await filters.locator("[name=sort]").getAttribute("class")).includes(
        "form-control",
      ),
      "actual Bootstrap sort select at " + width,
    );
    check(
      (
        await filters
          .getByRole("button", { name: "Filtern", exact: true })
          .getAttribute("class")
      ).includes("btn-solid"),
      "actual Bootstrap filter action at " + width,
    );
    check(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth + 2,
      ),
      "long declared field labels stay inside viewport at " + width,
    );
    await page
      .getByRole("link", { name: "Nächste Seite", exact: true })
      .focus();
    await page
      .getByRole("link", { name: "Nächste Seite", exact: true })
      .press("Enter");
    await page.waitForFunction(() =>
      document
        .querySelector(".website-result-count")
        ?.innerText.includes("Seite 2 von 3"),
    );
    check(
      JSON.stringify(await ids()) ===
        JSON.stringify(fixture.slice(25, 50).map((p) => p.id)),
      "keyboard next page has no duplicates at " + width,
    );
    check(
      new URL(page.url()).searchParams.get("q") === "Own bounded browser",
      "filter preserved across actual navigation at " + width,
    );
    await page
      .getByRole("link", { name: "Nächste Seite", exact: true })
      .click();
    await page.waitForFunction(() =>
      document
        .querySelector(".website-result-count")
        ?.innerText.includes("Seite 3 von 3"),
    );
    check(
      JSON.stringify(await ids()) ===
        JSON.stringify(fixture.slice(50).map((p) => p.id)),
      "last actual page contains only seven remaining rows at " + width,
    );
    check(
      (await page
        .getByRole("link", { name: "Nächste Seite", exact: true })
        .count()) === 0,
      "last page offers no invalid next link at " + width,
    );
    await page.goto(url({ page: "999999" }));
    check(
      (await count().innerText()).includes("Seite 3 von 3"),
      "oversize page clamps to existing final page at " + width,
    );
    for (const sort of [
      "custom.browser_rank:asc",
      "custom.browser_details:asc",
    ]) {
      await page.goto(url());
      await page.locator(".website-filters [name=sort]").selectOption(sort);
      await page.locator(".website-filters button").focus();
      await page.locator(".website-filters button").press("Enter");
      await page.waitForURL((u) => u.searchParams.get("sort") === sort);
      check(
        JSON.stringify(await ids()) ===
          JSON.stringify(
            fixture
              .slice()
              .reverse()
              .slice(0, 25)
              .map((p) => p.id),
          ),
        "actual typed custom sort " + sort + " at " + width,
      );
      check(
        (await rows().count()) === 25,
        "custom sort still fetches one bounded slice at " + width,
      );
      if (sort === "custom.browser_rank:asc")
        await page.screenshot({
          path: output + "/dvm-page-search-" + width + ".png",
        });
      await page
        .getByRole("link", { name: "Nächste Seite", exact: true })
        .click();
      await page.waitForFunction(() =>
        document
          .querySelector(".website-result-count")
          ?.innerText.includes("Seite 2 von 3"),
      );
      check(
        new URL(page.url()).searchParams.get("sort") === sort,
        "custom sort persists on next page at " + width,
      );
      check(
        JSON.stringify(await ids()) ===
          JSON.stringify(
            fixture
              .slice()
              .reverse()
              .slice(25, 50)
              .map((p) => p.id),
          ),
        "custom next page preserves typed ordering at " + width,
      );
    }
    for (const [status, total, start, end] of [
      ["published", 20, 0, 20],
      ["changed", 10, 10, 20],
      ["draft", 37, 20, 45],
    ]) {
      await page.goto(url({ status }));
      check(
        (await count().innerText()).startsWith(total + " Seiten gefunden"),
        "actual " + status + " count at " + width,
      );
      check(
        JSON.stringify(await ids()) ===
          JSON.stringify(fixture.slice(start, end).map((p) => p.id)),
        "actual " + status + " rows at " + width,
      );
    }
    await page.goto(url({ q: "READABLE BODY 00" }));
    check(
      (await rows().count()) === 1 && (await ids())[0] === fixture[0].id,
      "visible body search case folds across original formatting at " + width,
    );
    await page.goto(url({ q: "browser 00 synthetic-query-00" }));
    check(
      (await rows().count()) === 1 && (await ids())[0] === fixture[0].id,
      "original cross field text search retained at " + width,
    );
    await page.goto(url({ q: "100%_!" }));
    check(
      (await rows().count()) === 1,
      "LIKE wildcard text stays literal at " + width,
    );
    check(
      (await rows().innerText()).includes('Own escaped 100%_! " <marker>'),
      "stored quotes and markup render as literal text at " + width,
    );
    check(
      (await page.locator("marker").count()) === 0,
      "stored title cannot create elements at " + width,
    );
    await page.goto(url({ q: "%' OR 1=1 --" }));
    check(
      (await rows().count()) === 0 &&
        (await count().innerText()).startsWith("0 Seiten"),
      "SQL looking input cannot widen query at " + width,
    );
  }
  const after = await context.request.get(
    origin + "/site/" + fixture[0].slug + "/",
  );
  check(
    (await after.text()) === originalBody,
    "all actual searches leave original public bytes unchanged",
  );
  check(errors.length === 0, "no browser script regressions");
  check(
    external.length === 0,
    "actual list/filter workflows require no external requests",
  );
  console.log(
    JSON.stringify({
      suite: "native-cms-queries-browser",
      checks: n,
      widths: [1440, 820, 390],
      external_requests: external.length,
    }),
  );
} finally {
  await browser.close();
}
