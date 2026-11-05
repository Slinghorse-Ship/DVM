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
const originals = JSON.parse(
  await readFile(base + "page-copies-browser.json", "utf8"),
);
const origin = "http://127.0.0.1:8111",
  output = root + ".local/browser-tests";
await mkdir(output, { recursive: true, mode: 0o700 });
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
  const suffix = Date.now().toString(36);
  for (const width of [1440, 820, 390]) {
    await page.setViewportSize({ width, height: 1050 });
    for (const original of originals) {
      const url =
        origin +
        "/vereinsverwaltung/?view=website&tab=" +
        original.tab +
        "&native_item=" +
        original.id;
      const publicOriginal = await context.request.get(
        origin + "/site/" + original.slug + "/",
      );
      const originalBody = await publicOriginal.text(),
        originalStatus = publicOriginal.status();
      const imageUrl =
        origin +
        "/vereinsverwaltung/?native_endpoint=media-preview&media=" +
        original.photo +
        "&variant=original";
      const imageOriginal = await context.request.get(imageUrl);
      const originalImage = await imageOriginal.body();
      check(
        imageOriginal.status() === 200,
        "actual original image accessible for " + original.view + " " + width,
      );
      if (width === 1440 && original.view === "website") {
        await page.goto(url);
        await page.locator(".page-copy-panel summary").click();
        const conflictForm = page.locator(".page-copy-panel form");
        await conflictForm
          .getByLabel("Titel der Kopie", { exact: true })
          .fill("Own conflicting copy");
        await conflictForm
          .getByLabel("Neue Adresse der Kopie", { exact: true })
          .fill(original.slug);
        const conflictResponse = page.waitForResponse(
          (r) =>
            r.request().method() === "POST" &&
            r.request().postData()?.includes("operation=page-copy"),
        );
        await conflictForm
          .getByRole("button", {
            name: "Unveröffentlichten Entwurf anlegen",
            exact: true,
          })
          .click();
        check(
          (await conflictResponse).status() === 422,
          "actual copy form refuses occupied original address",
        );
        await page.waitForFunction(() =>
          document.body?.innerText.includes(
            "Die neue Adresse ist bereits vergeben",
          ),
        );
        check(
          new URL(page.url()).searchParams.get("native_item") === original.id,
          "failed copy keeps original editor available",
        );
        check(
          (await page
            .locator(".page-copy-panel [name=copy_title]")
            .inputValue()) === "Own conflicting copy" &&
            (await page
              .locator(".page-copy-panel [name=copy_slug]")
              .inputValue()) === original.slug,
          "actual conflict keeps entered copy title and address available for correction",
        );
        check(
          await page.locator(".page-copy-panel").evaluate((el) => el.open),
          "actual copy conflict reopens correction form",
        );
      }
      for (const basis of ["draft", "published"]) {
        await page.goto(url);
        const panel = page.locator(".page-copy-panel");
        await panel.locator("summary").focus();
        await panel.locator("summary").press("Enter");
        check(
          await panel.evaluate((el) => el.open),
          "keyboard opens copy choices for " +
            original.view +
            " " +
            basis +
            " " +
            width,
        );
        const form = panel.locator("form");
        check(
          (
            await form
              .getByLabel("Kopiergrundlage", { exact: true })
              .getAttribute("class")
          ).includes("form-control"),
          "shared Bootstrap choices for " + original.view + " " + width,
        );
        await form
          .getByLabel("Kopiergrundlage", { exact: true })
          .selectOption(basis);
        const slug = original.slug + "-" + width + "-" + basis + "-" + suffix;
        await form
          .getByLabel("Titel der Kopie", { exact: true })
          .fill(
            "Own private " + original.view + " copy " + width + " " + basis,
          );
        await form
          .getByLabel("Neue Adresse der Kopie", { exact: true })
          .fill(slug);
        check(
          await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth + 2,
          ),
          "expanded copy form fits " + original.view + " " + width,
        );
        if (original.view === "website" && basis === "draft")
          await panel.screenshot({
            path: output + "/dvm-copy-form-" + width + ".png",
          });
        const request = page.waitForResponse(
          (r) =>
            r.request().method() === "POST" &&
            r.request().postData()?.includes("operation=page-copy"),
        );
        await form
          .getByRole("button", {
            name: "Unveröffentlichten Entwurf anlegen",
            exact: true,
          })
          .click();
        const response = await request;
        check(
          response.status() === 303,
          "actual copy POST succeeds for " +
            original.view +
            " " +
            basis +
            " " +
            width,
        );
        await page.waitForURL(
          (u) =>
            u.searchParams.get("native_item") &&
            u.searchParams.get("native_item") !== original.id,
        );
        check(
          new URL(page.url()).searchParams.get("tab") === original.tab,
          "copy opens correct original editor for " +
            original.view +
            " " +
            width,
        );
        const copiedValue = await page.locator("[name=body_html]").inputValue();
        check(
          copiedValue === original[basis],
          "actual copied original basis reopens for " +
            original.view +
            " " +
            basis +
            " " +
            width,
        );
        const copyPanel = page.locator(".page-copy-panel");
        check(
          (await copyPanel
            .locator("select[name=basis] option[value=published]")
            .count()) === 0,
          "new copy has no published revision for " +
            original.view +
            " " +
            width,
        );
        check(
          (
            await context.request.get(origin + "/site/" + slug + "/")
          ).status() === 404,
          "copied content stays private for " + original.view + " " + width,
        );
      }
      await page.goto(url);
      check(
        Number(
          await page.locator(".page-copy-panel [name=version]").inputValue(),
        ) === original.version,
        "source version remains exact for " + original.view + " " + width,
      );
      check(
        (await page.locator("[name=body_html]").inputValue()) ===
          original.draft,
        "source saved draft remains exact for " + original.view + " " + width,
      );
      const publicAfter = await context.request.get(
        origin + "/site/" + original.slug + "/",
      );
      check(
        publicAfter.status() === originalStatus &&
          (await publicAfter.text()) === originalBody,
        "original publication remains exact for " + original.view + " " + width,
      );
      check(
        Buffer.compare(
          await (await context.request.get(imageUrl)).body(),
          originalImage,
        ) === 0,
        "original image bytes remain exact for " + original.view + " " + width,
      );
    }
  }
  check(errors.length === 0, "copy lifecycle has no browser script errors");
  check(
    external.length === 0,
    "copy lifecycle makes no external provider request",
  );
  const memberContext = await browser.newContext();
  await memberContext.route("**/*", (r) =>
    new URL(r.request().url()).origin === origin ? r.continue() : r.abort(),
  );
  const member = await memberContext.newPage();
  await member.goto(origin + "/login/");
  await member.locator("[name=email]").fill(credentials.member.email);
  await member.locator("[name=password]").fill(credentials.member.password);
  await Promise.all([
    member.waitForURL("**/vereinsverwaltung/**"),
    member
      .getByRole("button", {
        name: "Anmelden und Vereinsmanager öffnen",
        exact: true,
      })
      .click(),
  ]);
  check(
    (
      await member.goto(
        origin +
          "/vereinsverwaltung/?view=website&tab=pages&native_item=" +
          originals[0].id,
      )
    ).status() === 403,
    "ordinary member cannot open editorial copy source",
  );
  check(
    (await member.locator(".page-copy-panel").count()) === 0,
    "ordinary member cannot obtain editorial copy form",
  );
  await memberContext.close();
  console.log(
    JSON.stringify({
      checks: n,
      widths: [1440, 820, 390],
      copies: 24,
      actual_http: true,
      external_actions: 0,
    }),
  );
} finally {
  await browser.close();
}
