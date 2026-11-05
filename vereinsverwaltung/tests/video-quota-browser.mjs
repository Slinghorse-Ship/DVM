import assert from "node:assert/strict";
import { readFile, mkdir } from "node:fs/promises";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const fixture = JSON.parse(
  await readFile(process.env.DVM_VIDEO_QUOTA_FIXTURE, "utf8"),
);
const browser = await chromium.launch({
  executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || undefined,
});
let checks = 0,
  posts = 0;
const errors = [],
  outside = [];
const check = (ok, label) => {
  assert(ok, label);
  checks++;
  console.log("PASS " + label);
};
try {
  const context = await browser.newContext({
    viewport: { width: 390, height: 1000 },
    hasTouch: true,
  });
  await context.addCookies([
    {
      name: fixture.cookie_name,
      value: fixture.cookie,
      path: "/",
      domain: "127.0.0.1",
      httpOnly: true,
      sameSite: "Strict",
    },
  ]);
  context.on("request", (r) => {
    if (r.method() === "POST") posts++;
    if (!r.url().startsWith(fixture.origin + "/")) outside.push(r.url());
  });
  const page = await context.newPage();
  page.on("pageerror", (e) => errors.push(String(e)));
  const url = fixture.origin + "/?view=social&item=" + fixture.item;
  await page.goto(url);
  const panel = page.locator("#social-videos"),
    quota = panel.locator(".social-video-quota"),
    form = quota.locator("[data-video-policy-form]"),
    upload = panel.locator(".social-video-upload form");
  const openQuota = async () => {
    if ((await quota.getAttribute("open")) === null)
      await quota.locator(":scope > summary").click();
  };
  check(
    (await quota.getAttribute("open")) === null,
    "optional original source budget starts compact and closed",
  );
  await openQuota();
  check(
    (await form.locator("input[name=quota_version]").inputValue()) === "3" &&
      (await form.locator("input[name=limit_mib]").inputValue()) === "1",
    "actual form displays the current original budget and version",
  );
  check(
    (await quota.innerText()).includes("2 Quellen") &&
      (await quota.innerText()).includes("0,00 MiB frei"),
    "current over-budget originals remain counted and visible",
  );
  for (const width of [320, 390, 820])
    for (const zoom of [1, 2]) {
      await page.setViewportSize({ width, height: 1000 });
      await page.evaluate(
        (z) => (document.documentElement.style.zoom = String(z)),
        zoom,
      );
      const proof = await form.evaluate((root) => ({
        fit: root.scrollWidth <= root.clientWidth + 1,
        dimensions: [
          root.clientWidth,
          root.scrollWidth,
          ...[
            ...root.querySelectorAll(
              "input:not([type=hidden]),button,label,small",
            ),
          ].map((n) => [
            n.tagName,
            n.clientWidth,
            n.scrollWidth,
            getComputedStyle(n).minWidth,
            getComputedStyle(n).boxSizing,
          ]),
        ],
        targets: [
          ...root.querySelectorAll("input:not([type=hidden]),button:not([hidden])"),
        ].map((n) => ({
          h: n.getBoundingClientRect().height,
          font: parseFloat(getComputedStyle(n).fontSize),
        })),
      }));
      check(proof.fit, "actual budget form fits " + width + "px/" + zoom + "x");
      check(
        proof.targets.every((t) => t.h >= 43.5 && t.font >= 15.5),
        "actual budget controls remain readable and touchable at " +
          width +
          "px/" +
          zoom +
          "x",
      );
    }
  await page.evaluate(() => (document.documentElement.style.zoom = "1"));
  await page.setViewportSize({ width: 390, height: 1000 });
  const original = await form.evaluate((n) =>
    new URLSearchParams(new FormData(n)).toString(),
  );
  const bad = new URLSearchParams(original);
  bad.set("csrf", "invalid-own-csrf");
  const rejected = await context.request.post(url, {
    data: bad.toString(),
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    maxRedirects: 0,
  });
  posts++;
  check(
    rejected.status() >= 400 && rejected.status() < 500,
    "actual invalid CSRF cannot change the quota",
  );
  await panel.locator(".social-video-upload > summary").click();
  await upload.locator("input[type=file]").setInputFiles(fixture.file);
  let response = page.waitForResponse((r) => r.request().method() === "POST");
  await upload.locator("[data-video-upload-save]").click();
  check(
    (await response).status() === 422,
    "actual over-budget multipart upload is rejected",
  );
  await panel.locator('[data-video-upload-status][role=alert]').waitFor();
  const retainedFile = await upload.locator('input[type=file]').evaluate(n=>({name:n.files[0].name,bytes:n.files[0].size,key:n.form.querySelector('[name=video_request_key]').value}));
  const draft = page.locator('form:has(input[name=operation][value=communication-save]) [name=title]');
  await draft.fill('Own unsaved retained quota caption');
  await form.locator("input[name=limit_mib]").fill("3");
  response = page.waitForResponse(r=>r.request().method()==='POST');
  await form.locator('[data-policy-save]').click();
  check((await response).status()===200,'actual explicit increased quota is saved');
  await quota.locator('[data-policy-status]').filter({hasText:'Regel gespeichert.'}).waitFor();
  check(await draft.inputValue()==='Own unsaved retained quota caption' && JSON.stringify(await upload.locator('input[type=file]').evaluate(n=>({name:n.files[0].name,bytes:n.files[0].size,key:n.form.querySelector('[name=video_request_key]').value})))===JSON.stringify(retainedFile),'rule update preserves the actual refused File original upload key and unsaved source title');
  check(
    (await form.locator("input[name=quota_version]").inputValue()) === "4",
    "saved quota never silently changes its version",
  );
  response = page.waitForResponse((r) => r.request().method() === "POST");
  await upload.locator('[data-video-upload-retry]').click();
  check(
    (await response).status() === 200,
    "actual budget-fitting original multipart upload succeeds once",
  );
  await panel.locator('[data-video-upload-status]').filter({hasText:'Video gespeichert.'}).waitFor();
  check(await draft.inputValue()==='Own unsaved retained quota caption','accepted original retry keeps the same unsaved source draft');
  check(
    (await quota.innerText()).includes("3 Quellen"),
    "accepted source appears with the actual complete total",
  );
  await openQuota();
  await form.locator("input[name=limit_mib]").fill("1");
  response = page.waitForResponse((r) => r.request().method() === "POST");
  await form.getByRole("button", { name: "Speichergrenze speichern" }).click();
  check(
    (await response).status() === 200,
    "actual smaller limit remains an explicit versioned user choice",
  );
  await quota.locator('[data-policy-status]').filter({hasText:'Regel gespeichert.'}).waitFor();
  check(
    (await quota.innerText()).includes("3 Quellen") &&
      (await quota.innerText()).includes("0,00 MiB frei") &&
      (await form.locator("input[name=quota_version]").inputValue()) === "5",
    "lower limit preserves every over-budget original without deleting sources",
  );
  const staleInput = new URLSearchParams(original); staleInput.set("policy_request_key","d".repeat(32));
  const stale = await context.request.post(url, {
    data: staleInput.toString(),
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    maxRedirects: 0,
  });
  posts++;
  check(
    stale.status() === 409,
    "actual stale original budget is rejected instead of promoted",
  );
  check(
    (await form.locator("input[name=quota_version]").inputValue()) === "5",
    "rejected stale choice leaves the exact saved version",
  );
  await mkdir(".local/browser-tests/video-quota", { recursive: true });
  await quota.screenshot({
    path: ".local/browser-tests/video-quota/mobile.png",
  });
  const plain = await browser.newContext({
    javaScriptEnabled: false,
    viewport: { width: 320, height: 900 },
  });
  await plain.addCookies([
    {
      name: fixture.cookie_name,
      value: fixture.cookie,
      path: "/",
      domain: "127.0.0.1",
      httpOnly: true,
      sameSite: "Strict",
    },
  ]);
  const p = await plain.newPage();
  await p.goto(url);
  await p.locator(".social-video-quota > summary").click();
  check(
    (await p
      .locator(".social-video-quota input[name=quota_version]")
      .inputValue()) === "5",
    "actual budget remains readable without JavaScript",
  );
  await plain.close();
  check(
    posts === 6,
    "two actual accepted policy changes, one accepted and one rejected upload plus two rejected policy submissions occur exactly once",
  );
  check(
    errors.length === 0 && outside.length === 0,
    "actual quota and upload flow creates no script error or external request",
  );
  console.log(
    JSON.stringify({
      suite: "video-quota-browser",
      checks,
      posts,
      live_transport: false,
    }),
  );
  await context.close();
} finally {
  await browser.close();
}
