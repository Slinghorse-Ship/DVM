import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { execFileSync } from "node:child_process";
import { createServer } from "node:http";
import { fileURLToPath } from "node:url";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const root = fileURLToPath(new URL("../", import.meta.url));
const markup = execFileSync(
  "php",
  [fileURLToPath(new URL("./editor-list-fixture.php", import.meta.url))],
  { encoding: "utf8" },
);
const assets = new Map([
  ["/editor.js", "native/Web/assets/editor.js"],
  ["/editor.css", "native/Web/assets/editor.css"],
  ["/bootstrap.css", "public/vendor/bootstrap/bootstrap-6.0.0-alpha.1.min.css"],
  ["/controls.css", "native/Web/assets/bootstrap-dvm.css"],
]);
const server = createServer(async (req, res) => {
  if (req.url === "/") {
    res.setHeader("Content-Type", "text/html; charset=utf-8");
    res.end(
      '<!doctype html><html lang="de"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/bootstrap.css"><link rel="stylesheet" href="/controls.css"><link rel="stylesheet" href="/editor.css"><style>body{margin:0;padding:16px}form{max-width:900px;margin:auto}input,textarea{max-width:100%}</style>' +
        markup +
        '<script>document.addEventListener("submit",event=>{event.preventDefault();window.saved=(window.saved||0)+1;});</script><script src="/editor.js"></script></html>',
    );
  } else if (assets.has(req.url)) {
    res.setHeader(
      "Content-Type",
      req.url.endsWith(".js") ? "text/javascript" : "text/css",
    );
    res.end(await readFile(root + assets.get(req.url)));
  } else {
    res.writeHead(404);
    res.end();
  }
});
await new Promise((resolve) => server.listen(0, "127.0.0.1", resolve));
const origin = "http://127.0.0.1:" + server.address().port;
const browser = await chromium.launch(
  process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE
    ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE }
    : {},
);
let checks = 0;
const errors = [],
  external = [];
