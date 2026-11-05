import assert from "node:assert/strict";
import { readFile, mkdir } from "node:fs/promises";
import { fileURLToPath } from "node:url";
const { chromium } = await import(
    process.env.PLAYWRIGHT_MODULE || "playwright"
  ),
  root = fileURLToPath(new URL("../../", import.meta.url)),
  runtime = root + ".local/native-cms-integration/integrated-manager-runtime/",
  output = root + ".local/browser-tests";
const credentials = JSON.parse(
  await readFile(runtime + "browser-credentials.json", "utf8"),
);
let checks = 0;
const check = (ok, label) => {
  assert(ok, label);
  checks++;
  console.log("PASS " + label);
};
await mkdir(output, { recursive: true, mode: 0o700 });
const browser = await chromium.launch({
  executablePath: process.env.CHROMIUM_EXECUTABLE || "/usr/bin/chromium",
});
try {
  for (const standalone of [false, true]) {
    const fixture = JSON.parse(
        await readFile(
          standalone
            ? output + "/page-versions-standalone.json"
            : runtime + "page-versions-browser.json",
          "utf8",
        ),
      ),
      origin = standalone ? "http://127.0.0.1:8107" : "http://127.0.0.1:8111",
      name = standalone ? "standalone" : "integrated",
      context = await browser.newContext(),
      page = await context.newPage(),
      posts = [],
      external = [],
      errors = [];
    await context.route("**/*", (route) =>
      new URL(route.request().url()).origin === origin
        ? route.continue()
        : (external.push(route.request().url()), route.abort()),
    );
    page.on("pageerror", (error) => errors.push(error.message));
    page.on("request", (request) => {
      if (
        request.method() === "POST" &&
        !new URL(request.url()).pathname.startsWith("/login")
      )
        posts.push(request.url());
    });
    if (!standalone) {
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
    }
    const itemUrl = (item, args = {}) =>
      origin +
      (standalone ? "/?" : "/vereinsverwaltung/?") +
      new URLSearchParams(
        standalone
          ? { view: item.view, item: item.id, ...args }
          : {
              view: "website",
              tab: {
                website: "pages",
                events: "events",
                news: "news",
                media: "gallery",
              }[item.view],
              native_item: item.id,
              ...args,
            },
      );
    const publicBytes = new Map();
    for (const item of fixture.items) {
      const response = await context.request.get(
        origin + "/site/" + item.slug + (standalone ? "" : "/"),
      );
      check(
        response.status() === 200,
        name + " actual original public " + item.kind,
      );
      publicBytes.set(item.id, await response.body());
    }
    const imageUrl = standalone
        ? origin + "/media-preview/" + fixture.images[0] + "/original"
        : origin +
          "/vereinsverwaltung/?" +
          new URLSearchParams({
            native_endpoint: "media-preview",
            media: fixture.images[0],
            variant: "original",
          }),
      pixels = await context.request.get(imageUrl),
      pixelBytes = await pixels.body();
    check(
      pixels.status() === 200,
      name + " actual historical original picture available",
    );
    for (const width of [1440, 820, 390]) {
      await page.setViewportSize({ width, height: 1050 });
      for (const item of fixture.items) {
        await page.goto(itemUrl(item));
        const panel = page.locator("[data-page-versions]"),
          form = panel.locator("[data-version-compare-form]");
        await panel.waitFor();
        check(!await panel.evaluate(node=>node.open),name+' saved history starts collapsed');
        await panel.locator(':scope > summary').click();
        check(
          (await panel.locator("[data-saved-version]").count()) === 25,
          name +
            " bounded complete original history " +
            item.kind +
            " " +
            width,
        );
        check(
          (await form.locator("[name=compare_from]").inputValue()) ===
            String(item.published) &&
            (await form.locator("[name=compare_to]").inputValue()) ===
              String(item.current),
          name +
            " published original retained outside first history slice " +
            item.kind +
            " " +
            width,
        );
        check(
          (await form.locator("[name=compare_from] option").count()) <= 27,
          name +
            " selected comparison catalog remains bounded " +
            item.kind +
            " " +
            width,
        );
        const fetched = page.waitForResponse(
          (response) =>
            response.request().method() === "GET" &&
            new URL(response.url()).searchParams.get("compare_from") ===
              String(item.published) &&
            new URL(response.url()).searchParams.get("compare_to") ===
              String(item.current),
        );
        await form
          .getByRole("button", { name: "Versionen vergleichen", exact: true })
          .click();
        check(
          (await fetched).status() === 200,
          name +
            " actual compare form uses successful GET " +
            item.kind +
            " " +
            width,
        );
        await panel.locator("[data-version-comparison]").waitFor();
        await page.waitForURL(
          (url) =>
            url.searchParams.get("compare_from") === String(item.published),
        );
        const title = panel.locator("[data-version-change=title]");
        check(
          (await title.innerText()).includes(item.old_title) &&
            (await title.innerText()).includes(item.new_title),
          name +
            " original titles shown side by side " +
            item.kind +
            " " +
            width,
        );
        const content = panel.locator("[data-version-change=content]");
        check(
          (await content.locator("strong").innerText()) ===
            "body " + item.kind &&
            (await content.locator("em").innerText()) === "body " + item.kind &&
            (await content.innerText()).includes("<marker>"),
          name +
            " safe original rich formatting and literal characters " +
            item.kind +
            " " +
            width,
        );
        if (item.kind === "page") {
          check(
            (
              await panel
                .locator(
                  '[data-version-change="custom.' + fixture.prefix + 'amount"]',
                )
                .innerText()
            ).includes("999999999999999999.999997") &&
              (
                await panel
                  .locator(
                    '[data-version-change="custom.' +
                      fixture.prefix +
                      'amount"]',
                  )
                  .innerText()
              ).includes("999999999999999999.999998"),
            name + " adjacent large decimals remain exact " + width,
          );
          check(
            (
              await panel
                .locator(
                  '[data-version-change="custom.' + fixture.prefix + 'active"]',
                )
                .innerText()
            ).includes("Nein") &&
              (
                await panel
                  .locator(
                    '[data-version-change="custom.' +
                      fixture.prefix +
                      'active"]',
                  )
                  .innerText()
              ).includes("Ja"),
            name + " original false is a readable choice " + width,
          );
          check(
            (
              await panel
                .locator(
                  '[data-version-change="definition.browser_version_formatted"]',
                )
                .innerText()
            ).includes("Own historical formatted") &&
              (
                await panel
                  .locator(
                    '[data-version-change="definition.browser_version_formatted"]',
                  )
                  .innerText()
              ).includes("Own changed formatted"),
            name +
              " old field labels and versions survive current model " +
              width,
          );
          check(
            (
              await panel
                .locator("[data-version-change=template_definition]")
                .innerText()
            ).includes("Own historical group") &&
              (
                await panel
                  .locator("[data-version-change=template_definition]")
                  .innerText()
              ).includes("Own changed <group>"),
            name + " historical nested model remains literal " + width,
          );
        }
        if (item.kind === "gallery")
          check(
            (
              await panel
                .locator("[data-version-change=gallery_items]")
                .innerText()
            ).includes("Own original first picture") &&
              (
                await panel
                  .locator("[data-version-change=gallery_items]")
                  .innerText()
              ).includes("Own changed second picture <literal>"),
            name +
              " original image order descriptions and tags compare " +
              width,
          );
        if (item.kind === "event")
          check(
            (
              await panel
                .locator("[data-version-change=event_location]")
                .innerText()
            ).includes("Own old event location") &&
              (
                await panel
                  .locator("[data-version-change=event_location]")
                  .innerText()
              ).includes("Own new event location"),
            name + " actual original event details compare " + width,
          );
        check(
          await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth + 1,
          ),
          name +
            " shared Bootstrap original comparison fits " +
            item.kind +
            " " +
            width,
        );
        if (item.kind === "page") {
          await page.screenshot({
            path: output + "/dvm-page-versions-" + name + "-" + width + ".png",
            fullPage: true,
          });
          await panel
            .locator("[data-version-comparison]")
            .evaluate((element) => element.scrollIntoView({ block: "start" }));
          await page.screenshot({
            path:
              output +
              "/dvm-page-versions-focus-" +
              name +
              "-" +
              width +
              ".png",
            fullPage: false,
          });
        }
        await panel
          .getByRole("link", { name: "Ältere Versionen", exact: true })
          .click();
        await page.waitForURL(
          (url) => url.searchParams.get("versions_page") === "2",
        );
        check(
          (await panel
            .locator('[data-saved-version="' + item.published + '"]')
            .count()) === 1 &&
            (await form.locator("[name=compare_from]").inputValue()) ===
              String(item.published) &&
            (await panel.locator("[data-version-comparison]").count()) === 1,
          name +
            " history navigation retains original editor and selected comparison " +
            item.kind +
            " " +
            width,
        );
      }
    }
    const item = fixture.items.find((item) => item.kind === "page");
    await page.goto(
      origin +
        (standalone
          ? "/?view=news"
          : "/vereinsverwaltung/?view=website&tab=news"),
    );
    const search = page.locator("form.content-list-filters");
    await search.locator("[name=q]").fill("Own browser versions news");
    await search.getByRole("button", { name: "Suchen", exact: true }).click();
    await page.waitForURL(
      (url) => url.searchParams.get("q") === "Own browser versions news",
    );
    check(
      (await page.locator(".content-list-count").innerText()) === "1 Beiträge",
      name +
        " existing news filter retains actual GET query and original results",
    );
    check(
      (await page.locator("dialog[open]").count()) === 0 && posts.length === 0,
      name + " query forms do not become dirty content or post writes",
    );
    // The native GET form also remains usable without scripts.
    const fallback = await browser.newContext({
        javaScriptEnabled: false,
        storageState: await context.storageState(),
      }),
      fallbackPage = await fallback.newPage();
    await fallback.route("**/*", (route) =>
      new URL(route.request().url()).origin === origin
        ? route.continue()
        : (external.push(route.request().url()), route.abort()),
    );
    await fallbackPage.goto(itemUrl(item));
    await fallbackPage.locator('[data-page-versions] > summary').click();
    await Promise.all([
      fallbackPage.waitForURL(
        (url) =>
          url.searchParams.get("compare_from") === String(item.published),
      ),
      fallbackPage
        .locator("[data-version-compare-form]")
        .getByRole("button", { name: "Versionen vergleichen", exact: true })
        .click(),
    ]);
    check(
      (await fallbackPage.locator("[data-version-change=title]").count()) ===
        1 &&
        new URL(fallbackPage.url()).searchParams.get(
          standalone ? "item" : "native_item",
        ) === item.id,
      name + " no-script native compare GET retains exact original identity",
    );
    await fallback.close();
    check(
      posts.length === 0,
      name + " all comparisons and history pages are read-only",
    );
    // Deliberate restore uses the existing protected source operation and creates only a new private draft.
    await page.goto(
      itemUrl(item, {
        versions_page: "2",
        compare_from: String(item.published),
        compare_to: String(item.current),
      }),
    );
    const restore = page.locator(
      '[data-saved-version="' + item.published + '"] form',
    );
    const restored = page.waitForResponse(
      (response) =>
        response.request().method() === "POST" &&
        new URL(response.url()).origin === origin,
    );
    await restore
      .getByRole("button", { name: "Als Entwurf laden", exact: true })
      .click();
    check(
      (await restored).status() === (standalone ? 200 : 303),
      name + " explicit restoration keeps actual original save contract",
    );
    await page.waitForFunction(
      (version) =>
        Number(
          document
            .querySelector("input[name=operation][value=page-save]")
            ?.form.querySelector("[name=version]").value,
        ) === version,
      item.current + 1,
    );
    check(
      (await page.locator("input[name=title]").inputValue()) ===
        item.old_title &&
        (await page
          .locator('[name="custom_fields[' + fixture.prefix + 'amount]"]')
          .inputValue()) === item.old_values[fixture.prefix + "amount"] &&
        (await page
          .locator('[name="custom_fields[' + fixture.prefix + 'active]"]')
          .inputValue()) === "0",
      name +
        " restored original becomes one private revision with exact decimal and false",
    );
    const malformed = await context.request.get(
      itemUrl(item, { compare_from: "0", compare_to: String(item.current) }),
    );
    check(
      malformed.status() === 422,
      name + " invalid comparison version rejected through actual HTTP",
    );
    for (const current of fixture.items)
      check(
        (
          await (
            await context.request.get(
              origin + "/site/" + current.slug + (standalone ? "" : "/"),
            )
          ).body()
        ).equals(publicBytes.get(current.id)),
        name +
          " all version workflows retain exact public " +
          current.kind +
          " bytes",
      );
    check(
      (await (await context.request.get(imageUrl)).body()).equals(pixelBytes),
      name + " actual historical picture bytes retained",
    );
    check(
      posts.length === 1,
      name + " only the deliberate own private restoration writes",
    );
    check(
      errors.length === 0,
      name + " no browser script errors " + JSON.stringify(errors),
    );
    check(external.length === 0, name + " no external or provider requests");
    await context.close();
  }
  console.log(
    JSON.stringify({
      suite: "native-page-versions-browser",
      checks,
      actual_private_restores: 2,
      external_actions: 0,
    }),
  );
} finally {
  await browser.close();
}
