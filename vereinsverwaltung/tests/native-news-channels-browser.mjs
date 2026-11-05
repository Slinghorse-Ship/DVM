import assert from 'node:assert/strict';
import {readFile, writeFile, mkdir} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';

const {chromium} = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = fileURLToPath(new URL('../../', import.meta.url));
const ownFixture=process.env.DVM_EDITORIAL_WORKFLOW_FIXTURE?JSON.parse(await readFile(process.env.DVM_EDITORIAL_WORKFLOW_FIXTURE,'utf8')):null;
const origin = ownFixture?.origin??'http://127.0.0.1:8111';
const accounts = ownFixture?.accounts??JSON.parse(await readFile(root + '.local/native-cms-integration/integrated-manager-runtime/browser-credentials.json', 'utf8'));
const originals = ownFixture?.data.items??JSON.parse(await readFile(root + '.local/browser-tests/news-channels-fixture.json', 'utf8'));
const browser = await chromium.launch({executablePath: process.env.CHROMIUM_EXECUTABLE || '/usr/bin/chromium'});
const checks = [];
try {
  const context = await browser.newContext();
  await context.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
  const page = await context.newPage(), errors = [];
  page.on('pageerror', error => errors.push(error.message));
  const login = async (target, account) => {
    if(ownFixture){await target.goto(origin+'/own-test-enter?role='+account.role);return;}
    await target.goto(origin + '/login/');
    await target.getByLabel('E-Mail-Adresse oder persönlicher Benutzername', {exact: true}).fill(account.email);
    await target.getByLabel('Passwort', {exact: true}).fill(account.password);
    await Promise.all([target.waitForURL('**/vereinsverwaltung/**'), target.getByRole('button', {name: 'Anmelden und Vereinsmanager öffnen'}).click()]);
  };
  await login(page, accounts.admin);
  for (const item of originals) {
    await page.setViewportSize({width: item.width, height: 1050});
    const url = origin + '/vereinsverwaltung/?view=website&tab=news&native_item=' + item.id;
    assert.equal((await page.goto(url)).status(), 200);
    const form = () => page.locator('form').filter({has: page.locator('input[name=operation][value=article-save]')});
    const version = () => form().locator('input[name=version]').inputValue().then(Number);
    let saved = await version();
    const originalVersion = saved;
    const reviewPanel = () => page.getByRole('region', {name: 'Redaktionelle Prüfung'});
    const oldReviewForm = await reviewPanel().locator('form').filter({has: page.locator('input[name=review_status][value=review]')}).evaluate(element => [...new FormData(element).entries()]);
    await reviewPanel().getByRole('button', {name: 'Zur Prüfung geben', exact: true}).focus();
    assert(await reviewPanel().getByRole('button', {name: 'Zur Prüfung geben', exact: true}).evaluate(element => element === document.activeElement));
    await reviewPanel().getByRole('button', {name: 'Zur Prüfung geben', exact: true}).press('Enter');
    await page.waitForFunction(() => document.querySelector('[data-news-review=review]'));
    assert.equal(await version(), originalVersion + 1);
    const staleReview = await context.request.post(url, {headers: {Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded'}, data: new URLSearchParams(oldReviewForm).toString()});
    assert.equal(staleReview.status(), 422);
    await page.reload();
    await reviewPanel().getByRole('button', {name: 'Redaktionell freigeben', exact: true}).click();
    await page.waitForFunction(() => document.querySelector('[data-news-review=approved]'));
    assert.equal(await version(), originalVersion + 2);
    const publicBeforeEdit = await context.request.get(origin + '/' + item.slug + '/');
    assert.equal(publicBeforeEdit.status(), 200);
    assert(!(await publicBeforeEdit.text()).includes('Redaktionsstand:'));
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 2));
    saved = await version();
    const group = form().getByRole('group', {name: 'Veröffentlichungskanäle'});
    assert(await group.getByLabel('Website', {exact: true}).isChecked());
    assert(await group.getByLabel('RSS-Feed', {exact: true}).isChecked());
    const stale = await form().evaluate(element => [...new FormData(element).entries()]);
    await group.getByLabel('Website', {exact: true}).focus();
    assert(await group.getByLabel('Website', {exact: true}).evaluate(element => element === document.activeElement));
    await group.getByLabel('Website', {exact: true}).press('Space');
    assert.equal(await group.getByLabel('Website', {exact: true}).isChecked(), false);
    await form().getByRole('button', {name: 'Entwurf speichern', exact: true}).click();
    await page.waitForFunction(expected => Number(document.querySelector('form input[name=operation][value=article-save]')?.form.querySelector('input[name=version]')?.value) === expected, saved + 1);
    assert.equal(new URL(page.url()).searchParams.get('tab'), 'news');
    assert.equal(await reviewPanel().locator('[data-news-review]').getAttribute('data-news-review'), 'draft');
    assert.equal((await context.request.get(origin + '/' + item.slug + '/')).status(), 200, 'channel edit stays private until publication');
    const rejected = await context.request.post(url, {headers: {Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded'}, data: new URLSearchParams(stale).toString()});
    assert.equal(rejected.status(), 422);
    await page.reload();
    assert.equal(await version(), saved + 1);
    assert.equal(await form().getByLabel('Website', {exact: true}).isChecked(), false);
    assert(await form().getByLabel('RSS-Feed', {exact: true}).isChecked());
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 2));
    await page.getByRole('button', {name: 'Beitrag veröffentlichen', exact: true}).click();
    await page.waitForFunction(expected => Number(document.querySelector('form input[name=operation][value=article-save]')?.form.querySelector('input[name=version]')?.value) === expected, saved + 2);
    assert((await page.locator('body').innerText()).includes('Veröffentlicht · RSS-Feed'));
    assert.equal(await page.getByRole('link', {name: 'Veröffentlichten Beitrag ansehen ↗'}).count(), 0);
    for (const path of [item.slug, 'site/' + item.slug, item.alias]) {
      const response = await context.request.get(origin + '/' + path + '/');
      assert.equal(response.status(), 404);
      assert(!(await response.text()).includes(item.title));
    }
    let feed = await context.request.get(origin + '/feeds/news/');
    assert.equal(feed.status(), 200); assert.match(feed.headers()['content-type'], /application\/rss\+xml/);
    assert((await feed.text()).includes(item.title));
    const etag = feed.headers().etag; assert(etag);
    const cached = await context.request.get(origin + '/feeds/news/', {headers: {'If-None-Match': etag}});
    assert.equal(cached.status(), 304); assert.equal((await cached.body()).length, 0);
    const rssHead = await context.request.head(origin + '/feeds/news/');
    assert.equal(rssHead.status(), 200); assert.equal((await rssHead.body()).length, 0);
    await form().getByLabel('RSS-Feed', {exact: true}).uncheck();
    await form().getByRole('button', {name: 'Entwurf speichern', exact: true}).click();
    await page.waitForFunction(expected => Number(document.querySelector('form input[name=operation][value=article-save]')?.form.querySelector('input[name=version]')?.value) === expected, saved + 3);
    assert((await (await context.request.get(origin + '/feeds/news/')).text()).includes(item.title));
    await page.getByRole('button', {name: 'Beitrag veröffentlichen', exact: true}).click();
    await page.waitForFunction(expected => Number(document.querySelector('form input[name=operation][value=article-save]')?.form.querySelector('input[name=version]')?.value) === expected, saved + 4);
    assert((await page.locator('body').innerText()).includes('Freigegeben · kein öffentlicher Kanal'));
    feed = await context.request.get(origin + '/feeds/news/');
    assert(!(await feed.text()).includes(item.title));
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 2));
    assert(!/Fatal error:|Warning:|Notice:|Parse error:/.test(await page.locator('body').innerText()));
    checks.push({width: item.width, review_and_approval: true, stale_review_rejected: true, approval_resets_after_edit: true, saved_rss_only: true, stale_rejected: true, original_private_until_publish: true, public_urls_hidden: true, feed_etag: true, no_channel_withdrawal: true, keyboard: true});
  }
  const memberContext = await browser.newContext();
  await memberContext.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
  const member = await memberContext.newPage();
  await login(member, accounts.member);
  assert.equal((await member.goto(origin + '/vereinsverwaltung/?view=website&tab=news&native_item=' + originals[0].id)).status(), 403);
  assert.equal(await member.locator('input[name=news_channels_present]').count(), 0);
  assert.deepEqual(errors, []);
  if(!ownFixture){
  await mkdir(root + '.local/browser-tests', {recursive: true, mode: 0o700});
  await writeFile(root + '.local/browser-tests/news-channels-result.json', JSON.stringify({checks, member_denied: true, external_transport: false}, null, 2), {mode: 0o600});
  }else console.log(JSON.stringify({browser_cases:checks,member_denied:true,external_actions:0}));
  console.log('Native news channels and review browser passed: original-bound review, private approval and edit reset, RSS-only choices, original website withdrawal, protected historical links, conditional RSS/HEAD, stale form rejection, both-channel withdrawal, keyboard and member denial at three widths.');
} finally {
  await browser.close();
}
