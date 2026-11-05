import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { createHash, randomBytes } from "node:crypto";
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
let checks = 0,
  writes = 0;
const external = [];
const check = (condition, label) => {
  assert(condition, label);
  checks++;
  console.log("PASS " + label);
};
try {
  for (const standalone of [true, false]) {
    const name = standalone ? "standalone" : "integrated";
    const origin = "http://127.0.0.1:" + (standalone ? 8107 : 8111);
    const f = JSON.parse(
      await readFile(
        root + ".local/browser-tests/album-upload-" + name + "-status.json",
        "utf8",
      ),
    );
    const context = await browser.newContext({
      javaScriptEnabled: false,
      storageState: standalone ? undefined : session,
    });
    await context.route("**/*", (route) => {
      if (new URL(route.request().url()).origin !== origin) {
        external.push(route.request().url());
        return route.abort();
      }
      assert.equal(
        route.request().method(),
        "GET",
        "Page rendering must never submit a form",
      );
      return route.continue();
    });
    const page = await context.newPage();
    const url =
      origin +
      (standalone ? "/?" : "/vereinsverwaltung/?") +
      new URLSearchParams(
        standalone
          ? { view: "media", item: f.album }
          : { view: "website", tab: "gallery", native_item: f.album },
      );
    await page.goto(url, { waitUntil: "domcontentloaded" });
    const fields = await page
      .locator("[data-album-upload]")
      .evaluate((input) =>
        Object.fromEntries(
          [...new FormData(input.form)].filter(
            ([, value]) => typeof value === "string",
          ),
        ),
      );
    check(
      fields.id === f.album &&
        Number(fields.version) === f.version &&
        fields.operation === "album-upload",
      name + " actual owned upload form and exact version",
    );
    const bytes = await readFile(f.sources.a.path);
    check(
      createHash("sha256").update(bytes).digest("hex") === f.sources.a.sha256,
      name + " selected source is byte exact",
    );
    const uploadId = randomBytes(16).toString("hex");
    const response = await context.request.post(url, {
      multipart: {
        ...fields,
        upload_id: uploadId,
        image: {
          name: f.prefix + "-status-a.png",
          mimeType: "image/png",
          buffer: bytes,
        },
      },
      headers: { Accept: "application/json" },
      maxRedirects: 0,
    });
    writes++;
    check(
      response.status() === 200,
      name + " genuine identified multipart upload succeeds",
    );
    const result = await response.json();
    check(
      result.ok === true &&
        result.image.id === uploadId &&
        result.image.name === f.prefix + "-status-a.png" &&
        !result.html,
      name + " exact private photo receipt without replacing forms",
    );
    assert.deepEqual(result.image.album, {
      id: f.album,
      version: f.version + 1,
    });
    checks++;
    const statusUrl = (query) =>
      origin +
      (standalone
        ? "/album-photo-status?"
        : "/vereinsverwaltung/?native_endpoint=album-photo-status&") +
      new URLSearchParams({
        album_id: f.album,
        version: String(f.version),
        upload_id: uploadId,
        ...query,
      });
    const read = await context.request.get(statusUrl());
    check(
      read.status() === 200 && /no-store/.test(read.headers()["cache-control"]),
      name + " actual private status is never cached",
    );
    const found = await read.json();
    check(
      found.found === true && found.image.id === uploadId,
      name + " actual endpoint finds exactly the accepted photo",
    );
    assert.deepEqual(found.album, {
      id: f.album,
      version: f.version + 1,
      current: true,
    });
    checks++;
    check(
      new URL(found.image.url, origin).origin === origin &&
        new URL(found.image.edit_url, origin).origin === origin,
      name + " private links stay in the actual application",
    );
    check(
      standalone
        ? found.image.url.startsWith("/media-preview/")
        : found.image.url.includes("native_endpoint=media-preview"),
      name + " preview route is correctly translated",
    );
    const head = await context.request.head(statusUrl());
    check(
      head.status() === 200 &&
        (await head.body()).length === 0 &&
        /no-store/.test(head.headers()["cache-control"]),
      name + " HEAD has no body or writes",
    );
    const rejected = await context.request.post(statusUrl(), {
      data: "Own prohibited status mutation",
    });
    writes++;
    check(
      rejected.status() === 405 &&
        rejected.headers().allow === "GET, HEAD" &&
        (await rejected.body()).length === 0,
      name + " actual endpoint rejects mutations before processing",
    );
    for (const query of [
      { upload_id: randomBytes(16).toString("hex") },
      { version: String(f.version + 1) },
      { album_id: randomBytes(16).toString("hex") },
    ]) {
      const missing = await context.request.get(statusUrl(query));
      check(
        missing.status() === 200 &&
          JSON.stringify(await missing.json()) === '{"found":false}',
        name + " another exact identity or version has no receipt",
      );
    }
    for (const query of [
      { version: "0" },
      { version: "2.0" },
      { version: "4294967295" },
      { album_id: "../" + f.album },
    ]) {
      const invalid = await context.request.get(statusUrl(query), {
        headers: { Accept: "application/json" },
      });
      check(
        invalid.status() === (standalone ? 422 : 403),
        name + " malformed actual request is rejected",
      );
    }
    const edit = await context.request.post(url, {
      form: {
        ...fields,
        operation: "album-save",
        version: String(f.version + 1),
        title: f.prefix + " independent edit Ä",
        slug: f.slug.replace(/^galerie\//, ""),
        body: "Own independent text Ä",
      },
      maxRedirects: 0,
    });
    writes++;
    check(
      edit.status() === 303,
      name + " genuine later independent album edit succeeds",
    );
    const later = await context.request.get(statusUrl());
    const laterReceipt = await later.json();
    check(
      later.status() === 200 && laterReceipt.image.id === uploadId,
      name + " immutable accepted photo remains readable",
    );
    assert.deepEqual(laterReceipt.album, {
      id: f.album,
      version: f.version + 1,
      current: false,
    });
    checks++;
    console.log(
      "PASS " +
        name +
        " later edit never promotes an old upload to a newer revision",
    );
    await context.close();
  }
  check(external.length === 0, "no external request or provider send");
  check(
    writes === 6,
    "only four deliberate writes and two rejected status mutations",
  );
  console.log(
    JSON.stringify({
      checks,
      writes,
      external_service_calls: false,
      exact_album_receipts: true,
    }),
  );
} finally {
  await browser.close();
}
