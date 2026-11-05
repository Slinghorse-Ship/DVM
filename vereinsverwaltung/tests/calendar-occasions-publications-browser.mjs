import assert from "node:assert/strict";
import { readFile, writeFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const root = fileURLToPath(new URL("../../", import.meta.url));
const origin = "http://127.0.0.1:8111";
const fixture = JSON.parse(
  await readFile(
    root + ".local/browser-tests/calendar-occasions-publications.json",
    "utf8",
  ),
);
const credentials = JSON.parse(
  await readFile(
    root +
      ".local/native-cms-integration/integrated-manager-runtime/browser-credentials.json",
    "utf8",
  ),
);
const browser = await chromium.launch({
  executablePath: process.env.CHROMIUM_EXECUTABLE || "/usr/bin/chromium",
});
const errors = [];
async function login(role) {
  const context = await browser.newContext();
  await context.route("**/*", (r) =>
    new URL(r.request().url()).origin === origin ? r.continue() : r.abort(),
  );
  const page = await context.newPage();
  page.on("pageerror", (e) => errors.push(e.message));
  await page.goto(origin + "/login/");
  await page
    .getByLabel("E-Mail-Adresse oder persönlicher Benutzername", {
      exact: true,
    })
    .fill(credentials[role].email);
  await page
    .getByLabel("Passwort", { exact: true })
    .fill(credentials[role].password);
  await Promise.all([
    page.waitForURL("**/vereinsverwaltung/**"),
    page
      .getByRole("button", { name: "Anmelden und Vereinsmanager öffnen" })
      .click(),
  ]);
  return { context, page };
}
try {
  const { page } = await login("admin");
  const cases = [
    [
      "birthdays",
      "Kalender-Geburtstagsprofil",
      "members",
      "id",
      fixture.birthday,
    ],
    [
      "internal",
      "Kalender-Interne Probe",
      "calendar",
      "event",
      fixture.internal,
    ],
    [
      "external",
      "Kalender-Externes Vereinsfest",
      "calendar",
      "event",
      fixture.external,
    ],
    [
      "events",
      "Kalender-Öffentliches Konzert",
      "calendar",
      "event",
      fixture.published,
    ],
    [
      "social",
      "Kalender-Social-Beitrag",
      "social",
      "native_item",
      fixture.social,
    ],
    [
      "newsletter",
      "Kalender-Newsletter",
      "newsletter",
      "native_item",
      fixture.newsletter,
    ],
  ];
  for (const width of [1440, 820, 390]) {
    await page.setViewportSize({ width, height: 1050 });
    for (const [filter, title, view, key, id] of cases) {
      const response = await page.goto(
        origin +
          "/vereinsverwaltung/?view=calendar&mode=agenda&date=" +
          fixture.date +
          "&calendar_filter=" +
          filter,
      );
      assert.equal(response.status(), 200);
      const link = page
        .locator(".calendar-agenda a")
        .filter({ hasText: title });
      assert.equal(await link.count(), 1);
      const url = new URL(await link.getAttribute("href"), page.url());
      assert.equal(url.searchParams.get("view"), view);
      assert.equal(url.searchParams.get(key), id);
      assert(
        await link
          .locator("xpath=ancestor::article")
          .locator(".calendar-source")
          .innerText(),
      );
      assert(
        await page.evaluate(
          () => document.documentElement.scrollWidth <= innerWidth + 2,
        ),
        "calendar fits " + width + " / " + filter,
      );
      await link.focus();
      assert(await link.evaluate((e) => document.activeElement === e));
    }
    await page.goto(
      origin +
        "/vereinsverwaltung/?view=calendar&mode=agenda&date=" +
        fixture.date,
    );
    assert(
      await page.locator(".calendar-heading h1").evaluate((heading) => {
        const text = document.createRange();
        text.selectNodeContents(heading);
        return text.getClientRects().length === 1;
      }),
      "calendar heading stays readable on one line at " + width,
    );
    if (width > 700) {
      assert(
        await page.locator(".calendar-view-filter").evaluate((form) => {
          const label = form.querySelector("label").getBoundingClientRect();
          const button = form.querySelector("button").getBoundingClientRect();
          return button.left >= label.right && button.top < label.bottom;
        }),
        "calendar filter uses one compact row at " + width,
      );
    }
    for (const [, title] of cases)
      assert.equal(
        await page
          .locator(".calendar-agenda a")
          .filter({ hasText: title })
          .count(),
        1,
        "all view " + title,
      );
    await page.screenshot({
      path: root + ".local/browser-tests/calendar-combined-" + width + ".png",
      fullPage: true,
    });
  }
  // Enter follows original scheduled post and newsletter, rather than a generic job list.
  for (const filter of ["social", "newsletter"]) {
    await page.goto(
      origin +
        "/vereinsverwaltung/?view=calendar&mode=agenda&date=" +
        fixture.date +
        "&calendar_filter=" +
        filter,
    );
    const title =
      filter === "social" ? "Kalender-Social-Beitrag" : "Kalender-Newsletter";
    const link = page.locator(".calendar-agenda a").filter({ hasText: title });
    await link.focus();
    await page.keyboard.press("Enter");
    await page.locator('.compose-writing [name="title"]').waitFor();
    assert.equal(
      await page.locator('.compose-writing [name="title"]').inputValue(),
      title,
    );
    assert.equal(
      new URL(page.url()).searchParams.get("native_item"),
      fixture[filter],
    );
  }
  await page.goto(
    origin + "/vereinsverwaltung/?view=calendar&event=" + fixture.external,
  );
  assert.equal(
    await page.locator("[name=activity_kind][value=external]").isChecked(),
    true,
  );
  const member = await login("member");
  await member.page.goto(
    origin +
      "/vereinsverwaltung/?view=calendar&mode=agenda&date=" +
      fixture.date,
  );
  for (const filter of ["social", "newsletter"])
    assert.equal(
      await member.page
        .locator("select[name=calendar_filter] option[value=" + filter + "]")
        .count(),
      0,
    );
  for (const title of [
    "Kalender-Geburtstagsprofil",
    "Kalender-Interne Probe",
    "Kalender-Externes Vereinsfest",
    "Kalender-Social-Beitrag",
    "Kalender-Newsletter",
  ])
    assert.equal(
      await member.page
        .locator(".calendar-agenda a")
        .filter({ hasText: title })
        .count(),
      0,
    );
  assert.equal(
    await member.page
      .locator(".calendar-agenda a")
      .filter({ hasText: "Kalender-Öffentliches Konzert" })
      .count(),
    1,
  );
  assert.deepEqual(errors, []);
  await writeFile(
    root + ".local/browser-tests/calendar-combined-result.json",
    JSON.stringify(
      {
        status: "passed",
        sources: 6,
        widths: [1440, 820, 390],
        keyboard_originals: true,
        member_permissions: true,
      },
      null,
      2,
    ),
  );
  console.log(
    "Combined calendar browser passed: six original sources, actual scheduled editors, external classification, member permissions, text labels, keyboard and three widths.",
  );
} finally {
  await browser.close();
}
