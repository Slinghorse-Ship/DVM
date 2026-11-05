import assert from "node:assert/strict";
import { readFile, writeFile, mkdir } from "node:fs/promises";
import { fileURLToPath } from "node:url";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const root = fileURLToPath(new URL("../../", import.meta.url)),
  origin = "http://127.0.0.1:8111";
const fixture = JSON.parse(
  await readFile(
    root + ".local/browser-tests/native-member-accounts.json",
    "utf8",
  ),
);
const browser = await chromium.launch({
  executablePath: process.env.CHROMIUM_EXECUTABLE || "/usr/bin/chromium",
});
const checks = [],
  errors = [];
const check = (ok, label) => {
  assert(ok, label);
  checks.push(label);
};
try {
  for (const [index, person] of fixture.people.entries()) {
    const context = await browser.newContext();
    await context.route("**/*", (route) =>
      new URL(route.request().url()).origin === origin
        ? route.continue()
        : route.abort(),
    );
    const page = await context.newPage();
    page.on("pageerror", (error) => errors.push(error.message));
    if (person.fresh) {
      await page.goto(origin + "/invite?token=" + person.setup_token);
      await page
        .getByLabel("Neues Passwort", { exact: true })
        .fill(person.password);
      await page.getByLabel("Passwort wiederholen").fill(person.password);
      await Promise.all([
        page.waitForNavigation(),
        page
          .getByRole("button", { name: "Konto einrichten", exact: true })
          .click(),
      ]);
      check(
        (await page.getByRole("status").innerText()).includes("eingerichtet"),
        "personal invitation accepts own password " + index,
      );
    } else await page.goto(origin + "/login/");
    await page
      .getByLabel("E-Mail-Adresse oder persönlicher Benutzername", {
        exact: true,
      })
      .fill(person.username);
    await page.getByLabel("Passwort", { exact: true }).fill(person.password);
    await Promise.all([
      page.waitForURL("**/vereinsverwaltung/**"),
      page
        .getByRole("button", { name: "Anmelden und Vereinsmanager öffnen" })
        .click(),
    ]);
    check(
      new URL(page.url()).searchParams.get("view") === "portal",
      "personal account lands in original member portal " + index,
    );
    check(
      (await page.locator("body").innerText()).includes(person.name),
      "personal account retains original profile " + index,
    );
    const forbidden = await context.request.get(origin + "/accounts");
    check(
      forbidden.status() === 403,
      "member has no account administration " + index,
    );
    for (const width of [1440, 820, 390]) {
      await page.setViewportSize({ width, height: 1000 });
      check(
        await page.evaluate(
          () => document.documentElement.scrollWidth <= innerWidth + 2,
        ),
        "personal portal fits viewport " + index + "/" + width,
      );
      check(
        !/Fatal error:|Warning:|Notice:|Parse error:/.test(
          await page.locator("body").innerText(),
        ),
        "personal portal has no runtime error " + index + "/" + width,
      );
    }
    await page.goto(origin + "/account");
    check(
      (await page.locator("body").innerText()).includes(person.username),
      "account screen displays own login name " + index,
    );
    await context.close();
  }
  const context = await browser.newContext();
  await context.route("**/*", (route) =>
    new URL(route.request().url()).origin === origin
      ? route.continue()
      : route.abort(),
  );
  const page = await context.newPage();
  await page.goto(origin + "/login/");
  await page
    .getByLabel("E-Mail-Adresse oder persönlicher Benutzername", {
      exact: true,
    })
    .fill("native-browser-family@example.invalid");
  await page
    .getByLabel("Passwort", { exact: true })
    .fill(fixture.people[0].password);
  await Promise.all([
    page.waitForNavigation(),
    page
      .getByRole("button", { name: "Anmelden und Vereinsmanager öffnen" })
      .click(),
  ]);
  check(
    (await page.getByRole("alert").count()) === 1,
    "ambiguous shared email never logs into an arbitrary family account",
  );
  check(
    new URL(page.url()).pathname.startsWith("/login"),
    "ambiguous email remains on login page",
  );
  await context.close();
  check(errors.length === 0, "no browser JavaScript errors");
  await mkdir(root + ".local/browser-tests", { recursive: true });
  await writeFile(
    root + ".local/browser-tests/native-member-accounts-report.json",
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
  await browser.close();
}
