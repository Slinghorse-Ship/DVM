import assert from "node:assert/strict";
import fs from "node:fs/promises";
import path from "node:path";

let input = "";
for await (const chunk of process.stdin) input += chunk;
const fixture = JSON.parse(input);
const token = (
  await fs.readFile(path.join(fixture.root, "SETUP-KEY.txt"), "utf8")
).trim();
const { chromium } = await import(
  process.env.DVM_PLAYWRIGHT_MODULE || process.env.PLAYWRIGHT_MODULE || "playwright"
);
const browser = await chromium.launch({
  headless: true,
  ...((process.env.DVM_CHROMIUM || process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE)
    ? { executablePath: process.env.DVM_CHROMIUM || process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE }
    : {}),
});
const context = await browser.newContext();
const errors = [];
// Forward the real HTTPS-origin browser request to the local PHP test server.
// Every response is produced by the actual uploaded controller; no UI/API fixture is substituted.
await context.route("https://dvm-install.example/**", async (route) => {
  const request = route.request();
  const url = new URL(request.url());
  const headers = await request.allHeaders();
  headers.host = "dvm-install.example";
  delete headers["content-length"];
  const response = await context.request.fetch(
    `http://127.0.0.1:${fixture.port}${url.pathname}${url.search}`,
    {
      method: request.method(),
      headers,
      data: request.postDataBuffer() || undefined,
      maxRedirects: 0,
    },
  );
  await route.fulfill({ response });
});
const page = await context.newPage();
page.on("pageerror", (error) => errors.push(error.message));
const screenshots = path.join(
  path.dirname(new URL(import.meta.url).pathname),
  "../../.local/browser-tests",
);
await fs.mkdir(screenshots, { recursive: true });
try {
  for (const width of [1440, 820, 390, 320]) for (const font of [100, 200]) {
    await page.setViewportSize({ width, height: 1000 });
    await page.goto("https://dvm-install.example/setup/");
    await page.locator("h1").waitFor();
    await page.evaluate(value => document.documentElement.style.fontSize = value + "%", font);
    assert.match(await page.title(), /DVM/);
    assert(await page.locator("button[type=submit]").isEnabled());
    if (width <= 600) assert(await page.locator('main').evaluate(el => {
      const style = getComputedStyle(el);
      return el.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight) >= innerWidth - 48;
    }), 'mobile padding preserves usable width with enlarged text');
    assert(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth + 1,
      ),
      `overflow at ${width}`,
    );
    assert.equal(
      await page.locator("script[type=module][src*=bootstrap-6]").count(),
      1,
    );
    assert(
      await page
        .locator("[name=club_name]")
        .evaluate((el) => el.classList.contains("form-control")),
    );
    assert(
      await page
        .locator("button[type=submit]")
        .evaluate((el) => el.classList.contains("btn")),
    );
    await page.locator("summary").first().focus();
    await page.keyboard.press("Enter");
    assert(
      await page
        .locator("details")
        .first()
        .evaluate((el) => el.open),
    );
    await page.keyboard.press("Tab");
    assert.equal(
      await page.evaluate(() => document.activeElement.name),
      "token",
    );
    await page.locator(".setup-addons > summary").focus();
    await page.keyboard.press("Enter");
    assert(await page.locator(".setup-addons").evaluate((el) => el.open));
    assert(await page.locator("[name=fints_enable]").isEnabled());
    await page.locator("[name=fints_enable]").check();
    assert(await page.locator("[name=fints_enable]").isChecked());
    assert(await page.locator("[name=ocr_enable]").isDisabled());
    assert.match(
      await page.locator(".setup-addons").textContent(),
      /proc_open.*fehlen/,
    );
    assert(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth + 1,
      ),
      `expanded addons overflow at ${width}`,
    );
    await page.screenshot({
      path: path.join(screenshots, `dvm-setup-${width}-${font}.png`),
      fullPage: true,
    });
  }
  await page.locator('.setup-hosting summary').click();
  await page.locator('[name=scheduler_php]').fill('/forged/later/php');
  const completedResponse = page.waitForResponse(async response => {
    if (response.request().method() !== 'POST' || new URL(response.url()).pathname !== '/setup/') return false;
    const body = await response.json().catch(() => null); return body?.phase === 'installed';
  }, {timeout:90000});
  completedResponse.catch(() => {});
  await page.locator("[name=token]").fill(token);
  await page.locator("[data-resume]").click();
  if (fixture.ocr_failed) {
    await page
      .locator("[data-error]")
      .filter({ hasText: "OCR-Paket fehlt" })
      .waitFor();
    assert(await page.locator("[data-addons]").isVisible());
    assert(await page.locator("[name=ocr_enable]").isDisabled());
    await page.locator("[data-addons]").click();
  }
  await page
    .locator("[data-login]")
    .waitFor({ state: "visible", timeout: 90_000 });
  assert.match(
    await page.locator("[data-status]").textContent(),
    /eingerichtet/,
  );
  assert.equal(await page.locator("[data-error]").textContent(), "");
  assert(await page.locator("form").isHidden());
  assert.equal(await page.locator('h1').textContent(), 'DVM ist eingerichtet');
  assert.equal(await page.locator('main > details').evaluate(el => el.open), false, 'completed prerequisites leave the hosting instructions in view');
  const completed = await (await completedResponse).json();
  assert.equal(completed.scheduler.php, fixture.expected_php, 'resume retains the original private PHP selection');
  assert.equal(completed.scheduler.status, 'not-verified');
  assert.equal(completed.scheduler.jobs.length, 15);
  assert(!completed.scheduler_text.includes(token));
  await fs.writeFile(path.join(fixture.root, 'private/browser-scheduler-result.json'), JSON.stringify({scheduler: completed.scheduler, scheduler_text: completed.scheduler_text}));
  const hosting = page.locator('[data-scheduler]'); await hosting.waitFor();
  assert.equal(await hosting.locator('[data-scheduler-command]').count(), 15);
  for (const job of completed.scheduler.jobs) {
    const command = hosting.locator('[data-scheduler-command="' + job.id + '"]');
    assert.equal(await command.inputValue(), job.cron_command);
    assert(await command.evaluate(el => el.readOnly));
    assert(!job.command.includes('/forged/later/php'));
    assert(job.command.includes(fixture.root + '/private/worker.php'));
  }
  await hosting.locator('details').first().locator('summary').click();
  for (const width of [1440, 820, 390, 320]) for (const font of [100, 200]) {
    await page.setViewportSize({width, height:1000});
    await page.evaluate(value => document.documentElement.style.fontSize = value + '%', font);
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'completed hosting instructions fit ' + width + '/' + font);
    await hosting.locator('textarea').first().focus();
    assert(await hosting.locator('textarea').first().evaluate(el => el === document.activeElement));
    if ((font === 100 && [1440,390].includes(width)) || (font === 200 && width === 320))
      await page.screenshot({path:path.join(screenshots, `dvm-hosting-${width}-${font}.png`),fullPage:true});
  }
  const downloadEvent = page.waitForEvent('download');
  await page.locator('[data-scheduler-download]').click(); const download = await downloadEvent;
  assert.equal(download.suggestedFilename(), 'DVM-Hintergrundaufgaben.txt');
  assert.equal(await fs.readFile(await download.path(), 'utf8'), completed.scheduler_text);
  await page.screenshot({
    path: path.join(screenshots, "dvm-setup-complete.png"),
    fullPage: true,
  });
  await page.locator("[data-login]").click();
  assert.match(await page.title(), /DVM/);
  assert.match(await page.locator("body").textContent(), /DeinVereinsManager/);
  assert.deepEqual(errors, []);
  const response = await page.goto("https://dvm-install.example/setup/");
  assert.equal(response.status(), 410);
  const result = {
    suite: "webspace-installer-browser",
    status: "passed",
    widths: [1440, 820, 390, 320],
    font_views: 16,
    hosting_jobs: 15,
    original_php_preserved: true,
    hosting_download_exact: true,
    shell_functions: "disabled",
    actual_http: true,
    external_transport: false,
  };
  await fs.writeFile(
    path.join(screenshots, "dvm-setup-result.json"),
    JSON.stringify(result, null, 2),
  );
  process.stdout.write(JSON.stringify(result) + "\n");
} catch (error) {
  console.error(error);
  await page.content().then(html => fs.writeFile(path.join(screenshots, 'dvm-setup-failure.html'), html)).catch(() => {});
  await page.screenshot({path:path.join(screenshots, 'dvm-setup-failure.png'),fullPage:true}).catch(() => {});
  throw error;
} finally {
  await context.close();
  await browser.close();
}
