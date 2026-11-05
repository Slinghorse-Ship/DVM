'use strict';
// Inspect the real PHP view rendered by datev-outgoing-credit.php with synthetic data.
const {chromium}=require('playwright'),fs=require('fs'),path=require('path'),assert=require('assert/strict');
(async()=>{
 assert.equal(process.env.VEREIN_MODE,'synthetic');
 const dir=fs.realpathSync(process.env.DATEV_CREDIT_RENDER_DIR||'');
 assert.equal(path.dirname(dir).toLowerCase(),fs.realpathSync(path.join(__dirname,'../../.local')).toLowerCase());
 assert(/^datev-credit-[a-z0-9-]+$/.test(path.basename(dir)));
 const browser=await chromium.launch({channel:'msedge',headless:true});let n=0;
 const ok=(value,label)=>{assert(value,label);console.log('PASS '+label);n++;};
 try{
  const context=await browser.newContext();await context.route('**/*',route=>route.abort());const page=await context.newPage();const errors=[];page.on('pageerror',error=>errors.push(error.message));
  for(const width of [1280,390]){
   await page.setViewportSize({width,height:900});
   for(const state of ['valid','rounding','missing-key']){
    await page.setContent(fs.readFileSync(path.join(dir,state+'.html'),'utf8'));
    ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),width+' '+state+' view has no page overflow');
    const download=page.getByRole('button',{name:'DATEV-Buchungsstapel herunterladen',exact:true});
    ok(await download.count()===(state==='valid'?1:0),width+' '+state+' download follows actual validation');
    if(state==='valid'){
     const help=page.locator('summary').filter({hasText:'Welche Buchungen enthält dieser Export?'});await help.focus();await page.keyboard.press('Enter');
     ok(await page.getByText('Eigene Ausgangsgutschriften:',{exact:true}).isVisible(),width+' keyboard opens correction guidance');
     await page.screenshot({path:path.join(dir,'valid-'+width+'.png'),fullPage:true});
    }else{
     await page.getByText('Download noch gesperrt:',{exact:false}).scrollIntoViewIfNeeded();
     await page.screenshot({path:path.join(dir,state+'-'+width+'.png'),fullPage:true});
     if(state==='missing-key')ok(await page.getByRole('link',{name:'Hier berichtigen / prüfen'}).first().getAttribute('href').then(href=>href.includes('edit_positions=1')),width+' original accounting can be opened directly');
    }
   }
  }
  ok(errors.length===0,'no browser errors or external requests required');
  console.log(n+' DATEV credit view browser checks passed');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
