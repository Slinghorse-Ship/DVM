import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const root = fileURLToPath(new URL("../../", import.meta.url)),
  fixtures = root + "vereinsverwaltung/tests/fixtures/photo-import/";
const heic = await readFile(fixtures + "own-camera.heic"),
  raw = await readFile(fixtures + "own-camera.dng"),
  jxl = await readFile(fixtures + "own-jxl-preview.dng");
const browser = await chromium.launch({ executablePath: "/usr/bin/chromium" });
let checks = 0;
const errors = [],
  external = [],
  writes = [];
const check = (ok, label) => {
  assert(ok, label);
  checks++;
  console.log("PASS " + label);
};
try {
  for (const standalone of [true, false]) {
    const name = standalone ? "standalone" : "integrated",
      origin = "http://127.0.0.1:" + (standalone ? 8107 : 8111),
      f = JSON.parse(
        await readFile(
          root + ".local/browser-tests/editor-upload-" + name + ".json",
          "utf8",
        ),
      );
    const context = await browser.newContext({
      storageState: standalone
        ? undefined
        : JSON.parse(
            await readFile(
              root + ".local/browser-tests/controls-sessions/admin.json",
              "utf8",
            ),
          ),
      viewport: { width: 320, height: 880 },
      hasTouch: true,
    });
    let blockUpload = false,
      intercepted = 0,
      holdUpload = false,
      holdStarted,
      releaseUpload;
    await context.route("**/*", async (route) => {
      const request = route.request();
      if (new URL(request.url()).origin !== origin) {
        external.push(request.url());
        return route.abort();
      }
      if (request.method() === "POST") writes.push(request.url());
      if (
        holdUpload &&
        request.postData()?.includes("communication-photo-upload")
      ) {
        return new Promise((resolve) => {
          releaseUpload = () =>
            route
              .abort()
              .catch(() => {})
              .finally(resolve);
          holdStarted();
        });
      }
      if (
        blockUpload &&
        request.postData()?.includes("communication-photo-upload")
      ) {
        intercepted++;
        return route.abort();
      }
      return route.continue();
    });
    const p = await context.newPage();
    p.on("pageerror", (e) => errors.push(e.message));
    p.setDefaultTimeout(30000);
    const route = (module, id) =>
      origin +
      (standalone ? "/?" : "/vereinsverwaltung/?") +
      new URLSearchParams({
        view: module,
        [standalone ? "item" : "native_item"]: id,
        section: "compose",
      });
    for (const key of ["newsletter", "press", "social", "story"]) {
      const module = key === "story" ? "social" : key;
      await p.goto(route(module, f.items[key]), {
        waitUntil: "domcontentloaded",
      });
      const form = p.locator("form").filter({
          has: p.locator("[name=operation][value=communication-save]"),
        }),
        editor = form.locator(
          "[data-kh-editor=" + (key === "story" ? "story" : "blocks") + "]",
        );
      if (key === "story") {
        const details = editor.locator("xpath=ancestor::details[1]");
        if (await details.count())
          await details.evaluate((d) => {
            d.open = true;
          });
      } else await form.locator(".compose-blocks>summary").click();
      const title = f.prefix + key + " edited";
      await form.locator("[name=title]").fill(title);
      if (key === "social" || key === "story") {
        const options = form
          .locator("[name=kind]")
          .locator("xpath=ancestor::details[1]");
        if (
          (await options.count()) &&
          !(await options.evaluate((details) => details.open))
        )
          await options.locator(":scope > summary").click();
      }
      if (key === "social") {
        const storyEditor = form.locator("[data-kh-editor=story]"),
          storyInput = storyEditor.locator("[data-editor-upload]");
        check(
          await storyInput.isDisabled(),
          name + " inactive story file input stays disabled",
        );
        await form.locator("[name=kind]").selectOption("story");
        await storyEditor.locator(".photo-edit-launch").waitFor();
        check(
          (await storyEditor.locator(".photo-edit-launch").count()) === 1 &&
            (await form.locator("[name=template]").inputValue()) ===
              "social-story",
          name +
            " revealing story offers its optional editor and the correct native template",
        );
        await form.locator("[name=kind]").selectOption("post");
        await form.locator("[name=kind]").selectOption("story");
        check(
          (await storyEditor.locator(".photo-edit-launch").count()) === 1,
          name + " repeated native story activation keeps one optional editor",
        );
        await form.locator("[name=kind]").selectOption("post");
        check(
          (await storyInput.isDisabled()) &&
            (await form.locator("[name=template]").inputValue()) ===
              "social-post",
          name +
            " returning to post restores the inactive story and its original template",
        );
      }
      check(
        (await editor.locator("[data-editor-upload]").count()) === 1,
        name +
          " " +
          key +
          " actual editor exposes one direct multiple photo input",
      );
      const photos = [
        { name: f.prefix + key + "-heic.HEIC", mimeType: "", buffer: heic },
        {
          name:
            f.prefix + key + (key === "newsletter" ? "-jxl.DNG" : "-raw.DNG"),
          mimeType: "",
          buffer: key === "newsletter" ? jxl : raw,
        },
      ];
      if (key === "newsletter")
        photos.splice(1, 0, {
          name: f.prefix + "broken.DNG",
          mimeType: "",
          buffer: Buffer.from("own invalid camera"),
        });
      if (key === "press")
        photos.push({
          name: f.prefix + "invalid.png",
          mimeType: "image/png",
          buffer: Buffer.from("own invalid normal image"),
        });
      await editor.locator("[data-editor-upload]").setInputFiles(photos);
      await editor
        .locator("[data-editor-status]")
        .filter({ hasText: /2 Fotos eingefügt/ })
        .waitFor();
      check(
        (await form.locator("[name=title]").inputValue()) === title,
        name +
          " " +
          key +
          " preserves the unsaved typed title during real uploads",
      );
      check(
        JSON.parse(await editor.locator("[data-editor-value]").inputValue())
          .length === 2,
        name +
          " " +
          key +
          " inserts exactly the two accepted own camera photos",
      );
      if (key === "newsletter")
        check(
          (await p.locator("#photo-import-message").innerText()).includes(
            "broken.DNG",
          ),
          name + " RAW conversion failure keeps the successful uploads",
        );
      if (key === "press")
        check(
          (await editor.locator("[data-editor-status]").innerText()).includes(
            "1 nicht hochgeladen",
          ),
          name +
            " actual invalid server upload is isolated from successful photos",
        );
      check(
        await p.evaluate(
          () => document.documentElement.scrollWidth <= innerWidth + 2,
        ),
        name + " " + key + " real upload view fits 320 px",
      );
      check(
        await editor
          .locator("[data-editor-upload]")
          .evaluate((el) => el.getBoundingClientRect().height >= 44),
        name + " " + key + " file input retains a 44 px touch target",
      );
      const sources = JSON.parse(
          await editor.locator("[data-editor-value]").inputValue(),
        ),
        images = [];
      for (let index = 0; index < sources.length; index++) {
        await editor
          .locator("[data-editor-select]")
          .selectOption(String(index));
        const displayed = await editor
          .locator("[data-editor-preview] img")
          .evaluateAll((images) => images.map((image) => image.src));
        check(
          displayed.length === 1 &&
            displayed[0].includes(sources[index].media_ref),
          name +
            " " +
            key +
            " selected photo preview retains its exact stored source ID",
        );
        images.push(...displayed);
      }
      if (key === "story") {
        check(
          await editor.locator('[data-editor-step="1"]').isDisabled(),
          name +
            " actual last story navigation preserves its disabled boundary",
        );
        await form.locator("[name=kind]").selectOption("post");
        await form.locator("[name=kind]").selectOption("story");
        check(
          (await editor.locator('[data-editor-step="1"]').isDisabled()) &&
            (await editor.locator("[data-editor-select]").inputValue()) === "1",
          name +
            " story reactivation preserves selection and disabled navigation state",
        );
      }
      check(
        images.length === 2 && images.every((url) => url.startsWith(origin)),
        name + " " + key + " previews the exact local uploaded images",
      );
      const rejected = await form.evaluate(async (form) => {
        const data = new FormData();
        data.set("csrf", "own-wrong-token");
        const action = form.elements.namedItem("action");
        if (action) data.set("action", action.value);
        data.set("operation", "communication-photo-upload");
        data.set("id", form.elements.namedItem("id").value);
        data.set(
          "photo",
          new Blob(["own invalid image"], { type: "image/png" }),
          "own-denied.png",
        );
        const response = await fetch(location.href, {
          method: "POST",
          headers: { Accept: "application/json" },
          body: data,
        });
        return response.status;
      });
      check(
        rejected >= 400,
        name +
          " " +
          key +
          " actual photo operation rejects incorrect CSRF without replacing the draft",
      );
      if (key === "newsletter") {
        const chooser = p.waitForEvent("filechooser");
        await editor
          .getByRole("button", {
            name: "Foto vor dem Hochladen bearbeiten",
            exact: true,
          })
          .click();
        await (
          await chooser
        ).setFiles([
          {
            name: f.prefix + "newsletter-edited.HEIC",
            mimeType: "",
            buffer: heic,
          },
        ]);
        await p.waitForFunction(
          () => !!document.querySelector(".photo-editor")?.photoEditor,
        );
        await p
          .locator(".photo-editor")
          .getByRole("button", { name: "Rechts drehen", exact: true })
          .click();
        await p
          .locator(".photo-editor")
          .getByRole("button", { name: "Übernehmen", exact: true })
          .click();
        await editor
          .locator("[data-editor-status]")
          .filter({ hasText: "1 Foto eingefügt." })
          .waitFor();
        check(
          JSON.parse(await editor.locator("[data-editor-value]").inputValue())
            .length === 3,
          name +
            " optional camera editor applies a third real upload directly to the same draft",
        );
        const png = await p.evaluate(async () => {
          const c = document.createElement("canvas");
          c.width = c.height = 10;
          return [
            ...new Uint8Array(
              await (await new Promise((r) => c.toBlob(r))).arrayBuffer(),
            ),
          ];
        });
        blockUpload = true;
        await editor.locator("[data-editor-upload]").setInputFiles([
          {
            name: f.prefix + "not-sent.png",
            mimeType: "image/png",
            buffer: Buffer.from(png),
          },
        ]);
        await editor
          .locator("[data-editor-status]")
          .filter({ hasText: "Letzten Upload in der Mediathek prüfen." })
          .waitFor();
        blockUpload = false;
        check(
          intercepted === 1 &&
            JSON.parse(await editor.locator("[data-editor-value]").inputValue())
              .length === 3,
          name +
            " interrupted request is not retried and preserves accepted draft photos",
        );
        const started = new Promise((resolve) => {
          holdStarted = resolve;
        });
        holdUpload = true;
        await editor.locator("[data-editor-upload]").setInputFiles([
          {
            name: f.prefix + "cancelled.png",
            mimeType: "image/png",
            buffer: Buffer.from(png),
          },
        ]);
        await started;
        check(
          await form
            .getByRole("button", { name: "Entwurf speichern", exact: true })
            .isDisabled(),
          name + " draft submit stays locked during a pending upload",
        );
        await editor.locator("[data-editor-upload-cancel]").click();
        releaseUpload();
        holdUpload = false;
        await editor
          .locator("[data-editor-status]")
          .filter({ hasText: "Weitere Uploads gestoppt." })
          .waitFor();
        check(
          JSON.parse(await editor.locator("[data-editor-value]").inputValue())
            .length === 3 &&
            (await form
              .getByRole("button", { name: "Entwurf speichern", exact: true })
              .isEnabled()),
          name +
            " actual cancel preserves successful photos and restores draft editing",
        );
      }
      if (key === "story")
        for (let index = 0; index < 2; index++) {
          await editor
            .locator("[data-editor-select]")
            .selectOption(String(index));
          await editor
            .locator("[data-editor-field=title]")
            .fill("Eigene Folie " + (index + 1));
        }
      await form
        .getByRole("button", { name: "Entwurf speichern", exact: true })
        .click();
      await p.waitForFunction(
        () =>
          document
            .querySelector("[name=operation][value=communication-save]")
            ?.form.querySelector("[name=version]")?.value === "2",
      );
      await p.goto(route(module, f.items[key]), {
        waitUntil: "domcontentloaded",
      });
      check(
        (await p.locator("[name=title]").inputValue()) === title,
        name + " " + key + " reload confirms the actual typed draft save",
      );
      const saved = p.locator(
        "[data-kh-editor=" +
          (key === "story" ? "story" : "blocks") +
          "] [data-editor-value]",
      );
      check(
        JSON.parse(await saved.inputValue()).length ===
          (key === "newsletter" ? 3 : 2),
        name +
          " " +
          key +
          " fresh response retains exact persisted image associations",
      );
    }
    await context.close();
  }
  check(errors.length === 0, "no app runtime errors: " + errors.join("; "));
  check(external.length === 0, "no external app requests");
  console.log(
    JSON.stringify({
      checks,
      writes: writes.length,
      external_service_calls: false,
    }),
  );
} finally {
  await browser.close();
}
