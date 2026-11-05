const {chromium}=require('C:/Users/wehla/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const path=require('node:path'),fs=require('node:fs'),assert=require('node:assert/strict');
const origin='http://127.0.0.1:8108',output=path.resolve(__dirname,'../../.local/native-cms-integration');
(async()=>{const browser=await chromium.launch({headless:true}),page=await browser.newPage({viewport:{width:1440,height:1000}}),checks=[];const check=(v,s)=>{assert.ok(v,s);checks.push(s);};try{
await page.goto(origin+'/?view=chat');await page.locator('.chat-room').first().click();await page.locator('[data-chat-compose]').waitFor();
check(await page.locator('.native-chat').evaluate(el=>getComputedStyle(el).gridTemplateColumns.split(' ').length===2),'desktop uses two columns');
check(await page.locator('.chat-conversation .chat-details').count()===1,'participants and settings belong to conversation');
check(await page.locator('.chat-message time').first().innerText().then(s=>/^\d{2}:\d{2}$/.test(s)),'message time uses local hours and minutes');
check(!(await page.locator('.chat-message').first().innerText()).includes('Version 1'),'technical version removed from normal message display');
await page.getByRole('link',{name:'+ Neue Unterhaltung',exact:true}).click();check(await page.locator('#chat-create').evaluate(el=>el.open),'create shortcut opens conversation form');
await page.getByRole('link',{name:'Teilnehmer ansehen',exact:true}).click();check(await page.locator('#chat-people').evaluate(el=>el.open),'participants shortcut opens details');
await page.locator('#chat-create summary').click();await page.locator('#chat-people summary').click();
await page.screenshot({path:path.join(output,'native-chat-desktop.png'),fullPage:true});
await page.setViewportSize({width:390,height:844});check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'chat fits mobile width');await page.screenshot({path:path.join(output,'native-chat-mobile.png'),fullPage:true});
fs.writeFileSync(path.join(output,'chat-layout-browser-result.json'),JSON.stringify({passed:checks.length,checks},null,2));console.log(JSON.stringify({passed:checks.length,checks}));
}finally{await browser.close();}})().catch(error=>{console.error(error);process.exitCode=1;});