const check = (ok, label) => {
  assert(ok, label);
  checks++;
  console.log("PASS " + label);
};
try {
  const context = await browser.newContext({ hasTouch: true });
  await context.route("**/*", (route) =>
    new URL(route.request().url()).origin === origin
      ? route.continue()
      : (external.push(route.request().url()), route.abort()),
  );
  const p = await context.newPage();
  p.on("pageerror", (error) => errors.push(error.message));
  for (const width of [320, 390, 820]) {
    await p.setViewportSize({ width, height: 880 });
    await p.goto(origin);
    const editor = p.locator("#blocks [data-kh-editor]"),
      value = editor.locator("[data-editor-value]"),
      select = editor.locator("[data-editor-select]");
    const original = JSON.parse(await value.inputValue());
    check(
      original.length === 100 &&
        (await editor.locator("[data-editor-index]").count()) === 1,
      width + " 100 original blocks render exactly one editable card",
    );
    check(
      (await select.locator("option").count()) === 100,
      width + " every original remains directly selectable",
    );
    check(
      (await editor.boundingBox()).height < 880,
      width +
        " entire 100-block editor stays within one mobile viewport height",
    );
    check(
      await p.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth + 2,
      ),
      width + " editor has no horizontal overflow",
    );
    check(
      await editor
        .locator("[data-editor-step]")
        .evaluateAll((buttons) =>
          buttons.every(
            (button) =>
              button.getBoundingClientRect().height >= 44 &&
              button.getBoundingClientRect().width >= 44,
          ),
        ),
      width + " navigation retains 44 px touch targets",
    );
    check(
      await editor
        .locator("[data-editor-field=text]")
        .evaluate(
          (field) => parseFloat(getComputedStyle(field).fontSize) >= 16,
        ),
      width + " editable input avoids small phone typography",
    );
    await editor
      .getByRole("button", { name: "Nächster Baustein", exact: true })
      .click();
    check(
      (await select.inputValue()) === "1" &&
        JSON.stringify(JSON.parse(await value.inputValue())) ===
          JSON.stringify(original),
      width + " moving through blocks changes no original content",
    );
    await select.selectOption("99");
    check(
      (await editor.locator("legend").textContent()).includes("100.") &&
        (await editor
          .getByRole("button", { name: "Nächster Baustein", exact: true })
          .isDisabled()),
      width +
        " last original is directly reachable with correct navigation boundary",
    );
    await select.focus();
    await p.keyboard.press("Home");
    await p.keyboard.press("Enter");
    check(
      (await select.inputValue()) === "0",
      width + " native keyboard selection reaches the first original",
    );
    await editor
      .locator("[data-editor-field=text]")
      .fill("Eigener bearbeiteter Text 🎶");
    const expected = structuredClone(original);
    expected[0].text = "Eigener bearbeiteter Text 🎶";
    check(
      JSON.stringify(JSON.parse(await value.inputValue())) ===
        JSON.stringify(expected),
      width + " edit changes exactly its selected original value",
    );
    await select.selectOption("50");
    await editor.locator("[data-editor-field=text]").fill("");
    await select.selectOption("99");
    await p
      .locator("#blocks")
      .getByRole("button", { name: "Entwurf speichern", exact: true })
      .click();
    check(
      (await select.inputValue()) === "50" &&
        (await p.evaluate(() => window.saved || 0)) === 0,
      width +
        " submit finds an invalid hidden original and prevents an incomplete save",
    );
    check(
      (await editor.locator("[data-editor-status]").textContent()).includes(
        "51",
      ),
      width + " validation identifies the actual original block number",
    );
    await editor.locator("[data-editor-field=text]").fill(original[50].text);
    await p
      .locator("#blocks")
      .getByRole("button", { name: "Entwurf speichern", exact: true })
      .click();
    check(
      (await p.evaluate(() => window.saved)) === 1,
      width + " corrected original allows saving the complete document",
    );
    await select.selectOption("5");
    await editor
      .getByRole("button", { name: "Baustein 6: Nach oben", exact: true })
      .click();
    const reordered = JSON.parse(await value.inputValue());
    check(
      reordered[4].text === original[5].text &&
        reordered[5].type === original[4].type &&
        reordered.length === 100,
      width + " ordering preserves every original across selection changes",
    );
    await editor
      .getByRole("button", { name: "Baustein 5: Entfernen", exact: true })
      .click();
    check(
      JSON.parse(await value.inputValue()).length === 99,
      width + " selected removal affects exactly one original",
    );
    await editor
      .getByRole("button", { name: "Entfernen rückgängig machen", exact: true })
      .click();
    check(
      JSON.stringify(JSON.parse(await value.inputValue())) ===
        JSON.stringify(reordered),
      width + " undo restores the exact removed original and its position",
    );
    await editor.locator(".kh-editor-preview-panel>summary").click();
    check(
      await editor.locator("[data-editor-preview]").isVisible(),
      width + " preview is optional and reachable for the selected original",
    );
    const story = p.locator("#story [data-kh-editor]");
    check(
      (await story.locator("[data-editor-index]").count()) === 1 &&
        JSON.parse(await story.locator("[data-editor-value]").inputValue())
          .length === 10,
      width + " all ten story originals survive the compact view",
    );
    await story.locator("[data-editor-select]").selectOption("9");
    check(
      (await story.locator("[data-editor-field=title]").inputValue()) ===
        "Eigene Folie 9",
      width + " last story title remains its exact own original",
    );
  }
  await p.setViewportSize({ width: 390, height: 850 });
  await p.goto(origin);
  await p.evaluate(() => {
    document.documentElement.style.zoom = "2";
  });
  const zoomFits = await p.evaluate(
    () => document.documentElement.scrollWidth <= innerWidth + 2,
  );
  if (!zoomFits)
    console.log(
      JSON.stringify(
        await p.evaluate(() => ({
          viewport: innerWidth,
          scroll: document.documentElement.scrollWidth,
          offenders: [...document.querySelectorAll("body *")]
            .map((el) => ({
              tag: el.tagName,
              cls: el.className,
              rect: el.getBoundingClientRect().toJSON(),
            }))
            .filter((el) => el.rect.right > innerWidth + 2)
            .slice(0, 12),
        })),
      ),
    );
  check(
    await p.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth + 2,
    ),
    "200 percent zoom keeps compact controls within the viewport",
  );
  check(
    (await p.locator("#blocks [data-editor-index]").count()) === 1,
    "200 percent zoom retains one editable original",
  );
  check(errors.length === 0, "no browser runtime errors: " + errors.join("; "));
  check(external.length === 0, "no external browser requests");
  console.log(
    JSON.stringify({
      checks,
      source_blocks: 100,
      source_slides: 10,
      external_service_calls: false,
    }),
  );
} finally {
  await browser.close();
  await new Promise((resolve) => server.close(resolve));
}
