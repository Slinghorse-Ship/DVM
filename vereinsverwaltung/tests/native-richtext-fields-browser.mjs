import assert from "node:assert/strict";
import { readFile, mkdir } from "node:fs/promises";
import { fileURLToPath } from "node:url";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const root = fileURLToPath(new URL("../../", import.meta.url));
const base = root + ".local/native-cms-integration/integrated-manager-runtime/";
const credentials = JSON.parse(
  await readFile(base + "browser-credentials.json", "utf8"),
);
const fixture = JSON.parse(
  await readFile(base + "richtext-fields-browser.json", "utf8"),
);
const origin = "http://127.0.0.1:8111",
  out = root + ".local/browser-tests";
await mkdir(out, { recursive: true, mode: 0o700 });
let n = 0;
const check = (ok, label) => {
  assert(ok, label);
  n++;
  console.log("PASS " + label);
};
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
  const url =
    origin +
    "/vereinsverwaltung/?view=website&tab=pages&native_item=" +
    fixture.id;
  for (const width of [1440, 820, 390]) {
    await page.setViewportSize({ width, height: 1050 });
    const response = await page.goto(url);
    check(response.status() === 200, "original page editor opens at " + width);
    const editor = page.locator("[data-kh-richtext]").filter({
        has: page.locator('[name="custom_fields[browser_formatted_required]"]'),
      }),
      surface = editor.locator("[data-richtext-surface]");
    await surface.waitFor();
    await page.waitForFunction(
      () =>
        document
          .querySelector('[name="custom_fields[browser_formatted_required]"]')
          ?.closest("[data-kh-richtext]")
          .querySelector("[data-richtext-surface]").contentEditable === "true",
    );
    check(
      (await surface.getAttribute("aria-required")) === "true",
      "accessible required custom editor at " + width,
    );
    check(
      (await surface.locator("strong").count()) === 1,
      "stored actual formatting reopened at " + width,
    );
    check(
      await editor
        .locator("button[data-rt-command=bold]")
        .evaluate((el) => el.classList.contains("btn")),
      "shared toolbar uses Bootstrap buttons at " + width,
    );
    check(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth + 1,
      ),
      "formatted editors fit " + width,
    );
    await surface.fill("");
    const submitted = [];
    const listener = (r) => {
      if (r.method() === "POST") submitted.push(r.url());
    };
    page.on("request", listener);
    const form = editor.locator("xpath=ancestor::form");
    await form
      .getByRole("button", { name: "Entwurf speichern", exact: true })
      .click();
    await editor
      .locator("[data-richtext-status]")
      .filter({ hasText: "Pflichtfeld" })
      .waitFor();
    check(
      submitted.length === 0,
      "required field stops actual browser request at " + width,
    );
    await surface.fill("ä🎶");
    await form
      .getByRole("button", { name: "Entwurf speichern", exact: true })
      .click();
    await editor
      .locator("[data-richtext-status]")
      .filter({ hasText: "Mindestens 3" })
      .waitFor();
    check(
      submitted.length === 0,
      "minimum counts visible Unicode characters at " + width,
    );
    page.off("request", listener);
    await surface.fill("Vereinstext 🎶");
    await surface.press("Control+a");
    await editor.locator("[data-rt-command=bold]").click();
    check(
      (await surface.locator("strong,b").count()) > 0,
      "actual toolbar formats custom field at " + width,
    );
    await surface.fill("x".repeat(121));
    await form
      .getByRole("button", { name: "Entwurf speichern", exact: true })
      .click();
    await editor
      .locator("[data-richtext-status]")
      .filter({ hasText: "Höchstens 120" })
      .waitFor();
    check(
      (await surface.getAttribute("aria-invalid")) === "true",
      "visible maximum is enforced at " + width,
    );
    const optional = page
      .locator("[data-kh-richtext]")
      .filter({
        has: page.locator('[name="custom_fields[browser_formatted_optional]"]'),
      });
    const optionalSurface = optional.locator("[data-richtext-surface]");
    await optionalSurface.fill("Synthetische Tabelle");
    await optionalSurface.press("Control+End");
    await optionalSurface.press("Enter");
    await optional.locator("summary").filter({ hasText: "Tabelle" }).click();
    await optional
      .getByLabel("Neue Tabelle: Zeilen", { exact: true })
      .fill("2");
    await optional
      .getByLabel("Neue Tabelle: Spalten", { exact: true })
      .fill("2");
    await optional
      .getByRole("button", { name: "Tabelle einfügen", exact: true })
      .click();
    check(
      (await optionalSurface.locator("tr").count()) === 2,
      "actual custom field table inserted at " + width,
    );
    await optionalSurface.locator("td").first().click();
    await optional.locator("summary").filter({ hasText: "Tabelle" }).click();
    await optional
      .getByRole("button", { name: "Zeile hinzufügen", exact: true })
      .click();
    check(
      (await optionalSurface.locator("tr").count()) === 3,
      "table operation keeps this editor selection at " + width,
    );
    await optional
      .getByRole("button", { name: "Rückgängig", exact: true })
      .click();
    check(
      (await optionalSurface.locator("tr").count()) === 2,
      "custom field undo retains its own history at " + width,
    );
    await optional
      .getByRole("button", { name: "Wiederholen", exact: true })
      .click();
    check(
      (await optionalSurface.locator("tr").count()) === 3,
      "custom field redo retains its own history at " + width,
    );
    await optionalSurface.locator("td").first().click();
    await optional.locator("summary").filter({ hasText: "Farben" }).click();
    await optional
      .getByLabel("Tabellenhintergrund wählen", { exact: true })
      .fill("#ddeedd");
    check(
      (await optional.locator("[data-richtext-value]").inputValue()).includes(
        "background-color:#ddeedd",
      ),
      "actual table color synchronizes at " + width,
    );
    await surface.fill("Musik & Verein 🎶");
    await surface.press("Control+a");
    if ((await surface.locator("strong,b").count()) === 0) {
      await editor.locator("[data-rt-command=bold]").click();
    }
    check(
      (await surface.locator("strong,b").innerText()) === "Musik & Verein 🎶",
      "intended final formatting exists before save at " + width,
    );
    await editor.scrollIntoViewIfNeeded();
    await page.screenshot({
      path: out + "/dvm-formatted-field-" + width + ".png",
      fullPage: false,
    });
  }
  const editor = page.locator("[data-kh-richtext]").filter({
      has: page.locator('[name="custom_fields[browser_formatted_required]"]'),
    }),
    form = editor.locator("xpath=ancestor::form");
  const request = page.waitForResponse((r) => r.request().method() === "POST");
  const version = Number(await form.locator("[name=version]").inputValue());
  await form
    .getByRole("button", { name: "Entwurf speichern", exact: true })
    .click();
  const saved = await request;
  check(
    saved.status() === 303,
    "actual original formatted-field form saves and redirects",
  );
  await page.waitForFunction(
    (expected) =>
      Number(
        document
          .querySelector("form [name=operation][value=page-save]")
          ?.form.querySelector("[name=version]")?.value,
      ) === expected,
    version + 1,
  );
  await page.goto(url);
  check(
    (
      await page
        .locator('[name="custom_fields[browser_formatted_required]"]')
        .getAttribute("value")
    ).includes("<strong>Musik &amp; Verein 🎶</strong>"),
    "saved custom HTML reopens from real MySQL",
  );
  const savedOptional = page.locator(
    '[name="custom_fields[browser_formatted_optional]"]',
  );
  check(
    (await savedOptional.inputValue()).includes("background-color:#ddeedd"),
    "table color reopens from actual MySQL",
  );
  const savedOptionalEditor = savedOptional.locator(
    "xpath=ancestor::*[@data-kh-richtext]",
  );
  check(
    (await savedOptionalEditor.locator("tr").count()) === 3,
    "actual table row history survives save",
  );
  const publicPage = await context.newPage();
  await publicPage.goto(origin + "/site/" + fixture.slug);
  check(
    (await publicPage.locator(".custom-fields-public strong").count()) === 1,
    "private edit leaves original published formatted version available",
  );
  check(errors.length === 0, "no browser script errors");
  check(external.length === 0, "no external network or provider action");
  console.log(
    JSON.stringify({
      checks: n,
      widths: [1440, 820, 390],
      actual_http: true,
      external_actions: 0,
    }),
  );
  await context.close();
} finally {
  await browser.close();
}
