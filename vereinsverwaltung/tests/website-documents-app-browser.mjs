import {kDirectory,kOrigin,kStorage,kConfig,kSource} from './support/k-browser-client.mjs';
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import { createHash } from "node:crypto";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const root = fileURLToPath(new URL("../../", import.meta.url));
const browser = await chromium.launch({ executablePath: "/usr/bin/chromium" });
let checks = 0;
const errors = [],
  external = [];
const check = (ok, label) => {
  assert(ok, label);
  checks++;
  console.log("PASS " + label);
};
const sha = (bytes) => createHash("sha256").update(bytes).digest("hex");
try {
  for (const standalone of [true, false]) {
    const name = standalone ? "standalone" : "integrated";
    const origin = kOrigin(standalone);
    const f = JSON.parse(
      await readFile(
        kDirectory()+"/website-documents-" + name + ".json",
        "utf8",
      ),
    );
    const storageState=kStorage(standalone);
    const expected = await Promise.all(f.files.map((path) => readFile(path)));
    const anonymous = await browser.newContext();
    for (const [width, javaScriptEnabled] of [
      [390, true],
      [320, true],
      [820, true],
      [390, false],
    ]) {
      const key = width + "-" + (javaScriptEnabled ? "js" : "nojs");
      const fixture = f.pages[key];
      const context = await browser.newContext({
        storageState,
        viewport: { width, height: 880 },
        hasTouch: true,
        javaScriptEnabled,
      });
      await context.route("**/*", (route) =>
        new URL(route.request().url()).origin === origin
          ? route.continue()
          : (external.push(route.request().url()), route.abort()),
      );
      const p = await context.newPage();
      p.on("pageerror", (e) => errors.push(e.message));
      const url =
        origin +
        (standalone
          ? "/?view=website&item="
          : "/vereinsverwaltung/?view=website&tab=pages&native_item=") +
        fixture.id;
      await p.goto(url, { waitUntil: "domcontentloaded", timeout: 60000 });
      const form = () =>
        p.locator("form").filter({
          has: p.locator('input[name="operation"][value="page-save"]'),
        });
      const action = async (operation, version) => {
        const target = p.locator("form").filter({
          has: p.locator('input[name="operation"][value="' + operation + '"]'),
        });
        if (javaScriptEnabled) {
          const response = p.waitForResponse(
            (r) =>
              r.request().method() === "POST" && r.url().startsWith(origin),
          );
          await target
            .getByRole("button", {
              name:
                operation === "page-save"
                  ? "Entwurf speichern"
                  : operation === "page-publish"
                    ? "Veröffentlichen"
                    : "Offline nehmen",
              exact: true,
            })
            .click();
          const result = await response;
          check(
            result.status() >= 200 && result.status() < 400,
            name + " actual " + operation + " " + key,
          );
        } else {
          await Promise.all([
            p.waitForNavigation({
              waitUntil: "domcontentloaded",
              timeout: 60000,
            }),
            target
              .getByRole("button", {
                name:
                  operation === "page-save"
                    ? "Entwurf speichern"
                    : operation === "page-publish"
                      ? "Veröffentlichen"
                      : "Offline nehmen",
                exact: true,
              })
              .click(),
          ]);
          check(
            !p.url().includes("login"),
            name + " actual no-JS " + operation,
          );
        }
        await p.waitForFunction(
          (v) =>
            document
              .querySelector('form input[name="operation"][value="page-save"]')
              ?.form.querySelector('input[name="version"]').value === String(v),
          version,
        );
      };
      await form()
        .locator('input[name="title"]')
        .fill(f.prefix + " edited " + key);
      await form()
        .locator('input[name="excerpt"]')
        .fill("Own unsaved input " + key);
      const panel = form().locator(".website-documents");
      await panel.locator("summary").click();
      const upload = form().locator('input[name="website_pdfs[]"]');
      check(
        (await upload.getAttribute("accept")) === "application/pdf,.pdf" &&
          (await upload.getAttribute("multiple")) !== null,
        name + " ordinary multi-PDF selection stays direct " + key,
      );
      check(
        (await panel.locator("summary").boundingBox()).height >= 44 &&
          (await upload.boundingBox()).height >= 44,
        name + " PDF controls are touch-sized " + key,
      );
      {
        await upload.setInputFiles(f.bad);
        if (javaScriptEnabled) {
          const response = p.waitForResponse(
            (r) =>
              r.request().method() === "POST" && r.url().startsWith(origin),
          );
          await form()
            .getByRole("button", { name: "Entwurf speichern", exact: true })
            .click();
          check(
            !(await response).ok(),
            name + " malformed PDF reports an actual failed save " + key,
          );
        } else {
          const [response] = await Promise.all([
            p.waitForNavigation({
              waitUntil: "domcontentloaded",
              timeout: 60000,
            }),
            form()
              .getByRole("button", { name: "Entwurf speichern", exact: true })
              .click(),
          ]);
          check(
            response.status() === 422,
            name + " malformed PDF reports a real no-JS rejection",
          );
        }
        check(
          (await form().locator('input[name="title"]').inputValue()) ===
            f.prefix + " edited " + key &&
            (await form().locator('input[name="excerpt"]').inputValue()) ===
              "Own unsaved input " + key,
          name + " failed upload keeps all unsaved fields " + key,
        );
      }
      if (
        !(await form()
          .locator(".website-documents")
          .evaluate((d) => d.open))
      )
        await form().locator(".website-documents summary").click();
      await upload.setInputFiles(f.files);
      await action("page-save", 3);
      await form().locator(".website-documents summary").click();
      const links = form().locator("a[data-native-document]");
      check(
        (await links.count()) === 2 &&
          (await form().locator('input[name="title"]').inputValue()) ===
            f.prefix + " edited " + key &&
          (await form().locator('input[name="excerpt"]').inputValue()) ===
            "Own unsaved input " + key,
        name + " two uploads and unsaved fields saved together " + key,
      );
      const ids = await links.evaluateAll((a) =>
        a.map((el) => {
          const u = new URL(el.href);
          return u.searchParams.get("document") || u.pathname.split("/").at(-1);
        }),
      );
      for (const id of ids)
        check(
          (
            await anonymous.request.get(origin + "/website-document/" + id)
          ).status() === 404,
          name + " private new PDF is not exposed " + key,
        );
      const download = p.waitForEvent("download");
      await links.first().click();
      const actual = await download;
      check(
        sha(await readFile(await actual.path())) === sha(expected[0]),
        name + " actual private download preserves exact PDF bytes " + key,
      );
      await p.evaluate(() => (document.documentElement.style.zoom = "2"));
      check(
        await p.evaluate(
          () => document.documentElement.scrollWidth <= innerWidth + 1,
        ),
        name + " page with document names fits at 200 percent zoom " + key,
      );
      await p.evaluate(() => (document.documentElement.style.zoom = "1"));
      await action("page-publish", 4);
      for (let i = 0; i < ids.length; i++) {
        const url = origin + "/website-document/" + ids[i];
        const response = await anonymous.request.get(url);
        check(
          response.status() === 200 &&
            sha(await response.body()) === sha(expected[i]),
          name +
            " published original has exact anonymous bytes " +
            key +
            " " +
            i,
        );
        check(
          response.headers()["content-type"] === "application/pdf" &&
            response
              .headers()
              ["content-disposition"].startsWith("attachment;") &&
            response.headers()["cache-control"].includes("no-store") &&
            response.headers()["x-content-type-options"] === "nosniff",
          name + " controlled PDF download headers " + key + " " + i,
        );
        const head = await anonymous.request.head(url);
        check(
          head.status() === 200 &&
            (await head.body()).length === 0 &&
            Number(head.headers()["content-length"]) === expected[i].length,
          name +
            " actual HEAD has original length and no body " +
            key +
            " " +
            i,
        );
        check(
          (
            await anonymous.request.post(url, { data: "own synthetic" })
          ).status() === 405,
          name + " public PDF route is read-only " + key + " " + i,
        );
      }
      const html = await (
        await anonymous.request.get(origin + "/site/" + fixture.slug)
      ).text();
      check(
        html.includes("/website-document/" + ids[0]) &&
          html.includes("/website-document/" + ids[1]) &&
          html.includes("Own original heading"),
        name +
          " public page retains original structured text and both links " +
          key,
      );
      await form().locator(".website-documents summary").click();
      await form().locator('input[name="document_rows[0][remove]"]').check();
      await form()
        .locator('input[name="document_rows[1][label]"]')
        .fill("Own readable PDF " + key);
      await action("page-save", 5);
      check(
        (
          await anonymous.request.get(origin + "/website-document/" + ids[0])
        ).status() === 200,
        name + " private removal keeps current public original " + key,
      );
      await action("page-publish", 6);
      check(
        (
          await anonymous.request.get(origin + "/website-document/" + ids[0])
        ).status() === 404 &&
          (
            await anonymous.request.get(origin + "/website-document/" + ids[1])
          ).status() === 200,
        name +
          " explicit publication changes only the chosen PDF references " +
          key,
      );
      await action("page-unpublish", 7);
      check(
        (
          await anonymous.request.get(origin + "/website-document/" + ids[1])
        ).status() === 404,
        name + " withdrawal stops anonymous PDF downloads immediately " + key,
      );
      await context.close();
    }
    await anonymous.close();
  }
  check(
    external.length === 0 && errors.length === 0,
    "no external requests or browser errors",
  );
  console.log(
    JSON.stringify({
      checks,
      new_pdfs: 16,
      new_pages: 8,
      original_bytes: true,
      live_transport: false,
    }),
  );
} finally {
  await browser.close();
}
