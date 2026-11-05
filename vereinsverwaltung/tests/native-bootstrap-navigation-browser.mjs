import assert from "node:assert/strict";
import {actualOwnMenuPage,actualOwnMenuRoutes} from "./support/owned-menu-client.mjs";
import { readFile, mkdir, writeFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";

const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const root = fileURLToPath(new URL("../../", import.meta.url));
const owned=process.env.DVM_MANAGER_CONTROLS_FIXTURE?JSON.parse(await readFile(process.env.DVM_MANAGER_CONTROLS_FIXTURE,'utf8')):null;
const origin=owned?.origin||"http://127.0.0.1:8111";
const credentials=owned?null:JSON.parse(await readFile(root+".local/native-cms-integration/integrated-manager-runtime/browser-credentials.json","utf8"));
const browser = await chromium.launch({
  executablePath: process.env.CHROMIUM_EXECUTABLE || "/usr/bin/chromium",
});
const result = { routes: [], errors: [], external: [], live_transport: false };
try {
  for (const role of ["admin", "member"]) {
    const context = await browser.newContext();
    await context.route("**/*", (route) => {
      if (new URL(route.request().url()).origin === origin)
        return route.continue();
      result.external.push(new URL(route.request().url()).origin);
      return route.abort();
    });
    const page = await context.newPage();
    page.on("pageerror", (error) =>
      result.errors.push({ role, message: error.message }),
    );
    if(owned)await page.goto(origin+"/own-test-enter?role="+role);
    else{
    await page.goto(origin + "/login/");
    await page.locator("[name=email]").fill(credentials[role].email);
    await page.locator("[name=password]").fill(credentials[role].password);
    await Promise.all([
      page.waitForURL("**/vereinsverwaltung/**"),
      page
        .getByRole("button", {
          name: "Anmelden und Vereinsmanager öffnen",
          exact: true,
        })
        .click(),
    ]);
    }
    const routes = await actualOwnMenuRoutes(page,origin);
    assert(
      routes.length >= (role === "admin" ? 20 : 3),
      "populated role-specific menu",
    );
    for (const width of (owned?[1440,820,390,320]:[1440,820,390])) {
      await page.setViewportSize({ width, height: 1050 });
      for (const route of routes) {
        console.log('Checking actual own navigation',role,width,route.label);
        const response = await actualOwnMenuPage(page,route.url,owned,assert);
        assert.equal(response.status(), 200, role + " " + route.label);
        await page.waitForFunction(
          () => typeof window.KulturhusNavigation?.navigate === "function",
        );
        assert(
          !/Fatal error:|SQLSTATE\[|Stack trace:|Parse error:/.test(
            await page.locator("main").innerText(),
          ),
          "working page " + route.label,
        );
        assert.equal(
          await page.locator("script[src*=bootstrap][type=module]").count(),
          1,
          "one framework module " + route.label,
        );
        assert.equal(
          await page.locator("#navigation .subnav a:not(.nav-link)").count(),
          0,
          "every visible sidebar item uses the shared Bootstrap navigation",
        );
        assert(
          await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth + 2,
          ),
          role + " layout " + width + " " + route.label,
        );
        const active = page.locator("#navigation .subnav a[aria-current=page]");
        if (await active.count()) {
          assert(
            await active
              .first()
              .evaluate((link) => link.classList.contains("active")),
            "current item is visibly active",
          );
          if (await page.getByRole("button", { name: "Navigation öffnen", exact: true }).isVisible()) {
            await page
              .getByRole("button", { name: "Navigation öffnen", exact: true })
              .click();
            await page.waitForFunction(
              () =>
                document.querySelector("#navigation").getBoundingClientRect()
                  .left >= -1,
            );
            await active.first().focus();
            assert(
              await active
                .first()
                .evaluate((link) => document.activeElement === link),
              "keyboard can reach active mobile item",
            );
            assert(
              await active.first().evaluate((link) => {
                const box = link.getBoundingClientRect();
                return box.left >= 0 && box.right <= innerWidth;
              }),
              "mobile menu label fits: " + role + " " + route.label,
            );
            await page.keyboard.press("Escape");
            assert.equal(
              await page
                .getByRole("button", { name: "Navigation öffnen", exact: true })
                .getAttribute("aria-expanded"),
              "false",
            );
          }
        }
        result.routes.push({
          role,
          width,
          label: route.label,
          route: new URL(route.url).search,
        });
      }
      // Use the real rendered menu and existing lifecycle for a soft-navigation round trip.
      const target = routes.find(
        (route) => new URL(route.url).searchParams.get("view") === "calendar",
      );
      if (target) {
        await page.goto(origin + "/vereinsverwaltung/?view=dashboard");
        await page.waitForFunction(
          () => typeof window.KulturhusNavigation?.navigate === "function",
        );
        await page.evaluate(() => {
          window.bootstrapNavigationMarker = "same-document";
        });
        if (await page.getByRole("button", { name: "Navigation öffnen", exact: true }).isVisible())
          await page
            .getByRole("button", { name: "Navigation öffnen", exact: true })
            .click();
        const link = page
          .locator("#navigation .subnav a")
          .filter({ hasText: /^Kalender$/ })
          .first();
        await link.evaluate((element) => {
          element.closest("details").open = true;
        });
        await link.click();
        await page.waitForFunction(
          () =>
            new URL(location.href).searchParams.get("view") === "calendar" &&
            !document.querySelector("main")?.hasAttribute("aria-busy"),
        );
        assert.equal(
          await page.evaluate(() => window.bootstrapNavigationMarker),
          "same-document",
          "menu navigation retains the document and framework module",
        );
        assert.equal(
          await page.locator("script[src*=bootstrap][type=module]").count(),
          1,
        );
      }
      console.log(
        "PASS " + role + " menu pages at " + width + " px: " + routes.length,
      );
    }
    await context.close();
  }
  assert.deepEqual(result.errors, []);
  assert.deepEqual(result.external, []);
  if(!owned)await mkdir(root + ".local/browser-tests", { recursive: true, mode: 0o700 });
  await writeFile(
    owned?owned.root+"/bootstrap-navigation-result.json":root + ".local/browser-tests/bootstrap-navigation-result.json",
    JSON.stringify(result, null, 2),
    { mode: 0o600 },
  );
  console.log(
    result.routes.length +
      " actual role/menu page states passed including mobile keyboard and same-document navigation; no external transport.",
  );
} finally {
  await browser.close();
}
