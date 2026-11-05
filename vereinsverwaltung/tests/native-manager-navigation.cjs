const {chromium}=require('C:/Users/wehla/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const origin='http://127.0.0.1:8111',output=path.resolve(__dirname,'../../.local/native-cms-integration/integrated-manager-runtime');
const credentials=JSON.parse(fs.readFileSync(path.join(output,'browser-credentials.json'),'utf8'));
const checks=[],check=(ok,label)=>{assert.ok(ok,label);checks.push(label)};
(async()=>{const browser=await chromium.launch({headless:true}),context=await browser.newContext({viewport:{width:1440,height:1000}}),page=await context.newPage(),errors=[],external=[];
await context.route('**/*',route=>{if(route.request().url().startsWith(origin+'/'))return route.continue();external.push(route.request().url());return route.abort()});page.on('pageerror',error=>errors.push(error.message));
const login=async key=>{await page.goto(origin+'/login/');await page.getByLabel('E-Mail-Adresse oder persönlicher Benutzername',{exact:true}).fill(credentials[key].email);await page.getByLabel('Passwort',{exact:true}).fill(credentials[key].password);await Promise.all([page.waitForURL('**/vereinsverwaltung/**'),page.getByRole('button',{name:'Anmelden und Vereinsmanager öffnen'}).click()]);};
try{
await login('admin');await page.goto(origin+'/vereinsverwaltung/?view=website&tab=pages');await page.locator('[data-native-status]').waitFor({state:'attached'});await page.evaluate(()=>window.integrationNavigationMarker='same-document');
const tabs=()=>page.getByRole('navigation',{name:'Website verwalten'});
check(await tabs().getByRole('link').count()===7,'administrator sees seven website destinations');
for(const [label,view] of [['Aktuelles','news'],['Fotogalerie','media'],['Felder & Vorlagen','model'],['Seiten','website']]){
 await tabs().getByRole('link',{name:label,exact:true}).click();await page.locator('.native-manager-embedded[data-view="'+view+'"]').waitFor();
 check(await tabs().getByRole('link',{name:label,exact:true}).getAttribute('aria-current')==='page','visible website tab opens '+view);
}
check(await page.evaluate(()=>window.integrationNavigationMarker)==='same-document','website destinations keep original partial-navigation lifecycle');
await tabs().getByRole('link',{name:'Aktuelles',exact:true}).click();await page.locator('a.news-admin-item').filter({has:page.getByRole('heading',{name:'Was uns bewegt',exact:true})}).click();await page.getByLabel('Titel',{exact:true}).waitFor();
check(await page.getByLabel('Titel',{exact:true}).inputValue()==='Was uns bewegt','known seed article is reachable from visible manager navigation');
await page.screenshot({path:path.join(output,'manager-news-seed-desktop.png'),fullPage:true});
await page.setViewportSize({width:390,height:844});check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'website tabs fit mobile viewport');await page.screenshot({path:path.join(output,'manager-news-seed-mobile.png'),fullPage:true});
await context.clearCookies();await login('editor');await page.goto(origin+'/vereinsverwaltung/?view=website&tab=pages');check(await tabs().getByRole('link').count()===6&&await tabs().getByRole('link',{name:'Felder & Vorlagen'}).count()===0,'editor sees content destinations without technical schema administration');
check((await context.request.get(origin+'/vereinsverwaltung/?view=website&tab=technical')).status()===403,'hidden technical destination is also denied server side');
check(external.length===0&&errors.length===0,'no external requests or JavaScript exceptions');
const result={passed:checks.length,checks,external:external.length,errors};fs.writeFileSync(path.join(output,'navigation-result.json'),JSON.stringify(result,null,2));console.log(JSON.stringify(result,null,2));
}catch(error){console.error(error.message);process.exitCode=1}finally{await browser.close()}})();
