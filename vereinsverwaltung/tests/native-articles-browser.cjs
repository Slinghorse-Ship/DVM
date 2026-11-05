/* Read-only acceptance of the integrated synthetic news/site views. Service mutations use model.json. */
const {chromium} = require(process.env.KH_PLAYWRIGHT_MODULE || 'C:/Users/wehla/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const origin = process.env.KH_NATIVE_BROWSER_ORIGIN || 'http://127.0.0.1:8109';
assert.match(origin, /^http:\/\/127\.0\.0\.1:81\d{2}$/);
assert.ok(!['http://127.0.0.1:8107','http://127.0.0.1:8108'].includes(origin), 'Use the separately integrated news fixture');
const output = path.resolve(__dirname, '../../.local/native-cms-integration');
const checks = [], errors = [], external = [], failed = [], mutations = [];
const check = (ok, label) => { assert.ok(ok, label); checks.push(label); };
(async () => {
  const browser = await chromium.launch({headless:true});
  const context = await browser.newContext({viewport:{width:1440,height:1000}});
  const page = await context.newPage();
  page.on('pageerror', error => errors.push(error.message));
  page.on('response', response => { if(response.status() >= 400) failed.push({url:response.url(),status:response.status()}); });
  await context.route('**/*', route => {
    const request=route.request();
    if(request.method() !== 'GET' && request.method() !== 'HEAD') { mutations.push(request.method()+' '+request.url()); return route.abort(); }
    if(!request.url().startsWith(origin+'/')) { external.push(request.url()); return route.abort(); }
    return route.continue();
  });
  const visit = async route => { const response=await page.goto(origin+route); assert.equal(response.status(),200,route); await page.waitForLoadState('networkidle'); };
  const screenshot = async name => {
    // Prepare complete full-page evidence; lazy images below the viewport otherwise stay blank.
    await page.locator('img[loading="lazy"]').evaluateAll(images=>images.forEach(image=>{image.loading='eager';}));
    await page.waitForFunction(()=>Array.from(document.images).every(image=>image.complete),null,{timeout:10000});
    await page.screenshot({path:path.join(output,name),fullPage:true});
  };
  try {
    await visit('/site/start');
    check(await page.locator('.hero--home .hero__poster').count()===1,'home has Kulturhus LIVE poster');
    check(await page.getByRole('heading',{name:'Was uns bewegt',exact:true}).count()===1,'home Was uns bewegt section');
    check(await page.locator('.section--news .news-card').count()===3,'home shows three published articles');
    const homeArticles=await page.locator('.section--news .news-card h2 a').evaluateAll(links=>links.map(link=>({title:link.textContent,path:link.getAttribute('href')})));
    check(await page.locator('.culture-strip').count()===1 && await page.locator('#termine .event-feature').count()>=1,'home culture strip and published next event');
    check(await page.locator('#nav a').allTextContents().then(labels=>!labels.includes('Start')&&labels.includes('Aktuelles')&&labels.includes('Mitglied werden')&&labels.includes('Kontakt')),'original navigation sections without duplicate Start');
    check(await page.locator('link[href="/assets/kulturhus-site.css"]').count()===1 && await page.locator('link[href="/assets/native.css"]').count()===0,'public site uses shared Kulturhus assets');
    await screenshot('news-home-desktop.png');
    await visit('/site/aktuelles');
    check(await page.locator('h1').innerText()==='Aktuelles','news index title');
    check(await page.locator('.page-hero--stories').count()===1,'news index uses original stories hero');
    check(await page.locator('.news-card').count()===3,'news index includes synthetic articles');
    const indexArticles=await page.locator('.news-card h2 a').evaluateAll(links=>links.map(link=>({title:link.textContent,path:link.getAttribute('href')})));
    check(JSON.stringify(homeArticles)===JSON.stringify(indexArticles.slice(0,3)),'home teasers and index use the same published records and order');
    check(await page.locator('.news-card time[datetime]').count()===3,'news semantic editorial dates');
    check(await page.locator('.news-picture img').evaluateAll(images=>images.every(image=>image.complete&&image.naturalWidth>0)),'published cover images resolve');
    check(await page.locator('#nav a[aria-current="page"]').innerText()==='Aktuelles','news index active navigation');
    const first=page.locator('.news-card h2 a').first();
    const articleTitle=await first.innerText();
    const articlePath=await first.getAttribute('href');
    await screenshot('news-index-desktop.png');
    await first.click(); await page.waitForLoadState('networkidle');
    check(await page.locator('h1').count()===1 && await page.locator('h1').innerText()===articleTitle,'article distinct route and single title');
    check(await page.locator('.page-hero--stories time').count()===1,'article publication date in header');
    check(await page.locator('.news-article .kh-richtext strong').count()>=1,'article rich text preserved');
    check(await page.locator('.news-article .news-picture img').evaluateAll(images=>images.length>0&&images.every(image=>image.complete&&image.naturalWidth>0)),'article cover resolves');
    check(await page.locator('#nav a[aria-current="true"]').innerText()==='Aktuelles','article retains parent navigation state');
    check(await page.getByRole('link',{name:'← Alle Beiträge',exact:true}).count()>=1,'article back navigation');
    check(await page.locator('link[rel="canonical"]').getAttribute('href')===origin+articlePath,'article canonical route');
    await screenshot('news-article-desktop.png');
    await visit('/?view=news');
    check(await page.locator('.news-admin-item').count()>=3,'manager lists articles separately');
    await page.getByRole('link',{name:'+ Neuer Beitrag',exact:true}).click();
    await page.locator('[data-richtext-surface][contenteditable=true]').waitFor();
    check(await page.getByLabel('Beitragsdatum',{exact:true}).count()===1,'editor date control');
    check(await page.getByLabel('Titel',{exact:true}).count()===1 && await page.getByLabel('Kurztext für die Übersicht',{exact:true}).count()===1,'editor title and summary');
    check(await page.locator('[data-richtext-surface]').count()===1,'editor mounts existing WYSIWYG');
    check(await page.getByRole('button',{name:'Entwurf speichern',exact:true}).count()===1,'editor versioned draft action');
    await screenshot('news-editor-desktop.png');
    await page.setViewportSize({width:390,height:844});
    check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'mobile editor stays in viewport');
    await screenshot('news-editor-mobile.png');
    await visit('/site/aktuelles');
    check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'mobile news list stays in viewport');
    await page.getByRole('button',{name:'Menü',exact:true}).click();
    check(await page.getByRole('button',{name:'Menü',exact:true}).getAttribute('aria-expanded')==='true' && await page.locator('#nav').isVisible(),'mobile public menu works');
    await page.getByRole('button',{name:'Menü',exact:true}).click();
    await screenshot('news-index-mobile.png');
    await visit(articlePath);
    check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'mobile article stays in viewport');
    await screenshot('news-article-mobile.png');
    await visit('/site/galerie');
    check(await page.locator('.page-hero--gallery').count()===1 && await page.locator('.site-footer').count()===1,'gallery uses shared public shell');
    if(await page.locator('[data-lightbox]').count()) { await page.locator('[data-lightbox]').first().click(); check(await page.locator('#gallery-lightbox').evaluate(dialog=>dialog.open),'gallery lightbox survives shell change'); await page.keyboard.press('Escape'); }
    await screenshot('news-shared-gallery-mobile.png');
    for(const slug of ['kontakt','impressum','datenschutz','satzung']) { await visit('/site/'+slug); check(await page.locator('main').innerText().then(text=>/synthetisch|Testinhalt|Testanker/i.test(text)),slug+' is an explicit synthetic anchor'); }
    check(external.length===0,'no external requests');
    check(errors.length===0,'no JavaScript exceptions');
    check(failed.length===0,'no failed HTTP resources');
    check(mutations.length===0,'read-only browser acceptance made no mutations');
    const result={suite:'native-articles-browser',passed:checks.length,checks,external:external.length,errors,failed,mutations};
    fs.writeFileSync(path.join(output,'articles-browser-result.json'),JSON.stringify(result,null,2)); console.log(JSON.stringify(result,null,2));
  } catch(error) { await page.screenshot({path:path.join(output,'articles-browser-failure.png'),fullPage:true}); console.error(error, {errors,external,failed,mutations}); process.exitCode=1; }
  finally { await browser.close(); }
})();
