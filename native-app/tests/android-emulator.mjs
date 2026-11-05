/* Real installed Android WebView + protected, personally authenticated manager. */
import {readFile,writeFile,mkdir} from 'node:fs/promises';
import {execFile} from 'node:child_process';
import {promisify} from 'node:util';
import assert from 'node:assert/strict';
import {createHash} from 'node:crypto';
const execute=promisify(execFile), fixture=JSON.parse(await readFile(process.env.DVM_EDITORIAL_WORKFLOW_FIXTURE,'utf8'));
if(process.env.DVM_ANDROID_OWNED_EMULATOR!=='synthetic-only'||!/^http:\/\/127\.0\.0\.1:[0-9]+$/.test(fixture.origin))throw new Error('Own synthetic emulator and fixture required.');
const locale=process.env.DVM_ANDROID_UI_LOCALE||'de';if(!['de','en'].includes(locale))throw new Error('A declared app language is required.');
const labels=locale==='en'?{club:'Your club',menu:'App menu',change:'Switch club',address:'Club address',open:'Open',share:'Share'}:{club:'Dein Verein',menu:'App-Menü',change:'Verein wechseln',address:'Vereinsadresse',open:'Öffnen',share:'Teilen'};
const adb=process.env.DVM_ANDROID_ADB,serial=process.env.DVM_ANDROID_SERIAL||'emulator-5554',app='de.deinvereinsmanager.app';
if(!adb)throw new Error('DVM_ANDROID_ADB required.');
async function device(...args){return(await execute(adb,['-s',serial,...args],{timeout:120000,maxBuffer:8*1024*1024})).stdout.trim();}
assert.equal(await device('shell','getprop','ro.boot.qemu.avd_name'),'dvm-api36','only the owned test AVD');
const artifact=process.env.DVM_APP_ARTIFACT_DIR;if(artifact)await mkdir(artifact,{recursive:true});
const delay=ms=>new Promise(resolve=>setTimeout(resolve,ms));let checks=0,browser,forward,activePage;
function check(value,label){assert.ok(value,label);checks++;console.log('PASS Android '+label);}
async function ui(){await device('shell','uiautomator','dump','/sdcard/dvm-owned-ui.xml');return await device('shell','cat','/sdcard/dvm-owned-ui.xml');}
async function clickNative(attribute,value){
 const xml=await ui(),nodes=xml.match(/<node\b[^>]+>/g)||[],node=nodes.find(node=>node.includes(`${attribute}="${value}"`));
 assert.ok(node,'Native element '+attribute+'='+value);const box=/bounds="\[(\d+),(\d+)\]\[(\d+),(\d+)\]"/.exec(node);assert.ok(box);
 await device('shell','input','tap',String(Math.round((+box[1]+ +box[3])/2)),String(Math.round((+box[2]+ +box[4])/2)));
}
try{
 console.log('Android stage: attach owned API 36 emulator');
 await device('shell','cmd','locale','set-app-locales',app,'--user','0','--locales',locale);
 const port=new URL(fixture.origin).port;await device('reverse','tcp:'+port,'tcp:'+port);
 await device('shell','am','start','-W','-n',app+'/.MainActivity');
 // Reuse this app's own previous local fixture selection without clearing device data.
 let xml=await ui();if(xml.includes("Process system isn&apos;t responding")||xml.includes("Process system isn't responding")||xml.includes("System UI isn't responding")||xml.includes("System UI isn&apos;t responding")){console.log('Android emulator system was unresponsive; wait for actual app, no success claim.');await clickNative('text','Wait');await delay(3000);xml=await ui();}
 console.log('Android stage: configure actual native server chooser');
 if(!xml.includes('text="'+labels.club+'"')){await clickNative('content-desc',labels.menu);await clickNative('text',labels.change);}
 await clickNative('content-desc',labels.address);await device('shell','input','keyevent','KEYCODE_MOVE_END');await device('shell','input','keyevent','--longpress','KEYCODE_DEL');
 // Selecting all uses the native keyboard shortcut rather than guessing existing URL length.
 await device('shell','input','keycombination','113','29');await device('shell','input','text',fixture.origin);await clickNative('text',labels.open);
 console.log('Android stage: connect actual WebView');
 const pid=await device('shell','pidof',app);assert.match(pid,/^[0-9]+$/);
 forward=await device('forward','tcp:0','localabstract:webview_devtools_remote_'+pid);
 const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
 for(let attempt=0;attempt<60;attempt++){
  try{browser=await chromium.connectOverCDP('http://127.0.0.1:'+forward,{timeout:3000});break;}catch(error){if(attempt===59)throw error;await delay(500);}
 }
 const context=browser.contexts()[0],page=context.pages()[0];assert.ok(page);activePage=page;
 page.setDefaultTimeout(120000);const errors=[];page.on('pageerror',error=>errors.push(error.message));
 await page.goto(fixture.origin+'/own-test-enter?role=member',{waitUntil:'domcontentloaded',timeout:120000});
 await page.goto(fixture.origin+'/vereinsverwaltung/?view=dashboard',{waitUntil:'domcontentloaded',timeout:120000});
 await page.waitForFunction(()=>window.DVMNative?.platform==='android',null,{timeout:120000});
 check((await page.title()).length>0,'protected member dashboard renders in the installed APK');
 check(await page.evaluate(()=>window.DVMNative.version===1&&!window.DVMNative.payments),'native connection available without claiming a configured payment SDK');
 check(await page.evaluate(()=>typeof Notification.requestPermission==='function'&&typeof window.print==='function'),'native notification and print integration present');
 check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'phone viewport fits without horizontal page scroll');
 if(artifact)await page.screenshot({path:artifact+'/android-webview-phone.png',animations:'disabled'});
 check((await ui()).includes('content-desc="'+labels.menu+'"'),'actual native toolbar follows the selected '+locale+' app language');
 const before=await page.evaluate(()=>{window.dvmOriginalAppDocument=crypto.randomUUID();return window.dvmOriginalAppDocument;});
 const other=locale==='de'?'en':'de';await device('shell','cmd','locale','set-app-locales',app,'--user','0','--locales',other);await delay(1000);check(await page.evaluate(()=>window.dvmOriginalAppDocument)===before,'app language change retains the actual document and original inputs');await device('shell','cmd','locale','set-app-locales',app,'--user','0','--locales',locale);await delay(500);
 await device('shell','settings','put','system','accelerometer_rotation','0');await device('shell','settings','put','system','user_rotation','1');
 await page.waitForFunction(()=>innerWidth>innerHeight,null,{timeout:90000});check(await page.evaluate(()=>window.dvmOriginalAppDocument)===before,'rotation retains the actual document and its input state');
 await device('shell','wm','size','1600x2560');await device('shell','wm','density','240');await device('shell','settings','put','system','user_rotation','0');
 await page.waitForFunction(()=>innerWidth>=700,null,{timeout:90000});check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'tablet view fits');
 await device('shell','settings','put','system','font_scale','2.0');await delay(1500);check(await page.evaluate(()=>window.dvmOriginalAppDocument)===before,'double system font size retains the document');
 if(artifact)await page.screenshot({path:artifact+'/android-webview-tablet.png',animations:'disabled'});
 const bytes='DVM original export ä🙂\n'+('original-content\n'.repeat(6000));
 const expected=createHash('sha256').update(bytes).digest('hex');
 await page.evaluate(async bytes=>{await window.DVMNative.save(new Blob([bytes],{type:'text/plain'}),'DVM-original.txt');},bytes);
 const files=await device('shell','run-as',app,'find','cache/shared','-name','DVM-original.txt');assert.ok(files);
 const path=files.split('\n').at(-1),actual=(await execute(adb,['-s',serial,'shell','run-as',app,'cat',path],{encoding:'buffer',timeout:30000,maxBuffer:2*1024*1024})).stdout;
 check(createHash('sha256').update(actual).digest('hex')===expected,'multi-chunk native export preserves every original UTF-8 byte');
 check((await ui()).includes('DVM-original.txt'),'native export chooser names the actual original file');await clickNative('text',labels.share);check((await ui()).includes('DVM-original.txt'),'OS sharing sheet opens for the actual exported file');await device('shell','input','keyevent','KEYCODE_BACK');
 check(errors.length===0,'actual manager and native bridge report no JavaScript errors');
 const result={suite:'android-app-emulator',checks,original_export_sha256:expected,device:'owned AOSP API 36 emulator',ui_locale:locale,physical_device:false,live_transport:false};
 await writeFile(fixture.root+'/android-app-result.json',JSON.stringify(result,null,2)+'\n');if(artifact)await writeFile(artifact+'/android-app-result.json',JSON.stringify(result,null,2)+'\n');console.log(JSON.stringify(result));
}catch(error){
 if(artifact){await writeFile(artifact+'/android-app-failure.json',JSON.stringify({error:String(error.stack||error),checks,physical_device:false,live_transport:false},null,2)+'\n');if(activePage&&!activePage.isClosed())await activePage.screenshot({path:artifact+'/android-webview-failure.png',timeout:5000}).catch(()=>{});}
 throw error;
}finally{
 if(browser)await browser.close();
 for(const args of [['shell','settings','put','system','font_scale','1.0'],['shell','settings','put','system','user_rotation','0'],['shell','wm','size','reset'],['shell','wm','density','reset'],['shell','rm','-f','/sdcard/dvm-owned-ui.xml']])await device(...args).catch(()=>{});
 if(forward)await device('forward','--remove','tcp:'+forward).catch(()=>{});
 await device('reverse','--remove','tcp:'+new URL(fixture.origin).port).catch(()=>{});
}
