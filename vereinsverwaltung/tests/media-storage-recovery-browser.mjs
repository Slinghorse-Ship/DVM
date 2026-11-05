import assert from "node:assert/strict";
import { readFile, mkdir } from "node:fs/promises";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const fixture = JSON.parse(
  await readFile(process.env.DVM_MEDIA_RECOVERY_FIXTURE, "utf8"),
);
const browser = await chromium.launch({
  executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || undefined,
});
let checks = 0,
  accepted = 0,
  rejected = 0;
const errors = [],
  outside = [];
const check = (ok, label) => {
  assert(ok, label);
  checks++;
  console.log("PASS " + label);
};
const rootUrl =
  fixture.origin +
  "/?view=media&section=storage&storage_check=1&storage_after=" +
  (fixture.storageAfter || "");
const recoverUrl = (id) =>
  fixture.origin + "/?view=media&section=storage&recover_source=" + id;
const cookie = {
  ...fixture.cookie,
  domain: "127.0.0.1",
  path: "/",
  httpOnly: true,
  sameSite: "Strict",
};
try {
  const context = await browser.newContext({
    viewport: { width: 390, height: 1000 },
    hasTouch: true,
  });
  await context.addCookies([cookie]);
  context.on("request", (r) => {
    if (!r.url().startsWith(fixture.origin + "/")) outside.push(r.url());
  });
  const page = await context.newPage();
  page.on("pageerror", (error) => errors.push(String(error)));
  await page.goto(rootUrl);
  const card = page.locator(
    '[data-storage-group="' + fixture.sources.quota + '"]',
  );
  check(
    (await card
      .locator("a")
      .filter({ hasText: "Foto wiederherstellen" })
      .count()) === 1,
    "actual bounded storage observation offers one explicit recovery link on its complete own photo",
  );
  await card
    .getByRole("link", { name: "Foto wiederherstellen", exact: true })
    .click();
  await page.waitForURL("**recover_source=" + fixture.sources.quota + "*");
  const panel = page.locator(".media-storage-recovery"),
    form = panel.locator("form"),
    error = panel.locator("[data-media-removal-error]");
  check(
    (await form.count()) === 1 &&
      (await form.locator("input[name=source_fingerprint]").inputValue())
        .length === 64,
    "actual source review captures one exact own byte fingerprint without creating a photo",
  );
  for (const width of [320, 390, 820])
    for (const zoom of [1, 2]) {
      await page.setViewportSize({ width, height: 1000 });
      await page.evaluate(
        (z) => (document.documentElement.style.zoom = String(z)),
        zoom,
      );
      const proof = await panel.evaluate((root) => ({
        fit: root.scrollWidth <= root.clientWidth + 1,
        targets: [
          ...root.querySelectorAll("input:not([type=hidden]),button,summary"),
        ]
          .filter((n) => n.getBoundingClientRect().height > 0)
          .map((n) => ({
            h: n.getBoundingClientRect().height,
            font: parseFloat(getComputedStyle(n).fontSize),
          })),
      }));
      if (!proof.fit || proof.targets.some((t) => t.h < 43.5 || t.font < 15.5))
        console.log(JSON.stringify({ width, zoom, proof }));
      check(
        proof.fit,
        "actual recovery form fits " + width + "px/" + zoom + "x",
      );
      check(
        proof.targets.every((t) => t.h >= 43.5 && t.font >= 15.5),
        "actual recovery controls remain readable and touchable at " +
          width +
          "px/" +
          zoom +
          "x",
      );
    }
  await page.evaluate(() => (document.documentElement.style.zoom = "1"));
  await page.setViewportSize({ width: 390, height: 1000 });
  await form
    .locator("[name=recovery_name]")
    .fill("Own actual recovered browser.png");
  const fingerprint = await form
      .locator("[name=source_fingerprint]")
      .inputValue(),
    csrf = await form.locator("[name=csrf]").inputValue();
  await form
    .locator("[name=source_fingerprint]")
    .evaluate((node) => (node.value = "0".repeat(64)));
  let response = page.waitForResponse((r) => r.request().method() === "POST");
  await form
    .getByRole("button", { name: /^(?:Foto wiederherstellen|Fortsetzen)$/ })
    .click();
  check(
    (await response).status() === 409,
    "actual stale source fingerprint rejects the copy through its native POST",
  );
  rejected++;
  await error.waitFor({ state: "visible" });
  check(
    (await form.locator("[name=source_fingerprint]").inputValue()) ===
      "0".repeat(64) &&
      (await form.locator("[name=recovery_name]").inputValue()) ===
        "Own actual recovered browser.png" &&
      (await error.evaluate((node) => node === document.activeElement)),
    "actual source conflict retains entered name and old proof with local correction focus",
  );
  await form
    .locator("[name=source_fingerprint]")
    .evaluate((node, value) => (node.value = value), fingerprint);
  await form
    .locator("[name=csrf]")
    .evaluate((node) => (node.value = "wrong-own-csrf"));
  response = page.waitForResponse((r) => r.request().method() === "POST");
  await form
    .getByRole("button", { name: /^(?:Foto wiederherstellen|Fortsetzen)$/ })
    .click();
  check(
    (await response).status() === 422,
    "actual invalid CSRF rejects source recovery before any write",
  );
  rejected++;
  await form
    .locator("[name=csrf]")
    .evaluate((node, value) => (node.value = value), csrf);
  let lose = true;
  await context.route("**/*", async (route) => {
    const request = route.request();
    if (lose && request.method() === "POST") {
      lose = false;
      const saved = await route.fetch();
      assert.equal(saved.status(), 200);
      accepted++;
      await route.abort("failed");
      return;
    }
    await route.continue();
  });
  await form
    .getByRole("button", { name: /^(?:Foto wiederherstellen|Fortsetzen)$/ })
    .click();
  await error
    .filter({ hasText: /fetch|Network|Netz|bestätigt|fehlgeschlagen/i })
    .waitFor();
  check(
    (await form.locator("[name=source_fingerprint]").inputValue()) ===
      fingerprint &&
      (await form.locator("[name=recovery_name]").inputValue()) ===
        "Own actual recovered browser.png",
    "actual lost successful response retains the exact input rather than silently starting a new copy",
  );
  response = page.waitForResponse((r) => r.request().method() === "POST");
  await form
    .getByRole("button", { name: /^(?:Foto wiederherstellen|Fortsetzen)$/ })
    .click();
  check(
    (await response).status() === 200,
    "explicit retry of actual committed response loss returns the saved photo once",
  );
  await page.waitForURL("**section=library*");
  const saved = new URL(page.url()).searchParams.get("item");
  check(
    saved && saved !== fixture.sources.quota && saved !== fixture.target,
    "actual browser recovery opens only its own distinct saved photo",
  );
  await page.goto(recoverUrl(fixture.sources.quota));
  const openPhoto = page
    .locator(".media-storage-recovery")
    .getByRole("link", { name: "Foto öffnen", exact: true });
  const colors = await openPhoto.evaluate((node) => ({
    color: getComputedStyle(node).color,
    background: getComputedStyle(node).backgroundColor,
    height: node.getBoundingClientRect().height,
  }));
  check(
    colors.color === "rgb(255, 255, 255)" &&
      colors.background === "rgb(36, 86, 64)" &&
      colors.height >= 43.5,
    "actual saved photo action has visible contrasting text and a touch target",
  );
  check(
    (await page.locator(".media-storage-recovery form").count()) === 0 &&
      (await page
        .locator(".media-storage-recovery")
        .getByRole("link", { name: "Foto öffnen", exact: true })
        .count()) === 1,
    "read-only status check finds the already saved original and offers no duplicate recovery form",
  );
  await mkdir(".local/browser-tests/media-storage-recovery", {
    recursive: true,
  });
  await page.locator(".media-storage-recovery").screenshot({
    path: ".local/browser-tests/media-storage-recovery/mobile.png",
  });
  await context.close();
  const plain = await browser.newContext({
    viewport: { width: 390, height: 1000 },
    javaScriptEnabled: false,
  });
  await plain.addCookies([cookie]);
  const p = await plain.newPage();
  await p.goto(recoverUrl(fixture.sources.changed));
  const plainForm = p.locator(".media-storage-recovery form");
  check(
    (await plainForm.count()) === 1,
    "actual native source review remains available without JavaScript",
  );
  await plainForm
    .locator("[name=recovery_name]")
    .fill("Own exact plain recovery.png");
  const plainFingerprint = await plainForm
    .locator("[name=source_fingerprint]")
    .inputValue();
  await plainForm
    .locator("[name=source_fingerprint]")
    .evaluate((node) => (node.value = "0".repeat(64)));
  const invalidPlain = await Promise.all([
    p.waitForNavigation(),
    plainForm
      .getByRole("button", { name: /^(?:Foto wiederherstellen|Fortsetzen)$/ })
      .click(),
  ]);
  rejected++;
  const correction = p.locator(".media-storage-recovery form");
  check(
    invalidPlain[0].status() === 409 &&
      (await correction.locator("[name=source_fingerprint]").inputValue()) ===
        "0".repeat(64) &&
      (await correction.locator("[name=recovery_name]").inputValue()) ===
        "Own exact plain recovery.png",
    "actual native error without JavaScript retains original entered values and never silently rebases a stale source proof",
  );
  await correction
    .locator("[name=source_fingerprint]")
    .evaluate((node, value) => (node.value = value), plainFingerprint);
  await Promise.all([
    p.waitForNavigation(),
    correction
      .getByRole("button", { name: /^(?:Foto wiederherstellen|Fortsetzen)$/ })
      .click(),
  ]);
  accepted++;
  check(
    new URL(p.url()).searchParams.get("section") === "library" &&
      new URL(p.url()).searchParams.get("item") !== saved,
    "actual non-JavaScript form saves its second distinct own photo through the same boundary",
  );
  check(
    errors.length === 0 && outside.length === 0,
    "actual recovery has no script error or external request",
  );
  console.log(
    JSON.stringify({
      suite: "media-storage-recovery-browser",
      checks,
      accepted_writes: accepted,
      rejected_posts: rejected,
      live_transport: false,
    }),
  );
  await plain.close();
} finally {
  await browser.close();
}
