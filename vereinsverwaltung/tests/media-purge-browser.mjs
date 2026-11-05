import assert from "node:assert/strict";
import { readFile, mkdir } from "node:fs/promises";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const fixture = JSON.parse(
  await readFile(process.env.DVM_MEDIA_PURGE_FIXTURE, "utf8"),
);
assert(/^http:\/\/127\.0\.0\.1:\d{4,5}$/.test(fixture.origin));
for (const key of ["js", "nojs", "lost", "resume_js", "resume_nojs"])
  assert(/^[a-f0-9]{32}$/.test(fixture[key]));
const browser = await chromium.launch({
  executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || undefined,
});
let checks = 0,
  posts = 0;
const external = [],
  errors = [];
const check = (ok, label) => {
  assert(ok, label);
  checks++;
  console.log("PASS " + label);
};
const address = (id = "") =>
  fixture.origin + "/?view=media&section=removed" + (id ? "&item=" + id : "");
const ready = async (page) => {
  for (let i = 0; i < 100; i++) {
    if (
      await page.evaluate(() =>
        [...document.querySelectorAll('link[rel="stylesheet"]')].every(
          (link) => link.sheet,
        ),
      )
    )
      return;
    await new Promise((resolve) => setTimeout(resolve, 100));
  }
  throw Error("Actual private styles required");
};
const contextFor = async (js) => {
  const context = await browser.newContext({
    javaScriptEnabled: js,
    hasTouch: true,
    viewport: { width: 390, height: 900 },
  });
  await context.route("**/*", (route) => {
    if (new URL(route.request().url()).origin !== fixture.origin) {
      external.push(route.request().url());
      return route.abort();
    }
    if (route.request().method() === "POST") posts++;
    return route.continue();
  });
  return context;
};
const checkCurrent = async (page, form, id) => {
  const response = page.waitForResponse(
    (r) => r.request().method() === "GET" && r.url().includes("item=" + id),
  );
  await form.locator("[data-media-removal-check]").click();
  await page.locator("#discard[open]").waitFor();
  await page.locator('#discard [data-choice="discard"]').click();
  check(
    (await response).status() === 200,
    "unknown or stale result is read without repeating deletion",
  );
};
const submit = async (page, form, js) => {
  const response = page.waitForResponse((r) => r.request().method() === "POST");
  if (js) await form.locator("button").click();
  else
    await Promise.all([
      page.waitForNavigation({ waitUntil: "domcontentloaded" }),
      form.locator("button").click(),
    ]);
  check(
    (await response).status() === (js ? 200 : 303),
    "actual confirmed " +
      (js ? "JS" : "native") +
      " irreversible action succeeds",
  );
  await page.locator('[data-media-purge-state="purged"]').waitFor();
};
try {
  for (const js of [true, false]) {
    const context = await contextFor(js),
      page = await context.newPage();
    page.on("pageerror", (error) => errors.push(error.message));
    check(
      (await page.goto(address())).status() === 200,
      "actual bounded original and pending catalogue opens",
    );
    await ready(page);
    check(
      (await page.locator('[data-media-purge-state="purging"]').count()) > 0,
      "actual unfinished irreversible decisions remain visible",
    );
    for (const width of [320, 390, 820]) {
      await page.setViewportSize({ width, height: 900 });
      for (const zoom of [1, 2]) {
        await page.evaluate(
          (value) => (document.documentElement.style.zoom = String(value)),
          zoom,
        );
        check(
          await page.evaluate(
            () =>
              document.documentElement.scrollWidth <=
              document.documentElement.clientWidth + 1,
          ),
          "pending original catalogue fits at " + width + " and zoom " + zoom,
        );
        const controls = page.locator(
          ".removed-media-card a,.removed-media-card button,.media-library-pager a,.media-library-pager button,.media-library-pager input",
        );
        for (let i = 0; i < (await controls.count()); i++) {
          const node = controls.nth(i);
          if (!(await node.isVisible())) continue;
          const box = await node.evaluate((el) => {
            const r = el.getBoundingClientRect();
            return {
              w: r.width,
              h: r.height,
              font: parseFloat(getComputedStyle(el).fontSize),
            };
          });
          check(
            box.w >= 43.5 && box.h >= 43.5 && box.font >= 16,
            "actual original/pending control is touch sized and readable",
          );
        }
      }
    }
    await page.evaluate(() => (document.documentElement.style.zoom = "1"));
    await page.setViewportSize({ width: 390, height: 900 });
    const id = fixture[js ? "js" : "nojs"];
    check(
      (await page.goto(address(id))).status() === 200,
      "actual selected original opens for deliberate irreversible action",
    );
    await ready(page);
    const panel = page.locator("#media-purge");
    check(
      !(await panel.evaluate((node) => node.open)),
      "irreversible action begins collapsed",
    );
    await panel.locator("summary").focus();
    await page.keyboard.press("Enter");
    check(
      await panel.evaluate((node) => node.open),
      "keyboard opens actual native irreversible choices",
    );
    let form = panel.locator("form");
    let confirm = form.locator('[name="confirm_purge"]');
    check(
      !(await confirm.isChecked()),
      "irreversible confirmation is never preselected",
    );
    const before = posts;
    await form.locator("button").click();
    check(
      posts === before &&
        (await confirm.evaluate((node) => !node.validity.valid)),
      "required native confirmation prevents a request",
    );
    for (const width of [320, 390, 820]) {
      await page.setViewportSize({ width, height: 900 });
      for (const zoom of [1, 2]) {
        await page.evaluate(
          (value) => (document.documentElement.style.zoom = String(value)),
          zoom,
        );
        check(
          await page.evaluate(
            () =>
              document.documentElement.scrollWidth <=
              document.documentElement.clientWidth + 1,
          ),
          "deliberate irreversible form fits at " + width + " and zoom " + zoom,
        );
        for (const node of [
          panel.locator("summary"),
          form.locator("label"),
          form.locator("button"),
        ]) {
          const box = await node.evaluate((el) => {
            const r = el.getBoundingClientRect();
            return {
              w: r.width,
              h: r.height,
              font: parseFloat(getComputedStyle(el).fontSize),
            };
          });
          check(
            box.w >= 43.5 && box.h >= 43.5 && box.font >= 16,
            "actual irreversible confirmation and button are touch sized and readable",
          );
        }
      }
    }
    await page.evaluate(() => (document.documentElement.style.zoom = "1"));
    await page.setViewportSize({ width: 390, height: 900 });
    if (js) {
      await form
        .locator('[name="removal_version"]')
        .evaluate((node) => (node.value = "0"));
      await confirm.check();
      const failed = page.waitForResponse(
        (r) => r.request().method() === "POST",
      );
      await form.locator("button").click();
      check(
        (await failed).status() === 409,
        "actual stale irreversible form is rejected",
      );
      const alert = form.locator("[data-media-removal-error]");
      await alert.waitFor({ state: "visible" });
      for (
        let i = 0;
        i < 100 &&
        !(await alert.evaluate((node) => document.activeElement === node));
        i++
      )
        await new Promise((resolve) => setTimeout(resolve, 50));
      check(
        (await alert.evaluate((node) => document.activeElement === node)) &&
          !(await confirm.isChecked()),
        "actual error gets focus and clears irreversible confirmation",
      );
      check(
        (await form.locator('[name="removal_version"]').inputValue()) === "0",
        "conflict does not silently promote the old irreversible version",
      );
      await checkCurrent(page, form, id);
      await page
        .locator('#media-purge [name="removal_version"][value="1"]')
        .waitFor({ state: "attached" });
      await page.locator("#media-purge summary").click();
      form = page.locator("#media-purge form");
      confirm = form.locator('[name="confirm_purge"]');
      await mkdir(".local/browser-tests/media-purge", { recursive: true });
      await page.screenshot({
        path: ".local/browser-tests/media-purge/mobile.png",
      });
    }
    const confirmationLayout = await form.locator("label").evaluate((label) => {
      const box = label.querySelector("input").getBoundingClientRect(),
        text = label.querySelector("span").getBoundingClientRect();
      return (
        box.right < text.left &&
        Math.abs(box.top + box.height / 2 - (text.top + text.height / 2)) < 1
      );
    });
    check(
      confirmationLayout,
      "actual checkbox sits beside the readable deliberate confirmation",
    );
    await confirm.check();
    await submit(page, form, js);
    check(
      (await page.locator('[data-media-purge-state="purged"] img').count()) ===
        0 &&
        (await page
          .locator('form:has(input[name="operation"][value="media-restore"])')
          .count()) === 0,
      "completed action has no missing image or false original restoration",
    );
    const resume = fixture[js ? "resume_js" : "resume_nojs"];
    check(
      (await page.goto(address(resume))).status() === 200,
      "actual pending deletion opens its exact remaining original phase",
    );
    const pending = page.locator('[data-media-purge-state="purging"]');
    form = pending.locator("form");
    check(
      (await pending.locator("img").count()) === 0 &&
        !(await form.locator('[name="confirm_purge"]').isChecked()),
      "pending partial originals have no fabricated image or preselected continuation",
    );
    check(
      (await form.locator('[name="operation"]').inputValue()) ===
        "media-purge-resume" &&
        (await form.locator('[name="removal_version"]').inputValue()) === "2",
      "continuation uses the exact original committed phase and version",
    );
    const resumeBefore = posts;
    await form.locator("button").click();
    check(
      posts === resumeBefore,
      "continuation also requires new explicit confirmation",
    );
    await form.locator('[name="confirm_purge"]').check();
    await submit(page, form, js);
    if (js) {
      const lost = fixture.lost;
      check(
        (await page.goto(address(lost))).status() === 200,
        "new own lost-response original opens",
      );
      await page.locator("#media-purge summary").click();
      form = page.locator("#media-purge form");
      let lostReply = false;
      await page.route(
        (url) =>
          url.origin === fixture.origin &&
          url.searchParams.get("item") === lost,
        async (route) => {
          if (route.request().method() === "POST" && !lostReply) {
            posts++;
            const response = await route.fetch();
            const data = await response.json();
            check(
              response.status() === 200 &&
                data.html.includes('data-media-purge-state="purged"'),
              "actual server accepts only the new own purge before its response is lost",
            );
            lostReply = true;
            return route.abort("failed");
          }
          return route.fallback();
        },
      );
      await form.locator('[name="confirm_purge"]').check();
      await form.locator("button").click();
      await form
        .locator("[data-media-removal-error]")
        .waitFor({ state: "visible" });
      check(
        lostReply &&
          !(await form.locator('[name="confirm_purge"]').isChecked()) &&
          (await form.locator("[data-media-removal-check]").isVisible()),
        "lost real acceptance clears confirmation and offers only a current read",
      );
      const afterLost = posts;
      await new Promise((resolve) => setTimeout(resolve, 300));
      check(
        posts === afterLost,
        "unknown acceptance never automatically sends another irreversible request",
      );
      await checkCurrent(page, form, lost);
      await page.locator('[data-media-purge-state="purged"]').waitFor();
      check(
        posts === afterLost,
        "reading original completion invents no new decision or physical deletion",
      );
    }
    await context.close();
  }
  check(
    posts === 6,
    "exactly five own confirmed irreversible actions and one rejected old form",
  );
  check(
    external.length === 0 && errors.length === 0,
    "no external request or uncaught browser error",
  );
  console.log(
    JSON.stringify({
      suite: "media-purge-browser",
      checks,
      posts,
      external_requests: 0,
      live_transport: false,
    }),
  );
} finally {
  await browser.close();
}
