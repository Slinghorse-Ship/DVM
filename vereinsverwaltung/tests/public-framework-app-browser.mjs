import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const root = fileURLToPath(new URL("../../", import.meta.url));
const session = JSON.parse(
  await readFile(
    root + ".local/browser-tests/controls-sessions/admin.json",
    "utf8",
  ),
);
const browser = await chromium.launch({
  executablePath:
    process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || "/usr/bin/chromium",
});
let checks = 0;
const errors = [],
  external = [],
  writes = [];
const check = (ok, label) => {
  assert(ok, label);
  checks++;
  console.log("PASS " + label);
};
const poll = async (fn) => {
  for (let i = 0; i < 100; i++) {
    try {
      if (await fn()) return;
    } catch {}
    await new Promise((r) => setTimeout(r, 100));
  }
  throw Error("Actual public framework did not become ready");
};
try {
  for (const standalone of [true, false]) {
    const name = standalone ? "standalone" : "integrated",
      origin = "http://127.0.0.1:" + (standalone ? 8107 : 8111);
    const f = JSON.parse(
      await readFile(
        root + ".local/browser-tests/public-albums-" + name + ".json",
        "utf8",
      ),
    );
    for (const javaScriptEnabled of [true, false]) {
      const mode = name + (javaScriptEnabled ? " JS" : " native");
      const context = await browser.newContext({
        javaScriptEnabled,
        storageState: standalone ? undefined : session,
        hasTouch: true,
        viewport: { width: 320, height: 880 },
      });
      await context.route("**/*", (route) => {
        const request = route.request();
        if (new URL(request.url()).origin !== origin) {
          external.push(request.url());
          return route.abort();
        }
        if (request.method() !== "GET") writes.push(request.method());
        return route.continue();
      });
      const page = await context.newPage();
      page.on("pageerror", (e) => errors.push(e.message));
      const publicUrl =
        origin +
        (standalone ? "/site/" + f.album.slug : "/" + f.album.slug + "/");
      const response = await page.goto(publicUrl + "?page=2", {
        waitUntil: "domcontentloaded",
      });
      check(
        response.ok(),
        mode + " actual published original remains reachable",
      );
      await poll(() =>
        page.evaluate(() =>
          [...document.querySelectorAll('link[rel="stylesheet"]')].every(
            (link) => link.sheet,
          ),
        ),
      );
      const links = await page
        .locator("link[rel=stylesheet]")
        .evaluateAll((ns) => ns.map((n) => n.href));
      check(
        links.filter((u) => u.includes("bootstrap-6.0.0-alpha.1.min.css"))
          .length === 1 &&
          links.filter((u) => u.includes("bootstrap-dvm.css")).length === 1,
        mode + " actual local alpha base and common adapters load once",
      );
      check(
        await page
          .locator(".public-gallery-pager select")
          .first()
          .evaluate(
            (n) =>
              n.classList.contains("form-control") &&
              getComputedStyle(n).appearance === "auto",
          ),
        mode + " real original page chooser keeps its native accessible arrow",
      );
      check(
        (
          await page.locator("script").evaluateAll((ns) => ns.map((n) => n.src))
        ).every(
          (u) => !u.includes("manager-controls") && !u.includes("social-chat"),
        ),
        mode + " public original view loads only its public interactions",
      );
      for (const key of ["klar", "magazin", "aktiv", "natur", "kulturhus"]) {
        const preview =
          origin +
          (standalone
            ? "/design-preview?template=" + key
            : "/vereinsverwaltung/?native_endpoint=design-preview&template=" +
              key);
        const actual = await page.goto(preview, {
          waitUntil: "domcontentloaded",
        });
        check(
          actual.ok() && actual.headers()["cache-control"].includes("no-store"),
          mode + " actual private read-only template " + key,
        );
        check(
          await page
            .locator("body")
            .evaluate(
              (n, k) =>
                n.classList.contains(
                  k === "kulturhus" ? "theme-kultur" : "design-" + k,
                ),
              key,
            ),
          mode + " server renders actual chosen template " + key,
        );
        await poll(() =>
          page.evaluate(() =>
            [...document.querySelectorAll('link[rel="stylesheet"]')].every(
              (link) => link.sheet,
            ),
          ),
        );
        for (const width of [320, 390, 820]) {
          await page.setViewportSize({ width, height: 880 });
          await page.evaluate(() => {
            document.body.style.zoom = "2";
            window.scrollTo(0, 0);
          });
          check(
            await page.evaluate(
              () =>
                document.documentElement.scrollWidth <=
                document.documentElement.clientWidth + 2,
            ),
            mode +
              " actual " +
              key +
              " template fits " +
              width +
              " at 200 percent",
          );
          await page.evaluate(() => (document.body.style.zoom = "1"));
        }
        await page.setViewportSize({ width: 320, height: 880 });
        if (javaScriptEnabled) {
          const menu = page.getByRole("button", { name: "Menü", exact: true });
          await menu.focus();
          await menu.press("Enter");
          check(
            (await menu.getAttribute("aria-expanded")) === "true",
            mode + " actual " + key + " keyboard opens public navigation",
          );
          await menu.press("Enter");
          check(
            (await menu.getAttribute("aria-expanded")) === "false",
            mode + " actual " + key + " keyboard closes public navigation",
          );
        }
        const targets = await page.locator(".site-footer a").evaluateAll((ns) =>
          ns.every((n) => {
            const r = n.getBoundingClientRect();
            return r.width >= 44 && r.height >= 44;
          }),
        );
        check(
          targets,
          mode + " actual " + key + " footer retains 44 pixel targets",
        );
      }
      if (!standalone) {
        const membership = await page.goto(origin + "/mitglied-werden/", {
          waitUntil: "domcontentloaded",
        });
        check(membership.ok(), mode + " actual public membership form opens");
        await poll(() =>
          page.evaluate(() =>
            [...document.querySelectorAll('link[rel="stylesheet"]')].every(
              (n) => n.sheet,
            ),
          ),
        );
        const form = page.locator(".kh-membership-form");
        await form.locator('[name="first_name"]').fill("Synthetische Vorschau");
        await form.locator('[name="email"]').fill("preview@example.invalid");
        check(
          await form
            .locator("select")
            .evaluate(
              (n) =>
                n.classList.contains("form-control") &&
                getComputedStyle(n).appearance === "auto",
            ),
          mode + " actual membership chooser uses the native accessible arrow",
        );
        if (javaScriptEnabled) {
          await form.locator('[name="membership_type"]').selectOption("family");
          check(
            await form.locator("[data-family-fields]").isVisible(),
            mode + " actual family selection opens its fields",
          );
          await form.locator('[name="membership_type"]').selectOption("single");
        }
        for (const width of [320, 390, 820]) {
          await page.setViewportSize({ width, height: 880 });
          await page.evaluate(() => (document.body.style.zoom = "2"));
          check(
            await page.evaluate(
              () =>
                document.documentElement.scrollWidth <=
                document.documentElement.clientWidth + 2,
            ),
            mode + " actual membership form fits " + width + " at 200 percent",
          );
          await page.evaluate(() => (document.body.style.zoom = "1"));
        }
        check(
          (await form.locator('[name="first_name"]').inputValue()) ===
            "Synthetische Vorschau" &&
            (await form.locator('[name="email"]').inputValue()) ===
              "preview@example.invalid",
          mode + " actual responsive form retains unsent input",
        );
        check(
          await form
            .locator(".form-control")
            .evaluateAll((ns) =>
              ns
                .filter((n) => n.getClientRects().length)
                .every(
                  (n) =>
                    n.getBoundingClientRect().height >= 44 &&
                    parseFloat(getComputedStyle(n).fontSize) >= 16,
                ),
            ),
          mode +
            " actual visible membership controls retain readable touch targets",
        );
      }
      await context.close();
    }
  }
  check(
    writes.length === 0,
    "all framework and template browser checks are read-only",
  );
  check(
    errors.length === 0,
    "both actual public frameworks have no JavaScript errors",
  );
  check(
    external.length === 0,
    "all actual template assets stay on owned runtimes",
  );
  console.log(
    JSON.stringify({
      suite: "public-framework-app-browser",
      checks,
      writes: 0,
      external_requests: external.length,
    }),
  );
} finally {
  await browser.close();
}
