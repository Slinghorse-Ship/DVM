import assert from 'node:assert/strict';
import {readFile, writeFile} from 'node:fs/promises';
import {spawnSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
const {chromium} = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = fileURLToPath(new URL('../../', import.meta.url)), origin = 'http://127.0.0.1:8111';
const fixture = JSON.parse(await readFile(root + '.local/browser-tests/personal-replies.json', 'utf8'));
const credentials = JSON.parse(await readFile(root + '.local/native-cms-integration/integrated-manager-runtime/browser-credentials.json', 'utf8'));
const browser = await chromium.launch({executablePath: process.env.CHROMIUM_EXECUTABLE || '/usr/bin/chromium', args: ['--no-sandbox']});
let checks = 0, draftId;
const errors = [], external = [], writes = [];
const check = (ok, label) => {assert(ok, label); ++checks; console.log('PASS ' + label);};
async function client(role, javaScriptEnabled = true, state = null) {
  let storageState = state;
  if (!storageState && process.env.PERSONAL_REPLIES_STORAGE_DIR) storageState = JSON.parse(await readFile(process.env.PERSONAL_REPLIES_STORAGE_DIR + '/' + role + '.json', 'utf8'));
  const context = await browser.newContext({storageState: storageState || undefined, hasTouch: true, javaScriptEnabled});
  await context.route('**/*', route => {
    const req = route.request();
    if (new URL(req.url()).origin !== origin) {external.push(req.url()); return route.abort();}
    if (req.method() === 'POST' && new URL(req.url()).pathname !== '/login/') writes.push(new URLSearchParams(req.postData() || '').get('operation'));
    return route.continue();
  });
  const page = await context.newPage();
  page.on('pageerror', error => errors.push(error.message));
  if (!storageState) {
    await page.goto(origin + '/login/');
    await page.locator('[name=email]').fill(credentials[role].email);
    await page.locator('[name=password]').fill(credentials[role].password);
    await Promise.all([page.waitForURL('**/vereinsverwaltung/**'), page.getByRole('button', {name: 'Anmelden und Vereinsmanager öffnen', exact: true}).click()]);
  }
  return {context, page};
}
async function audit(page, label) {
  const problems = await page.evaluate(() => {
    const visible = node => node.checkVisibility() && !node.closest('[hidden]');
    return {
      overflow: document.documentElement.scrollWidth > innerWidth + 2,
      buttons: [...document.querySelectorAll('.native-manager-embedded button, .native-manager-embedded a.btn')].filter(visible).filter(node => {const r=node.getBoundingClientRect();return r.width<43.5||r.height<43.5;}).map(node=>node.textContent.trim()),
      fields: [...document.querySelectorAll('.social-reply-layout :is(input,textarea)')].filter(node=>node.type!=='hidden').filter(node=>!node.classList.contains(node.type==='checkbox'?'check':'form-control')||!node.labels?.length).map(node=>node.name),
    };
  });
  check(!problems.overflow, label + ' fits actual viewport');
  check(!problems.buttons.length, label + ' has actual 44-pixel touch targets ' + problems.buttons.join(','));
  check(!problems.fields.length, label + ' uses labeled Bootstrap controls ' + problems.fields.join(','));
}
try {
  const admin = await client('admin');
  for (const width of [1440, 820, 390]) {
    await admin.page.setViewportSize({width, height:1050});
    await admin.page.goto(origin + '/vereinsverwaltung/?view=social&section=review&state=all');
    if (width===390) await admin.page.locator('.social-chat-sidebar').waitFor({state:'visible'});
    const original=admin.page.locator('.chat-choice[href*="attention='+fixture.attention+'"]');
    check(await original.count()===1,'actual personal original appears once '+width);
    await original.tap();
    await admin.page.locator('[name=attention_id][value="'+fixture.attention+'"]').first().waitFor({state:'attached'});
    await audit(admin.page,'personal reply '+width);
    check((await admin.page.locator('.chat-person small').innerText()).includes('StadtLand.Funk'),'provider label belongs to personal source '+width);
    check(await admin.page.getByRole('link',{name:'Original ↗',exact:true}).getAttribute('href')==='https://www.stadtlandfunk.app/post-detail?id=own_personal_reply_browser','original provider link retained '+width);
    const save = admin.page.locator('form').filter({has:admin.page.locator('[name=operation][value=social-reply-save]')});
    const version = Number(await save.locator('[name=reply_version]').inputValue());
    const text='Eigene gespeicherte persönliche Antwort <script>kein Code</script> '+width;
    await save.locator('[name=reply_text]').fill(text);
    await save.locator('[name=reply_text]').focus();
    await admin.page.keyboard.press('Tab');
    check(await save.getByRole('button',{name:'Entwurf speichern',exact:true}).evaluate(node=>document.activeElement===node),'keyboard reaches actual save action '+width);
    await save.getByRole('button',{name:'Entwurf speichern',exact:true}).tap();
    await admin.page.waitForFunction(expected=>Number(document.querySelector('[name=operation][value=social-reply-save]')?.form.querySelector('[name=reply_version]')?.value)===expected,version+1);
    draftId=new URL(admin.page.url()).searchParams.get('reply');
    check(!!draftId && await admin.page.locator('.social-reply-preview .social-caption').innerText()===text,'exact private personal answer saved '+width);
    check((await admin.page.locator('.social-reply-preview .badge').innerText()).includes('nicht versendet'),'draft never claims live provider success '+width);
    check(!(await admin.page.locator('[name=reply_confirm]').isChecked())&&await admin.page.locator('[name=reply_confirm]').evaluate(node=>node.required),'actual send requires unchecked deliberate confirmation '+width);
    check(await admin.page.locator('.social-reply-layout script').count()===0,'private answer and original stay escaped '+width);
    await admin.page.screenshot({path:root+'.local/browser-tests/personal-reply-'+width+'.png',fullPage:false});
  }
  await admin.page.evaluate(()=>document.documentElement.style.zoom='2');
  await audit(admin.page,'personal reply 200 percent zoom');
  const noJs=await client('admin',false,await admin.context.storageState());
  await noJs.page.setViewportSize({width:390,height:1050});
  await noJs.page.goto(origin+'/vereinsverwaltung/?view=social&section=replies&reply='+draftId);
  await audit(noJs.page,'actual personal draft without JavaScript');
  const save=noJs.page.locator('form').filter({has:noJs.page.locator('[name=operation][value=social-reply-save]')});
  const old=await save.evaluate(node=>[...new FormData(node).entries()]);
  await save.locator('[name=reply_text]').fill('Eigene geprüfte Antwort ohne JavaScript');
  await Promise.all([noJs.page.waitForNavigation(),save.getByRole('button',{name:'Entwurf speichern',exact:true}).tap()]);
  check(await noJs.page.locator('.social-reply-preview .social-caption').innerText()==='Eigene geprüfte Antwort ohne JavaScript','actual no-JavaScript POST stores and displays exact personal draft');
  const stale=await noJs.context.request.post(origin+'/vereinsverwaltung/?view=social&section=replies',{headers:{Accept:'application/json'},form:Object.fromEntries(old)});
  check(stale.status()===422,'actual stale personal draft form rejected');
  const send=noJs.page.locator('form').filter({has:noJs.page.locator('[name=operation][value=social-reply-send]')});
  const unconfirmed=await send.evaluate(node=>Object.fromEntries(new FormData(node)));
  check((await noJs.context.request.post(origin+'/vereinsverwaltung/?view=social&section=replies',{headers:{Accept:'application/json'},form:unconfirmed})).status()===422,'actual unconfirmed personal send stops before provider');
  await noJs.page.reload();
  check((await noJs.page.locator('.social-reply-preview .badge').innerText()).includes('nicht versendet'),'rejected controller actions retain current private draft');
  const member=await client('member');
  check((await member.page.goto(origin+'/vereinsverwaltung/?view=social&section=replies&reply='+draftId)).status()===403,'actual member cannot read private original or personal reply');
  check(writes.length===4&&writes.every(operation=>operation==='social-reply-save'),'browser performs only four private draft saves');
  check(!errors.length&&!external.length,'no script errors or external provider/browser actions');
  const proof=spawnSync(process.env.PHP_BINARY||'php',[root+'vereinsverwaltung/tests/support/personal-replies-browser-fixture.php','check'],{cwd:root,env:{...process.env,KH_NATIVE_TEST_CONFIG:root+'.local/native-cms-integration/integrated-manager.json'},encoding:'utf8'});
  check(proof.status===0,'actual original data, account binding, approval bytes and other drafts unchanged');
  await writeFile(root+'.local/browser-tests/personal-replies-result.json',JSON.stringify({checks,private_saves:4,rejected_stale:1,rejected_unconfirmed:1,provider_writes:0,external_actions:0})+'\n',{mode:0o600});
  console.log(JSON.stringify({checks,private_saves:4,provider_writes:0}));
} finally {await browser.close();}
