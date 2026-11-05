/* Synthetic browser-only form checks; no account, mail or bank network call. */
const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
(async()=>{const root=path.resolve(__dirname,'../..'),php=process.env.PHP_BINARY||path.join(root,'.local/php-8.5.10/php.exe');
const registry=JSON.parse(cp.execFileSync(php,['-r',`require '${root.replaceAll('\\','/')}/vereinsverwaltung/src/Finance/Banking/Iban.php';echo json_encode(['lengths'=>Kulturhus\\Verein\\Iban::LENGTHS,'patterns'=>Kulturhus\\Verein\\Iban::PATTERNS]);`],{encoding:'utf8'}));
const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE}:process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});try{const page=await browser.newPage();await page.route('**/*',route=>route.abort());
await page.setContent('<meta name="iban-country-lengths"><meta name="iban-country-patterns"><form id="member"><label>IBAN<input name="data[iban]" required></label><button>Speichern</button></form><form id="settings"><label>IBAN<input name="data[iban]" value="OLD-UNCHANGED"></label><button>Speichern</button></form>');
await page.locator('meta[name=iban-country-lengths]').evaluate((el,data)=>el.content=JSON.stringify(data),registry.lengths);
await page.locator('meta[name=iban-country-patterns]').evaluate((el,data)=>el.content=JSON.stringify(data),registry.patterns);
await page.addStyleTag({content:fs.readFileSync(path.join(root,'vereinsverwaltung/public/form-validation.css'),'utf8')});
for(const file of ['form-validation.js','iban-validation.js'])await page.addScriptTag({content:fs.readFileSync(path.join(root,'vereinsverwaltung/public',file),'utf8')});
await page.evaluate(()=>document.addEventListener('submit',e=>e.preventDefault()));let n=0;
for(const[country,length]of [['DE',18],['AT',16]]){const bban='A'+'0'.repeat(length-1);let rest=0;for(const char of bban+country+'00'){for(const digit of /[A-Z]/.test(char)?String(char.charCodeAt(0)-55):char)rest=(rest*10+Number(digit))%97;}const bad=country+String(98-rest).padStart(2,'0')+bban;assert.match(await page.evaluate(x=>window.KulturhusIban.inspect(x).error,bad),/nur Ziffern/);n++;}
const field=page.locator('#member input');await field.fill('DE89370400440532013001');assert.equal(await field.getAttribute('aria-invalid'),'true');n++;
assert.match(await field.evaluate(el=>el.validationMessage),/Prüfziffer/);n++;
await field.fill('de89 3704 0044 0532 0130 00');await field.blur();assert.equal(await field.inputValue(),'DE89370400440532013000');assert.equal(await field.getAttribute('aria-invalid'),null);n+=2;
for(const bad of ['DE8937040044053201300','US89370400440532013000','DΕ89370400440532013000']){await field.fill(bad);assert.equal(await field.evaluate(el=>el.checkValidity()),false);n++;}
await page.evaluate(()=>{const form=document.createElement('form');form.id='supplier';form.innerHTML='<label>IBAN optional<input name="iban"></label><button>Firma anlegen</button>';document.body.append(form);});
const dynamic=page.locator('#supplier input');assert.equal(await dynamic.evaluate(el=>el.checkValidity()),true);n++;
await dynamic.fill('DE89370400440532013001');assert.equal(await dynamic.getAttribute('aria-invalid'),'true');n++;
await dynamic.evaluate(el=>{el.value='DE89\u00a03704 0044 0532 0130 00';el.dispatchEvent(new Event('change',{bubbles:true}));});assert.equal(await dynamic.inputValue(),'DE89370400440532013000');assert.equal(await dynamic.evaluate(el=>el.checkValidity()),true);n+=2;
await dynamic.fill('');assert.equal(await dynamic.evaluate(el=>el.checkValidity()),true);n++;
assert.equal(await page.locator('#settings input').getAttribute('aria-invalid'),null);assert.equal(await page.locator('#settings input').evaluate(el=>el.validity.customError),false);n+=2;
await field.fill('');await page.locator('#member button').click();assert.equal(await field.getAttribute('aria-invalid'),'true');n++;
await page.evaluate(()=>{const form=document.createElement('form');form.id='raw-review';form.noValidate=true;form.innerHTML='<label>Original IBAN<input name="data[iban]" data-iban-preserve-input></label><button>Review</button>';document.body.append(form);});
const raw=page.locator('#raw-review input');await raw.fill('de89 3704 0044 0532 0130 00');await raw.blur();assert.equal(await raw.inputValue(),'de89 3704 0044 0532 0130 00','explicit original review must retain entered IBAN case and spaces');n++;
await raw.fill('0');await page.locator('#raw-review button').click();assert.equal(await raw.inputValue(),'0','a source or preview action must retain the complete invalid original IBAN');n++;assert.equal(await raw.evaluate(el=>el.validity.customError),false,'an explicit server review must not acquire another form normalizer or untranslated client error');n++;
console.log(n+' IBAN browser checks passed; synthetic only');
}finally{await browser.close();}})().catch(error=>{console.error(error);process.exitCode=1;});
