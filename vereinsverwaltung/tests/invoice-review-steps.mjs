import fs from 'node:fs';
import {fileURLToPath} from 'node:url';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'/workspace/.setup/browser/node_modules/playwright/index.mjs');
const browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE||'/usr/bin/chromium',headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
let checks=0,external=0;const errors=[];
const check=(ok,label)=>{if(!ok)throw Error(label);checks++;console.log('PASS '+label);};
try{
 const context=await browser.newContext({serviceWorkers:'block'});await context.route('**/*',route=>{external++;return route.abort();});
 const page=await context.newPage();page.on('pageerror',failure=>errors.push(failure.message));
 await page.setContent(`<form data-invoice-review-form>
 <nav><span data-review-data-status></span><span data-review-purpose-status></span><span data-review-payment-status></span></nav>
 <section data-review-step="data"><label>Betrag<input name="amount" required></label></section>
 <section data-review-step="purpose"><label>Steuersatz<select name="classification_vat_rate" required><option value=""></option><option value="0">0</option></select></label></section>
 <section data-review-step="payment"><label>Zahlung<select name="payment_source"><option value="private_advance" selected>Privat bezahlt</option><option value="club">Verein</option></select></label><label>Mitglied<input name="reimbursement_contact" required></label></section>
 <section data-review-check hidden><p data-review-summary></p><ul data-review-issues></ul><p data-review-accounting-note></p></section>
 </form>`);
 await page.addScriptTag({content:`window.KulturhusPage={register:(name,fn)=>fn({frame:requestAnimationFrame,cleanup:()=>{}})};window.KulturhusInvoiceEntryText=(form,source,values={})=>Object.entries(values).reduce((text,[key,value])=>text.replaceAll('{'+key+'}',String(value)),source);`});
 await page.addScriptTag({content:fs.readFileSync(fileURLToPath(new URL('../public/invoice-review-progress.js',import.meta.url)),'utf8')});
 await page.waitForFunction(()=>document.querySelector('[data-review-data-status]').textContent==='1 Pflichtangabe noch prüfen');
 check(await page.locator('[data-review-purpose-status]').innerText()==='Noch auswählen'&&await page.locator('[data-review-payment-status]').innerText()==='Noch auswählen','invoice data, assignment and payment expose their own missing-field state');
 await page.locator('[name=amount]').fill('12,00');await page.waitForFunction(()=>document.querySelector('[data-review-data-status]').textContent==='Angaben eingetragen');
 check(await page.locator('[data-review-payment-status]').innerText()==='Noch auswählen','a missing reimbursement member never makes complete basic invoice data appear incomplete');
 await page.locator('[name=classification_vat_rate]').selectOption('0');await page.locator('[name=reimbursement_contact]').fill('own-member');
 await page.waitForFunction(()=>document.querySelector('[data-review-payment-status]').textContent==='Privat bezahlt');
 check(await page.locator('[data-review-purpose-status]').innerText()==='Ausgewählt'&&await page.locator('[data-review-summary]').innerText()==='Bereit zum Speichern.','completed required fields reveal a coherent assignment and private-payment summary');
 await page.locator('[name=payment_source]').selectOption('club');await page.waitForFunction(()=>document.querySelector('[data-review-payment-status]').textContent==='Vom Verein bezahlt');
 const values=await page.locator('form').evaluate(form=>Object.fromEntries(new FormData(form)));
 check(values.amount==='12,00'&&values.classification_vat_rate==='0'&&values.reimbursement_contact==='own-member'&&values.payment_source==='club','progress updates retain exact invoice, zero VAT and reimbursement input values');
 check(external===0&&errors.length===0,'invoice step summary runs locally without external requests or script failures');
 console.log(JSON.stringify({suite:'invoice-review-steps',checks,external_requests:external,javascript_errors:errors.length}));await context.close();
}finally{await browser.close();}
