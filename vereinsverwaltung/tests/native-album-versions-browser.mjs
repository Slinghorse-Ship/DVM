import assert from "node:assert/strict";
import { readFile, mkdir } from "node:fs/promises";
import { fileURLToPath } from "node:url";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const root = fileURLToPath(new URL("../../", import.meta.url)),
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
      item = fixture.items.find((item) => item.kind === "gallery"),
      origin = standalone ? "http://127.0.0.1:8107" : "http://127.0.0.1:8111",
      name = standalone ? "standalone" : "integrated",
      context = await browser.newContext(),
      page = await context.newPage(),
      errors = [],
      external = [],
      posts = [];
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
    const editorUrl =
        origin +
        (standalone ? "/?" : "/vereinsverwaltung/?") +
        new URLSearchParams(
          standalone
            ? { view: "media", item: item.id, versions_page: "2" }
            : {
                view: "website",
                tab: "gallery",
                native_item: item.id,
                versions_page: "2",
              },
        ),
      publicUrl = origin + "/site/" + item.slug + (standalone ? "" : "/"),
      publicResponse = await context.request.get(publicUrl),
      publicBytes = await publicResponse.body(),
      pixels = new Map();
    check(
      publicResponse.status() === 200,
      name + " own original published album available",
    );
    const imageUrl = (id, variant) =>
      standalone
        ? origin + "/media-preview/" + id + "/" + variant
        : origin +
          "/vereinsverwaltung/?" +
          new URLSearchParams({
            native_endpoint: "media-preview",
            media: id,
            variant,
          });
    for (const id of fixture.images)
      for (const variant of ["original", "thumb", "medium", "large"]) {
        const response = await context.request.get(imageUrl(id, variant));
        check(
          response.status() === 200,
          name + " actual own original image variant " + variant,
        );
        pixels.set(id + " " + variant, await response.body());
      }
    for (const width of [1440, 820, 390]) {
      await page.setViewportSize({ width, height: 1050 });
      await page.goto(editorUrl);
      check(!await page.locator('[data-page-versions]').evaluate(node=>node.open),name+' album history starts collapsed');
      await page.locator('[data-page-versions] > summary').click();
      const current = Number(
          await page
            .locator("[data-album-version]")
            .getAttribute("data-album-version"),
        ),
        restore = page.locator(
          '[data-saved-version="' + item.published + '"] form',
        );
      check(
        (await restore.locator("[name=operation]").inputValue()) ===
          "album-restore" &&
          Number(await restore.locator("[name=version]").inputValue()) ===
            current,
        name + " original album restore retains current version " + width,
      );
      const response = page.waitForResponse(
        (response) =>
          response.request().method() === "POST" &&
          new URL(response.url()).origin === origin,
      );
      await restore
        .getByRole("button", { name: "Als Entwurf laden", exact: true })
        .click();
      check(
        (await response).status() === (standalone ? 200 : 303),
        name + " actual protected album restoration succeeds " + width,
      );
      await page.waitForFunction(
        (expected) =>
          Number(
            document.querySelector("[data-album-version]")?.dataset
              .albumVersion,
          ) === expected,
        current + 1,
      );
      check(
        (await page.locator("input[name=title]").inputValue()) ===
          item.old_title,
        name +
          " original album title restored into new private version " +
          width,
      );
      const imageIds = await page
        .locator(".album-images .album-image [name=image_id]")
        .evaluateAll((inputs) => [
          ...new Set(inputs.map((input) => input.value)),
        ]);
      check(
        JSON.stringify(imageIds) === JSON.stringify(fixture.images),
        name + " original image order restored " + width,
      );
      const captions = await page
          .locator(".album-images [name=caption]")
          .evaluateAll((inputs) => inputs.map((input) => input.value)),
        tags = await page
          .locator(".album-images [name=tags]")
          .evaluateAll((inputs) => inputs.map((input) => input.value));
      check(
        JSON.stringify(captions) ===
          JSON.stringify([
            "Own original first picture",
            "Own original second picture",
          ]) && JSON.stringify(tags) === JSON.stringify(["Original", ""]),
        name + " original captions and tags restored " + width,
      );
      check(
        (await page.locator('[name="body_html"]').inputValue()) ===
          "<p>Own old <strong>body gallery</strong> &lt;marker&gt;</p>",
        name + " original formatted body and escaped literal restored " + width,
      );
      check(
        new URL(page.url()).searchParams.get(
          standalone ? "item" : "native_item",
        ) === item.id,
        name + " restoration retains original album editor identity " + width,
      );
      check(
        await page.evaluate(
          () => document.documentElement.scrollWidth <= innerWidth + 1,
        ),
        name + " actual restored album fits shared Bootstrap " + width,
      );
      check(
        (await (await context.request.get(publicUrl)).body()).equals(
          publicBytes,
        ),
        name +
          " deliberate private restoration preserves exact public original " +
          width,
      );
      await page.locator("[data-page-versions]").scrollIntoViewIfNeeded();
      await page.screenshot({
        path: output + "/dvm-album-versions-" + name + "-" + width + ".png",
        fullPage: false,
      });
    }
    const fallback = await browser.newContext({
        javaScriptEnabled: false,
        storageState: await context.storageState(),
      }),
      fallbackPage = await fallback.newPage(),
      fallbackPosts = [];
    await fallback.route("**/*", (route) =>
      new URL(route.request().url()).origin === origin
        ? route.continue()
        : (external.push(route.request().url()), route.abort()),
    );
    fallbackPage.on("pageerror", (error) => errors.push(error.message));
    fallbackPage.on("request", (request) => {
      if (request.method() === "POST") fallbackPosts.push(request.url());
    });
    await fallbackPage.goto(editorUrl);
    await fallbackPage.locator('[data-page-versions] > summary').click();
    const beforeFallback = Number(
      await fallbackPage
        .locator("[data-album-version]")
        .getAttribute("data-album-version"),
    );
    const fallbackResponse = fallbackPage.waitForResponse(
      (response) =>
        response.request().method() === "POST" &&
        new URL(response.url()).origin === origin,
    );
    await Promise.all([
      fallbackPage.waitForNavigation({ waitUntil: "domcontentloaded" }),
      fallbackPage
        .locator('[data-saved-version="' + item.published + '"] form')
        .getByRole("button", { name: "Als Entwurf laden", exact: true })
        .click(),
    ]);
    check(
      (await fallbackResponse).status() === 303,
      name + " JavaScript-free original album restoration uses protected HTTP",
    );
    check(
      Number(
        await fallbackPage
          .locator("[data-album-version]")
          .getAttribute("data-album-version"),
      ) ===
        beforeFallback + 1 &&
        (await fallbackPage.locator("input[name=title]").inputValue()) ===
          item.old_title,
      name + " JavaScript-free restore creates one private original revision",
    );
    check(
      JSON.stringify(
        await fallbackPage
          .locator(".album-images .album-image [name=image_id]")
          .evaluateAll((inputs) => [
            ...new Set(inputs.map((input) => input.value)),
          ]),
      ) === JSON.stringify(fixture.images),
      name + " JavaScript-free restore retains actual original image order",
    );
    check(
      (await (await fallback.request.get(publicUrl)).body()).equals(
        publicBytes,
      ),
      name + " JavaScript-free restore preserves exact public original",
    );
    check(
      fallbackPosts.length === 1,
      name + " only one deliberate JavaScript-free original request writes",
    );
    await fallback.close();
    for (const [key, original] of pixels) {
      const [id, variant] = key.split(" ");
      check(
        (
          await (await context.request.get(imageUrl(id, variant))).body()
        ).equals(original),
        name + " all restored original image bytes retained " + variant,
      );
    }
    check(
      posts.length === 3,
      name + " only three deliberate own private restorations write",
    );
    check(
      errors.length === 0,
      name + " no original album browser errors " + JSON.stringify(errors),
    );
    check(external.length === 0, name + " no external or provider actions");
    await context.close();
  }
  console.log(
    JSON.stringify({
      suite: "native-album-versions-browser",
      checks,
      actual_private_restores: 8,
      external_actions: 0,
    }),
  );
} finally {
  await browser.close();
}
