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
  ),
  fixture = JSON.parse(
    await readFile(base + "editorial-lists-browser.json", "utf8"),
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
    tab: "events",
    q: "Own editorial event",
    period: "all",
    ...args,
  });
const browser = await chromium.launch({
  executablePath: process.env.CHROMIUM_EXECUTABLE || "/usr/bin/chromium",
});
try {
  const context = await browser.newContext(),
    page = await context.newPage(),
    errors = [],
    external = [],
    mutations = [];
  await context.route("**/*", (r) =>
    new URL(r.request().url()).origin === origin
      ? r.continue()
      : (external.push(r.request().url()), r.abort()),
  );
  page.on("pageerror", (e) => errors.push(e.message));
  page.on("request", (r) => {
    if (
      r.method() === "POST" &&
      !new URL(r.url()).pathname.startsWith("/login")
    )
      mutations.push(r.url());
  });
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
  const publicUrl = origin + "/site/" + fixture.rows[0].slug + "/",
    before = await context.request.get(publicUrl),
    original = await before.text();
  check(
    before.status() === 200,
    "actual original published event is available",
  );
  const rows = () => page.locator("[data-website-row]"),
    ids = () =>
      rows().evaluateAll((nodes) => nodes.map((n) => n.dataset.websiteRow)),
    count = () => page.locator(".website-result-count");
  for (const width of [1440, 820, 390]) {
    await page.setViewportSize({ width, height: 1050 });
    await page.goto(url());
    check(
      (await rows().count()) === 25 &&
        (await count().innerText()).includes(
          "69 Veranstaltungen gefunden · Seite 1 von 3",
        ),
      "bounded actual event first page " + width,
    );
    check(
      JSON.stringify(await ids()) ===
        JSON.stringify(fixture.date_asc.slice(0, 25)),
      "ascending actual event dates and custom renderers " + width,
    );
    const tabs = page.getByRole("navigation", { name: "Website verwalten" });
    check(
      (await tabs.getAttribute("class")).split(" ").includes("nav"),
      "actual Bootstrap website tab list " + width,
    );
    check(
      (await tabs
        .locator("a.nav-link.active[aria-current=page]")
        .innerText()) === "Veranstaltungen",
      "actual Bootstrap selected event tab " + width,
    );
    check(
      await page
        .locator(".website-filters [name=year]")
        .evaluate((el) => el.classList.contains("form-control")),
      "actual Bootstrap year selection " + width,
    );
    check(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth + 2,
      ),
      "event filter/table remains inside viewport " + width,
    );
    await page.screenshot({
      path: output + "/dvm-event-list-" + width + ".png",
    });
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
        JSON.stringify(fixture.date_asc.slice(25, 50)),
      "keyboard next event page has exact contiguous rows " + width,
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
        JSON.stringify(fixture.date_asc.slice(50)),
      "last actual event page preserves unknown dates last " + width,
    );
    await page.goto(url({ sort: "date_desc" }));
    check(
      JSON.stringify(await ids()) ===
        JSON.stringify(fixture.date_desc.slice(0, 25)),
      "descending actual event date order " + width,
    );
    await page.goto(url({ sort: "date_desc", page: "999999" }));
    check(
      JSON.stringify(await ids()) ===
        JSON.stringify(fixture.date_desc.slice(50)),
      "oversize reverse event page clamps correctly " + width,
    );
    await page.goto(url());
    await page.locator(".website-filters [name=year]").selectOption("2050");
    await page
      .locator(".website-filters [name=sort]")
      .selectOption("date_desc");
    await page.locator(".website-filters button").focus();
    await page.locator(".website-filters button").press("Enter");
    await page.waitForURL(
      (u) =>
        u.searchParams.get("year") === "2050" &&
        u.searchParams.get("sort") === "date_desc",
    );
    check(
      (await count().innerText()).includes("57 Veranstaltungen gefunden"),
      "actual year form uses same bounded count " + width,
    );
    await page
      .getByRole("link", { name: "Nächste Seite", exact: true })
      .click();
    await page.waitForFunction(() =>
      document
        .querySelector(".website-result-count")
        ?.innerText.includes("Seite 2 von 3"),
    );
    check(
      new URL(page.url()).searchParams.get("year") === "2050" &&
        new URL(page.url()).searchParams.get("sort") === "date_desc" &&
        new URL(page.url()).searchParams.get("period") === "all",
      "actual year/period/sort survive event paging " + width,
    );
    check(
      JSON.stringify(await ids()) ===
        JSON.stringify(
          fixture.rows
            .slice(0, 57)
            .reverse()
            .slice(25, 50)
            .map((row) => row.id),
        ),
      "year-filtered next page has exact persisted order " + width,
    );
    for (const [status, total, start, end] of [
      ["published", 20, 0, 20],
      ["changed", 10, 10, 20],
      ["draft", 49, 20, 45],
    ]) {
      await page.goto(url({ status, sort: "title_asc" }));
      check(
        (await count().innerText()).startsWith(
          total + " Veranstaltungen gefunden",
        ),
        "actual event " + status + " count " + width,
      );
      check(
        JSON.stringify(await ids()) ===
          JSON.stringify(fixture.rows.slice(start, end).map((r) => r.id)),
        "actual event " + status + " rows " + width,
      );
    }
    await page.goto(url());
    await page.getByRole("link", { name: "Archiv", exact: true }).click();
    await page.waitForURL((u) => u.searchParams.get("period") === "archive");
    check(
      JSON.stringify(await ids()) ===
        JSON.stringify(
          fixture.rows
            .slice(57, 66)
            .reverse()
            .map((r) => r.id),
        ),
      "actual archive retains only nine older originals " + width,
    );
    await page.goto(url());
    await page
      .getByRole("link", { name: "Aktuell & kommend", exact: true })
      .click();
    await page.waitForURL((u) => u.searchParams.get("period") === "upcoming");
    check(
      (await count().innerText()).startsWith("60 Veranstaltungen gefunden"),
      "upcoming list includes 57 future and three unknown dates " + width,
    );
    await page.goto(url({ q: "EDITORIAL STREET 56" }));
    check(
      (await rows().count()) === 1 && (await ids())[0] === fixture.rows[56].id,
      "actual stored street address search " + width,
    );
    await page.goto(url({ q: "ORIGINAL EVENT BODY 00" }));
    check(
      (await rows().count()) === 1 && (await ids())[0] === fixture.rows[0].id,
      "actual visible body search across formatting " + width,
    );
    await page.goto(url({ q: "%' OR 1=1 --" }));
    check(
      (await rows().count()) === 0 &&
        (await count().innerText()).startsWith("0 Veranstaltungen gefunden"),
      "SQL-looking literal event query has no false match " + width,
    );
    await page.goto(origin + "/vereinsverwaltung/?view=website");
    const overview = fixture.overview;
    check(
      (await page.locator(".website-overview-card").count()) === 5 &&
        (await page.locator(".website-card-count").count()) === 5,
      "actual overview displays five area totals " + width,
    );
    const totals = await page
      .locator(".website-summary strong")
      .allTextContents();
    check(
      JSON.stringify(totals) ===
        JSON.stringify(
          [
            overview.counts.total,
            overview.counts.published,
            overview.counts.total - overview.counts.published,
            overview.counts.changed,
            overview.counts.media,
            overview.menu,
          ].map(String),
        ),
      "actual aggregate totals equal independent stored fixture metadata " +
        width,
    );
    check(
      JSON.stringify(
        await page.locator(".website-recent li>a").allTextContents(),
      ) === JSON.stringify(overview.recent.map((row) => row.title)),
      "actual overview has only eight correct recent originals " + width,
    );
    check(
      (await page
        .getByRole("navigation", { name: "Website verwalten" })
        .locator("a.nav-link.active[aria-current=page]")
        .innerText()) === "Übersicht",
      "actual Bootstrap selected overview tab " + width,
    );
    check(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth + 2,
      ),
      "counted overview fits viewport " + width,
    );
    await page.screenshot({
      path: output + "/dvm-counted-overview-" + width + ".png",
    });
    const sectionLinks = await page
      .getByRole("navigation", { name: "Website verwalten" })
      .locator("a.nav-link")
      .evaluateAll((links) =>
        links.map((link) => ({
          url: link.href,
          label: link.textContent.trim(),
        })),
      );
    check(
      sectionLinks.length === 8,
      "all eight administrator website sections are available " + width,
    );
    for (const link of sectionLinks) {
      const response = await page.goto(link.url);
      check(
        response.status() === 200 &&
          (await page
            .getByRole("navigation", { name: "Website verwalten" })
            .locator("a.nav-link.active[aria-current=page]")
            .innerText()) === link.label,
        "actual Bootstrap current website section " + link.label + " " + width,
      );
      check(
        await page.evaluate(
          () => document.documentElement.scrollWidth <= innerWidth + 2,
        ),
        "website section fits viewport " + link.label + " " + width,
      );
      if (link.label === "Menü") {
        const editor = page.locator("[data-navigation-editor]");
        check(
          await editor.locator("input[type=checkbox]").evaluateAll((inputs) =>
            inputs.every((input) => {
              const box = input.getBoundingClientRect();
              return (
                input.classList.contains("check") &&
                box.width >= 16 &&
                box.height >= 16
              );
            }),
          ),
          "actual Bootstrap menu checkboxes remain visible " + width,
        );
        check(
          (await editor.locator("button:not(.btn-solid)").count()) === 0,
          "every actual menu action uses Bootstrap 6 " + width,
        );
        const ordered = () =>
          editor
            .locator("[data-menu-row] input[type=text]")
            .evaluateAll((inputs) => inputs.map((input) => input.name));
        const originalOrder = await ordered();
        const down = editor
          .locator("[data-menu-row]")
          .first()
          .locator('[data-menu-step="1"]');
        await down.focus();
        await down.press("Enter");
        check(
          (await ordered())[1] === originalOrder[0],
          "keyboard moves a menu row down " + width,
        );
        const up = editor
          .locator("[data-menu-row]")
          .nth(1)
          .locator('[data-menu-step="-1"]');
        await up.focus();
        await up.press("Enter");
        check(
          JSON.stringify(await ordered()) === JSON.stringify(originalOrder),
          "keyboard restores the actual menu order " + width,
        );
        const automatic = editor.locator("[name=automatic_program_year]");
        const wasChecked = await automatic.isChecked();
        await automatic.focus();
        await automatic.press("Space");
        check(
          (await automatic.isChecked()) !== wasChecked,
          "keyboard toggles automatic programme year " + width,
        );
        await automatic.press("Space");
        await page.screenshot({
          path: output + "/dvm-website-menu-" + width + ".png",
        });
      }
    }
  }
  const after = await context.request.get(publicUrl);
  check(
    (await after.text()) === original,
    "all event/overview navigation preserves original public bytes",
  );
  check(
    mutations.length === 0,
    "event filtering and overview perform no content or provider mutations",
  );
  check(errors.length === 0, "no list/menu browser script errors");
  check(external.length === 0, "all list/menu flows stay local");
  console.log(
    JSON.stringify({
      suite: "native-editorial-lists-browser",
      checks: n,
      widths: [1440, 820, 390],
      external_requests: external.length,
      mutations: mutations.length,
    }),
  );
} finally {
  await browser.close();
}
