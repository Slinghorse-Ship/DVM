import assert from "node:assert/strict";
import { readFile, writeFile, mkdir } from "node:fs/promises";
import { fileURLToPath } from "node:url";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const root = fileURLToPath(new URL("../../", import.meta.url)),
  origin = "http://127.0.0.1:8111";
const fixture = JSON.parse(
  await readFile(root + ".local/browser-tests/path-history.json", "utf8"),
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
const checks = [],
  errors = [];
try {
  const context = await browser.newContext();
  await context.route("**/*", (route) =>
    new URL(route.request().url()).origin === origin
      ? route.continue()
      : route.abort(),
  );
  const page = await context.newPage();
  page.on("pageerror", (error) => errors.push(error.message));
  await page.goto(origin + "/login/");
  await page
    .getByLabel("E-Mail-Adresse oder persönlicher Benutzername", { exact: true })
    .fill(credentials.admin.email);
  await page
    .getByLabel("Passwort", { exact: true })
    .fill(credentials.admin.password);
  await Promise.all([
    page.waitForURL("**/vereinsverwaltung/**"),
    page
      .getByRole("button", { name: "Anmelden und Vereinsmanager öffnen" })
      .click(),
  ]);
  await page.goto(origin + fixture.url);
  const formFor = (operation) =>
    page
      .locator("form")
      .filter({
        has: page.locator('input[name=operation][value="' + operation + '"]'),
      });
  const save = formFor("page-save");
  if ((await save.locator("[name=slug]").inputValue()) !== fixture.current) {
    const version = Number(await save.locator("[name=version]").inputValue());
    await save.locator("[name=slug]").fill(fixture.current);
    await save.getByRole("button", { name: "Entwurf speichern" }).click();
    await page.waitForFunction(
      (expected) =>
        Number(
          document
            .querySelector("input[name=operation][value=page-save]")
            ?.form.querySelector("[name=version]")?.value,
        ) === expected,
      version + 1,
    );
    await formFor("page-publish")
      .getByRole("button", { name: "Veröffentlichen", exact: true })
      .click();
    await page.waitForFunction(
      (expected) =>
        Number(
          document
            .querySelector("input[name=operation][value=page-save]")
            ?.form.querySelector("[name=version]")?.value,
        ) === expected,
      version + 2,
    );
  }
  const panel = () =>
    page
      .locator("details")
      .filter({
        has: page
          .locator("summary")
          .filter({ hasText: "Alte Links & Weiterleitungen" }),
      });
  await panel().locator("summary").click();
  assert((await panel().innerText()).includes("/" + fixture.old + "/"));
  const stale = Object.fromEntries(
    await formFor("page-path-add").evaluate((form) => [
      ...new FormData(form).entries(),
    ]),
  );
  await formFor("page-path-add")
    .getByLabel("Früherer Inhaltspfad ohne Domain")
    .fill(fixture.import);
  await formFor("page-path-add")
    .getByRole("button", { name: "Alten Link übernehmen" })
    .click();
  await page.waitForFunction(
    (slug) =>
      [...document.querySelectorAll("code")].some(
        (el) => el.textContent === "/" + slug + "/",
      ),
    fixture.import,
  );
  for (const width of [1440, 820, 390]) {
    await page.setViewportSize({ width, height: 1050 });
    await page.reload();
    await panel().locator("summary").click();
    assert((await panel().innerText()).includes("/" + fixture.old + "/"));
    await formFor("page-path-add").getByRole("button").focus();
    assert(
      await formFor("page-path-add")
        .getByRole("button")
        .evaluate((el) => document.activeElement === el),
    );
    assert(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth + 2,
      ),
    );
    await mkdir(root + ".local/browser-tests", {
      recursive: true,
      mode: 0o700,
    });
    await page.screenshot({
      path: root + ".local/browser-tests/path-history-" + width + ".png",
      fullPage: true,
    });
    checks.push({ width, history: true, keyboard: true, overflow: false });
  }
  const old = await context.request.get(
    origin + "/" + fixture.old + "/?page=2",
    { maxRedirects: 0 },
  );
  assert.equal(old.status(), 308);
  assert.equal(old.headers().location, "/" + fixture.current + "/?page=2");
  const head = await context.request.head(origin + "/" + fixture.import + "/", {
    maxRedirects: 0,
  });
  assert.equal(head.status(), 308);
  assert.equal((await head.body()).length, 0);
  const unsafe = await context.request.post(origin + "/" + fixture.old + "/", {
    maxRedirects: 0,
    form: { unrelated: "1" },
  });
  assert.equal(unsafe.status(), 405);
  await page.goto(origin + "/" + fixture.old + "/");
  assert.equal(new URL(page.url()).pathname, "/" + fixture.current + "/");
  assert(
    (await page.locator("body").innerText()).includes(
      "Browserprüfung alte Seitenlinks",
    ),
  );
  for (const asset of [
    "/assets/kulturhus-site.css",
    "/assets/saxophone.webp",
    "/site/assets/kulturhus-public/apple-touch-icon.png",
  ]) {
    const response = await context.request.get(origin + asset);
    assert.equal(response.status(), 200);
    assert((await response.body()).length > 0);
  }
  await page.goto(origin + fixture.url);
  await panel().locator("summary").click();
  const imported = panel()
    .locator("li")
    .filter({
      has: page.locator("code").filter({ hasText: "/" + fixture.import + "/" }),
    });
  const removalForm = imported.locator("form");
  assert.equal(
    await removalForm.locator("[name=confirmed]").isChecked(),
    false,
  );
  const unchecked = Object.fromEntries(
    await removalForm.evaluate((form) => [...new FormData(form).entries()]),
  );
  const denied = await context.request.post(origin + fixture.url, {
    headers: { Accept: "application/json" },
    form: unchecked,
  });
  assert.equal(denied.status(), 422);
  await removalForm.locator("[name=confirmed]").check();
  await removalForm.getByRole("button").click();
  await page.waitForFunction(
    (slug) =>
      ![...document.querySelectorAll("code")].some(
        (el) => el.textContent === "/" + slug + "/",
      ),
    fixture.import,
  );
  const gone = await context.request.get(origin + "/" + fixture.import + "/", {
    maxRedirects: 0,
  });
  assert.equal(gone.status(), 404);
  const csrf = await context.request.post(origin + fixture.url, {
    headers: { Accept: "application/json" },
    form: { ...stale, csrf: "wrong", slug: "should-never-exist" },
  });
  assert.equal(csrf.status(), 422);
  assert.deepEqual(errors, []);
  await writeFile(
    root + ".local/browser-tests/path-history-result.json",
    JSON.stringify(
      {
        checks,
        actual_redirect: true,
        queries: true,
        head: true,
        post_denied: true,
        confirmed_removal: true,
        own_assets: true,
        live_transport: false,
      },
      null,
      2,
    ),
    { mode: 0o600 },
  );
  console.log(
    "Native path history: saved rename/import, original public 308 and query, HEAD/POST, confirmed removal, project assets and keyboard at 1440/820/390 px passed.",
  );
} finally {
  await browser.close();
}
