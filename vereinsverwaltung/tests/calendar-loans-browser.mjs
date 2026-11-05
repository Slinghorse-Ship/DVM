import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const root = fileURLToPath(new URL("../../", import.meta.url));
const origin = "http://127.0.0.1:8111";
const fixture = JSON.parse(
  await readFile(root + ".local/browser-tests/calendar-loans.json", "utf8"),
);
const credentials = JSON.parse(
  await readFile(
    root +
      ".local/native-cms-integration/integrated-manager-runtime/browser-credentials.json",
    "utf8",
  ),
).admin;
const browser = await chromium.launch({
  executablePath: process.env.CHROMIUM_EXECUTABLE || "/usr/bin/chromium",
});
try {
  const context = await browser.newContext();
  await context.route("**/*", (r) =>
    new URL(r.request().url()).origin === origin ? r.continue() : r.abort(),
  );
  const page = await context.newPage();
  const errors = [];
  page.on("pageerror", (e) => errors.push(e.message));
  await page.goto(origin + "/login/");
  await page
    .getByLabel("E-Mail-Adresse oder persönlicher Benutzername", { exact: true })
    .fill(credentials.email);
  await page.getByLabel("Passwort", { exact: true }).fill(credentials.password);
  await Promise.all([
    page.waitForURL("**/vereinsverwaltung/**"),
    page
      .getByRole("button", { name: "Anmelden und Vereinsmanager öffnen" })
      .click(),
  ]);
  for (const width of [1440, 820, 390]) {
    await page.setViewportSize({ width, height: 1050 });
    await page.goto(
      origin +
        "/vereinsverwaltung/?view=calendar&mode=agenda&date=" +
        fixture.start +
        "&calendar_filter=loans",
    );
    await page
      .getByRole("heading", { name: "Kalender", exact: true })
      .waitFor();
    assert.equal(
      await page.locator("select[name=calendar_filter]").inputValue(),
      "loans",
    );
    const links = page
      .locator(".calendar-agenda a")
      .filter({ hasText: "Kalender-Bühnentische" });
    const dates = [...new Set([fixture.start, fixture.due])];
    const visible = dates.filter(
      (date) => date.slice(0, 7) === fixture.start.slice(0, 7),
    );
    assert.equal(
      await links.count(),
      visible.length,
      "original loan dates in this month at " + width,
    );
    const urls = await links.evaluateAll((nodes) => nodes.map((n) => n.href));
    for (const value of urls) {
      const url = new URL(value);
      assert.equal(url.searchParams.get("view"), "inventory");
      assert.equal(url.searchParams.get("tab"), "loans");
      assert.equal(url.searchParams.get("loan"), fixture.loan);
    }
    await links.first().focus();
    assert(await links.first().evaluate((e) => e === document.activeElement));
    assert(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth,
      ),
      "loan calendar fits " + width,
    );
    await page.screenshot({
      path: root + ".local/browser-tests/calendar-loans-" + width + ".png",
      fullPage: true,
    });
  }
  await page
    .locator(".calendar-agenda a")
    .filter({ hasText: "Kalender-Bühnentische" })
    .first()
    .click();
  await page.getByRole("heading", { name: /Ausleihe AUS-/ }).waitFor();
  assert.match(
    await page.locator(".iv-receipt").innerText(),
    /Kalender-Bühnentische/,
  );
  assert.equal(new URL(page.url()).searchParams.get("loan"), fixture.loan);
  assert.deepEqual(errors, []);
  console.log(
    "Loan calendar browser passed: native login, canonical pickup/return links, original loan view, keyboard focus and 1440/820/390 widths.",
  );
} finally {
  await browser.close();
}
