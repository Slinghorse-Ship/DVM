import assert from "node:assert/strict";
import { readFile, writeFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const root = fileURLToPath(new URL("../../", import.meta.url)),
  origin = "http://127.0.0.1:8111";
const fixture = JSON.parse(
  await readFile(
    root + ".local/browser-tests/native-account-identity.json",
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
const contexts = [],
  checks = [],
  errors = [];
const check = (ok, label) => {
  assert(ok, label);
  checks.push(label);
};
const client = async () => {
  const context = await browser.newContext();
  contexts.push(context);
  await context.route("**/*", (route) =>
    new URL(route.request().url()).origin === origin
      ? route.continue()
      : route.abort(),
  );
  const page = await context.newPage();
  page.on("pageerror", (error) => errors.push(error.message));
  return { context, page };
};
const login = async (page, identifier, password) => {
  await page.goto(origin + "/login/");
  await page
    .getByLabel("E-Mail-Adresse oder persönlicher Benutzername", {
      exact: true,
    })
    .fill(identifier);
  await page.getByLabel("Passwort", { exact: true }).fill(password);
  await Promise.all([
    page.waitForURL("**/vereinsverwaltung/**"),
    page
      .getByRole("button", { name: "Anmelden und Vereinsmanager öffnen" })
      .click(),
  ]);
};
try {
  const admin = await client(),
    member = await client();
  await login(admin.page, credentials.admin.email, credentials.admin.password);
  await login(member.page, fixture.username, fixture.password);
  await admin.page.goto(origin + "/vereinsverwaltung/?view=settings&tab=roles");
  const panel = admin.page.locator("#personal-accounts");
  await panel.waitFor();
  const row = panel.locator("tr").filter({ hasText: fixture.username });
  const form = row
    .locator("form")
    .filter({
      has: admin.page.locator("[name=action][value=identity-assign]"),
    });
  check(
    (await panel.innerText()).includes(fixture.name) &&
      !(await panel.innerText()).includes("ProcessWire"),
    "native original identity panel shows personal names and accounts",
  );
  for (const width of [1440, 820, 390]) {
    await admin.page.setViewportSize({ width, height: 1050 });
    await form.locator("[name=role_id]").focus();
    check(
      await form
        .locator("[name=role_id]")
        .evaluate((el) => document.activeElement === el),
      "identity role selector accepts keyboard " + width,
    );
    check(
      await admin.page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth + 2,
      ),
      "identity administration fits viewport " + width,
    );
  }
  const stale = await form.evaluate((el) =>
    Object.fromEntries(new FormData(el).entries()),
  );
  await form.locator("[name=role_id]").selectOption(fixture.role);
  await Promise.all([
    admin.page.waitForNavigation(),
    form.getByRole("button", { name: "Zugriffsrechte speichern" }).click(),
  ]);
  check(
    (await form.locator("[name=role_id]").inputValue()) === fixture.role,
    "actual HTTP action assigns original own role",
  );
  const conflict = await admin.context.request.post(
    origin + "/vereinsverwaltung/?view=settings&tab=roles",
    { form: { ...stale, role_id: fixture.role, enabled: "1" } },
  );
  check(
    (await conflict.text()).includes(
      "Aktuelle persönliche Vereinszuordnung auswählen",
    ),
    "stale original account form is rejected",
  );
  await admin.page.goto(origin + "/vereinsverwaltung/?view=settings&tab=roles");
  await form.locator("[name=enabled]").uncheck();
  await Promise.all([
    admin.page.waitForNavigation(),
    form.getByRole("button", { name: "Zugriffsrechte speichern" }).click(),
  ]);
  await member.page.goto(origin + "/vereinsverwaltung/?view=portal");
  check(
    new URL(member.page.url()).pathname.startsWith("/login"),
    "native account suspension ends original member session",
  );
  await form.locator("[name=enabled]").check();
  await Promise.all([
    admin.page.waitForNavigation(),
    form.getByRole("button", { name: "Zugriffsrechte speichern" }).click(),
  ]);
  await login(member.page, fixture.username, fixture.password);
  check(
    (await member.page.locator("body").innerText()).includes(fixture.name),
    "explicit native re-enable retains personal credentials and original profile",
  );
  check(
    (await member.context.request.get(origin + "/accounts")).status() === 403,
    "assigned organization role creates no technical account administration",
  );
  check(errors.length === 0, "no browser JavaScript errors");
  await writeFile(
    root + ".local/browser-tests/native-account-identity-report.json",
    JSON.stringify({ checks, passed: true, live_transport: false }, null, 2),
  );
  console.log(
    JSON.stringify({
      checks: checks.length,
      passed: true,
      live_transport: false,
    }),
  );
} catch (error) {
  console.error(
    String(error.message)
      .replace(/([?&]token=)[^\s"']+/g, "$1[redacted]")
      .replace(/[a-f0-9]{32,}/gi, "[redacted]"),
  );
  process.exitCode = 1;
} finally {
  await Promise.all(contexts.map((context) => context.close()));
  await browser.close();
}
