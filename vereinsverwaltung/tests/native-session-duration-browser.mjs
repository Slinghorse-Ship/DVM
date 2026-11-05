import assert from "node:assert/strict";
import { readFile, writeFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const root = fileURLToPath(new URL("../../", import.meta.url)),
  origin = "http://127.0.0.1:8111";
const credentials = JSON.parse(
  await readFile(
    root +
      ".local/native-cms-integration/integrated-manager-runtime/browser-credentials.json",
    "utf8",
  ),
);
const browser = await chromium.launch({
    executablePath: process.env.CHROMIUM_EXECUTABLE || "/usr/bin/chromium",
  }),
  contexts = [],
  checks = [],
  errors = [];
const check = (ok, label) => {
  assert(ok, label);
  checks.push(label);
};
const client = async () => {
  const context = await browser.newContext({
    userAgent: "DVM synthetic duration browser " + crypto.randomUUID(),
  });
  contexts.push(context);
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
    .fill(credentials.admin.email);
  await page
    .getByLabel("Passwort", { exact: true })
    .fill(credentials.admin.password);
  await page
    .getByRole("button", { name: "Anmelden und Vereinsmanager öffnen" })
    .click();
  await page.waitForURL("**/vereinsverwaltung/**");
  return { context, page };
};
try {
  const first = await client(),
    second = await client();
  await first.page.goto(
    origin + "/vereinsverwaltung/?view=settings&tab=account#device-session",
  );
  const selection = first.page.getByLabel(
    "Wie lange möchtest du angemeldet bleiben?",
    { exact: true },
  );
  if (!(await selection.isVisible()))
    await first.page
      .locator("[data-account-panel=security] summary")
      .press("Enter");
  await selection.waitFor();
  check(
    (await selection.locator("option").allTextContents()).includes(
      "Bis ich mich abmelde",
    ),
    "original three duration choices available in native manager",
  );
  for (const width of [1440, 820, 390]) {
    await first.page.setViewportSize({ width, height: 1050 });
    await selection.focus();
    check(
      await selection.evaluate((el) => document.activeElement === el),
      "device selection supports keyboard " + width,
    );
    check(
      await first.page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth + 2,
      ),
      "native device controls fit viewport " + width,
    );
  }
  const currentCookie = async () =>
    (await first.context.cookies()).find((c) => c.name === "KH_NATIVE_MANAGER");
  const cookie = await currentCookie();
  check(
    cookie.httpOnly &&
      cookie.sameSite === "Strict" &&
      cookie.expires > Date.now() / 1000 + 86400,
    "private persistent browser cookie retains app restart login",
  );
  for (const mode of ["day", "month", "logout", "standard"]) {
    const before = (await currentCookie()).value;
    await selection.selectOption(mode);
    await first.page
      .getByRole("button", { name: "Anmeldedauer speichern", exact: true })
      .click();
    await first.page.waitForURL("**/vereinsverwaltung/**");
    await selection.waitFor();
    check(
      (await selection.inputValue()) === mode,
      "actual native HTTP action stores " + mode,
    );
    check(
      (await currentCookie()).value !== before,
      "device selection rotates current browser session " + mode,
    );
    check(
      (
        await first.page.locator("#device-session [role=status]").innerText()
      ).includes("gespeichert"),
      "saved duration is visible in original manager " + mode,
    );
    await second.page.goto(
      origin + "/vereinsverwaltung/?view=settings&tab=account",
    );
    check(
      !new URL(second.page.url()).pathname.startsWith("/login"),
      "another device remains logged in after " + mode,
    );
  }
  const form = await first.page
    .locator("[data-device-session-form]")
    .evaluate((el) => Object.fromEntries(new FormData(el).entries()));
  const response = await first.context.request.post(origin + "/account", {
    form: { ...form, mode: "other-account" },
  });
  check(
    response.status() === 422 &&
      !(await response.text()).includes("Kontostatus gespeichert"),
    "forged duration is rejected by actual native account controller",
  );
  await first.page.goto(origin + "/account");
  check(
    (await first.page
      .getByLabel("Wie lange möchtest du angemeldet bleiben?", { exact: true })
      .inputValue()) === "standard",
    "rejected form preserves selected own preference",
  );
  const logout = first.page
    .locator("form")
    .filter({ has: first.page.locator("[name=operation][value=logout]") });
  await logout.locator("button").click();
  await first.page.waitForURL("**/login*");
  const denied = await first.context.request.get(
    origin + "/vereinsverwaltung/?view=settings&tab=account",
  );
  check(
    new URL(denied.url()).pathname.startsWith("/login"),
    "persistent browser cookie cannot bypass explicit logout",
  );
  check(errors.length === 0, "no browser JavaScript errors");
  await writeFile(
    root + ".local/browser-tests/native-session-duration-report.json",
    JSON.stringify({ checks, passed: true, live_transport: false }, null, 2),
    { mode: 0o600 },
  );
  console.log(
    JSON.stringify({
      checks: checks.length,
      passed: true,
      live_transport: false,
    }),
  );
} catch (error) {
  console.error(String(error.message).replace(/[a-f0-9]{32,}/gi, "[redacted]"));
  process.exitCode = 1;
} finally {
  await Promise.all(contexts.map((c) => c.close()));
  await browser.close();
}
