import assert from "node:assert/strict";
import { readFile, mkdir } from "node:fs/promises";
import { fileURLToPath } from "node:url";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const root = fileURLToPath(new URL("../../", import.meta.url)),
  origin = "http://127.0.0.1:8111";
const credentials = JSON.parse(
  await readFile(
    root +
      ".local/native-cms-integration/integrated-manager-runtime/browser-credentials.json",
    "utf8",
  ),
);
const out = root + ".local/browser-tests";
await mkdir(out, { recursive: true, mode: 0o700 });
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
  const context = await browser.newContext({storageState:process.env.SOCIAL_REPLIES_STORAGE_DIR?JSON.parse(await readFile(process.env.SOCIAL_REPLIES_STORAGE_DIR+"/admin.json","utf8")):undefined}),
    external = [],
    errors = [];
  await context.route("**/*", (r) =>
    new URL(r.request().url()).origin === origin
      ? r.continue()
      : (external.push(r.request().url()), r.abort()),
  );
  const page = await context.newPage();
  page.on("pageerror", (e) => errors.push(e.message));
  const login = async (p, key) => {
    await p.goto(origin + "/login/");
    await p.locator("[name=email]").fill(credentials[key].email);
    await p.locator("[name=password]").fill(credentials[key].password);
    await Promise.all([
      p.waitForURL("**/vereinsverwaltung/**"),
      p
        .getByRole("button", {
          name: "Anmelden und Vereinsmanager öffnen",
          exact: true,
        })
        .click(),
    ]);
  };
  if(!process.env.SOCIAL_REPLIES_STORAGE_DIR) await login(page, "admin");
  let draftId;
  for (const width of [1440, 820, 390]) {
    await page.setViewportSize({ width, height: 1050 });
    let library = await page.goto(
      origin +
        "/vereinsverwaltung/?view=social&section=library&channel=facebook&q=Synthetischer%20Antwort-Browsertermin",
    );
    check(
      library.status() === 200,
      "private original thread library loads " + width,
    );
    const thread = page
      .locator(".social-content-card")
      .filter({ hasText: "Synthetischer Antwort-Browsertermin" });
    await thread.locator("details>summary").click();
    check(
      (await thread.locator("blockquote").count()) === 2 &&
        (await thread.innerText()).includes("Eigener Kommentar") &&
        (await thread.innerText()).includes("Antwort im Kommentarstrang"),
      "own answer and visitor follow-up appear with distinct thread labels " +
        width,
    );
    check(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth + 2,
      ),
      "expanded comment thread fits " + width,
    );
    const response = await page.goto(
      origin + "/vereinsverwaltung/?view=social&section=review",
    );
    check(
      response.status() === 200,
      "private original comment list loads " + width,
    );
    if(width===390) await page.locator('.social-chat-sidebar').waitFor({state:'visible'});
    const card=page.locator('.chat-choice').filter({hasText:'Gibt es einen barrierefreien Eingang?'}).first();
    check(await card.isVisible(), 'own original has a conversation entry '+width);
    await card.click();
    await page.locator('[name=reply_text]').waitFor();
    const form = page.locator("form").filter({
      has: page.locator("[name=operation][value=social-reply-save]"),
    });
    const oldVersion = Number(
      await form.locator("[name=reply_version]").inputValue(),
    );
    const oldData = await form.evaluate((e) => [...new FormData(e).entries()]);
    const text =
      "Der barrierefreie Eingang ist links neben dem Haupteingang. <script>kein Code</script> " +
      width;
    await form.locator("textarea[name=reply_text]").fill(text);
    await form
      .getByRole("button", { name: "Entwurf speichern", exact: true })
      .click();
    await page.waitForFunction(
      (expected) =>
        Number(
          document
            .querySelector("[name=operation][value=social-reply-save]")
            ?.form.querySelector("[name=reply_version]")?.value,
        ) === expected,
      oldVersion + 1,
    );
    draftId = new URL(page.url()).searchParams.get("reply");
    check(
      !!draftId &&
        new URL(page.url()).searchParams.get("section") === "replies",
      "authenticated save returns to exact saved reply " + width,
    );
    check(
      (await page
        .locator(".social-reply-preview .social-caption")
        .innerText()) === text,
      "preview displays exact saved answer instead of unsaved field " + width,
    );
    check(
      (await page.locator(".social-reply-layout script").count()) === 0,
      "reply and original markup stay escaped " + width,
    );
    check(
      (await page.locator(".social-reply-preview .badge").innerText()).includes(
        "nicht versendet",
      ),
      "private draft does not claim provider success " + width,
    );
    check(
      await page.locator("[data-human-send]").getAttribute("name") === "human_send" &&
        /^[a-f0-9]{32}$/.test(await page.locator("[name=human_key]").inputValue()),
      "direct human send requires its explicit button and original request key " + width,
    );
    check(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth + 2,
      ),
      "original and reply forms fit " + width,
    );
    const chat=await page.locator('.social-chat').evaluate(node=>({height:node.getBoundingClientRect().height,scroll:node.scrollHeight}));
    check(chat.height<=1050&&chat.scroll<=chat.height+2, 'actual reply chat has bounded height '+width);
    check(
      await page.locator(".workspace-tabs").evaluate((nav) =>
        [...nav.querySelectorAll("a")].every((a) => {
          const r = a.getBoundingClientRect(),
            b = nav.getBoundingClientRect();
          return r.left >= b.left - 1 && r.right <= b.right + 1;
        }),
      ),
      "all Bootstrap social tabs remain visible " + width,
    );
    await page.screenshot({
      path: out + "/social-reply-draft-" + width + ".png",
      fullPage: true,
    });
    if (oldVersion > 0) {
      const stale = await context.request.post(
        origin + "/vereinsverwaltung/?view=social&section=replies",
        {
          headers: {
            Accept: "application/json",
            "Content-Type": "application/x-www-form-urlencoded",
          },
          data: new URLSearchParams(oldData).toString(),
        },
      );
      check(
        stale.status() === 422,
        "stale team save is rejected by actual controller " + width,
      );
    }
  }
  const sendForm = page
    .locator("form")
    .filter({ has: page.locator("[name=operation][value=social-reply-save]") });
  const unconfirmed = await sendForm.evaluate(e => [
    ...[...new FormData(e)].filter(([name]) => !["operation","reply_confirm"].includes(name)),
    ["operation","social-reply-send"],
  ]);
  const denied = await context.request.post(
    origin + "/vereinsverwaltung/?view=social&section=replies",
    {
      headers: {
        Accept: "application/json",
        "Content-Type": "application/x-www-form-urlencoded",
      },
      data: new URLSearchParams(unconfirmed).toString(),
    },
  );
  check(
    denied.status() === 422,
    "controller rejects unconfirmed send without provider action",
  );
  await page.reload();
  check(
    (await page.locator(".social-reply-preview .badge").innerText()).includes(
      "nicht versendet",
    ),
    "rejected send preserves private draft after real reload",
  );
  const memberContext = await browser.newContext();
  await memberContext.route("**/*", (r) =>
    new URL(r.request().url()).origin === origin ? r.continue() : r.abort(),
  );
  const member = await memberContext.newPage();
  await login(member, "member");
  check(
    (
      await member.goto(
        origin +
          "/vereinsverwaltung/?view=social&section=replies&reply=" +
          draftId,
      )
    ).status() === 403,
    "ordinary member cannot read private original or reply history",
  );
  check(!errors.length, "reply lifecycle has no browser script errors");
  check(
    !external.length,
    "reply UI loads no external assets or provider endpoints",
  );
  console.log(
    n +
      " native social reply browser checks passed; real encrypted draft forms and no live send.",
  );
} finally {
  await browser.close();
}
