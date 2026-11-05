import assert from 'node:assert/strict';
let input=''; for await(const chunk of process.stdin) input+=chunk;
const fixture=JSON.parse(input);
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const browser=await chromium.launch({executablePath:process.env.CHROMIUM_EXECUTABLE||'/usr/bin/chromium'});
let checks=0;
const check=(value,label)=>{assert(value,label);checks++;};
try{
  const context=await browser.newContext(), page=await context.newPage(), errors=[], external=[];
  await context.route('**/*',route=>new URL(route.request().url()).origin===fixture.url?route.continue():(external.push(route.request().url()),route.abort()));
  page.on('pageerror',error=>errors.push(error.message));
  const name=suffix=>fixture.prefix+'_'+suffix;
  const root=suffix=>page.locator('[data-typed-collection][data-model-field="'+name(suffix)+'"]').first();
  const directRows=collection=>collection.locator(':scope > [data-collection-items] > [data-collection-item]');
  for(const width of [1280,768,320]){
    await page.setViewportSize({width,height:900});await page.goto(fixture.url+'/editor');
    await page.waitForFunction(()=>window.dvmTypedFieldsReady===true);
    check(await page.locator('input[type=password]').first().inputValue()==='','password blank '+width);
    check((await directRows(root('members')).count())===3,'two original items and one empty native slot '+width);
    const addTarget=await root('members').locator(':scope > [data-collection-add]').boundingBox();
    check(addTarget.width>=44&&addTarget.height>=44,'collection touch target is at least 44 by 44 '+width);
    const overflow=await page.evaluate(()=>[...document.querySelectorAll('body *')].filter(node=>node.getBoundingClientRect().right>innerWidth+1&&getComputedStyle(node).position!=='absolute').slice(0,14).map(node=>({tag:node.tagName,class:node.className,text:node.textContent.slice(0,110),width:Math.round(node.getBoundingClientRect().width),right:Math.round(node.getBoundingClientRect().right)})));
    check(!(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth)),'no document horizontal scrolling '+width+' '+JSON.stringify(overflow));
    const blank=directRows(root('members')).last();
    await blank.locator(':scope > summary').click();
    check((await blank.locator('input[name$="['+name('name')+']"]').getAttribute('required'))===null,'unused fresh item does not block form submission '+width);
    await root('tags').locator(':scope > [data-collection-add]').click();
    check((await directRows(root('tags')).count())===4,'add multiple value with real controls '+width);
    const newTag=directRows(root('tags')).last();await newTag.locator('input[name$="[value]"]').fill('Browser '+width);
    const nested=blank.locator('input[name$="['+name('name')+']"]');
    check(await nested.isVisible(),'new item visible '+width+' '+JSON.stringify(await nested.evaluate(node=>{const out=[];for(let el=node;el;el=el.parentElement)out.push({tag:el.tagName,cls:el.className,hidden:el.hidden,open:el.open,display:getComputedStyle(el).display});return out;})));
    await nested.fill('Browser guest '+width);
    await blank.locator(':scope > summary').click();await blank.locator(':scope > summary').click();
    check(await nested.inputValue()==='Browser guest '+width,'collapse preserves unsaved input '+width);
    // Use a typed native link search within the newly populated repeatable record.
    const pdf=blank.locator('[data-reference-kind=file]');await pdf.locator('[data-reference-query]').fill('public.pdf');await pdf.locator('[data-reference-action=search]').click();
    await page.waitForFunction(()=>!document.querySelector('[data-reference-kind=file][aria-busy=true]'));
    check((await pdf.locator('[data-reference-select] option').count())===2,'nested PDF metadata search succeeds '+width);
  }
  // A real POST saves both repeated controls and their original IDs/counters; it must not publish.
  const savedResponse=page.waitForResponse(response=>response.request().method()==='POST',{timeout:8000}).catch(()=>null);
  await page.locator('#save').click();const posted=await savedResponse;
  check(posted!==null&&posted.status()===303,'actual POST accepted: '+(posted?posted.status()+' '+await page.locator('[role=alert]').allTextContents():JSON.stringify(await page.locator(':invalid').evaluateAll(nodes=>nodes.map(node=>({name:node.name,message:node.validationMessage}))))));
  await page.waitForURL('**/editor?saved=1*');
  check(await page.getByRole('status').filter({hasText:'Gespeichert'}).count()===1,'actual HTTP save returns success');
  const members=directRows(root('members'));
  await members.nth(2).locator(':scope > summary').click();
  check(await members.nth(2).locator('input[name$="['+name('name')+']"]').inputValue()==='Browser guest 320','saved new item appears from original database row');
  const version=await members.nth(2).locator('input[name$="[version]"]').inputValue();check(version==='1','new item has persisted version one');
  const itemIds=await members.locator('input[name$="[id]"]').evaluateAll(inputs=>inputs.map(input=>input.value).filter(Boolean));
  check(itemIds.length===3&&new Set(itemIds).size===3,'persisted identities remain unique');
  // Reorder an editable collection, save, and read it from PHP again.
  await root('tags').locator(':scope > [data-collection-items] > [data-collection-item]').nth(2).locator(':scope > summary').click();
  await directRows(root('tags')).nth(2).locator('[data-collection-up]').click();
  await page.locator('#save').click();await page.waitForURL('**/editor?saved=1*');
  const tags=directRows(root('tags'));await tags.nth(1).locator(':scope > summary').click();
  check(await tags.nth(1).locator('input[name$="[value]"]').inputValue()==='Browser 320','native DOM reorder persists intended array order');
  const csrf=await page.locator('[name=csrf]').inputValue();
  const rejected=await context.request.post(fixture.url+'/editor',{form:{csrf:'wrong',version:'1'}});
  check(rejected.status()===403,'actual route rejects expired CSRF');
  await page.goto(fixture.url+'/editor?role=editor');
  const protectedItem=directRows(root('members')).first();await protectedItem.locator(':scope > summary').click();
  check(await protectedItem.locator('[name$="[preserve]"]').count()===1&&await protectedItem.getByText('Dieser Datensatz ist für die Bearbeitung geschützt.').count()===1,'editor gets original-bound protected stub');
  await page.locator('#save').click();await page.waitForURL('**/editor?saved=1*');
  check(await page.getByRole('status').filter({hasText:'Gespeichert'}).count()===1,'ordinary editor saves without rewriting protected records');
  await page.evaluate(()=>document.documentElement.style.fontSize='32px');
  const zoomOverflow=await page.evaluate(()=>[...document.querySelectorAll('body *')].filter(node=>node.getBoundingClientRect().right>innerWidth+1&&getComputedStyle(node).position!=='absolute').slice(0,12).map(node=>({tag:node.tagName,class:node.className,text:node.textContent.slice(0,70),width:Math.round(node.getBoundingClientRect().width),right:Math.round(node.getBoundingClientRect().right)})));
  check(!(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth)),'320px interface fits doubled font size '+JSON.stringify({nodes:zoomOverflow,sizes:await page.evaluate(()=>({scroll:document.documentElement.scrollWidth,width:innerWidth,body:document.body.scrollWidth,absolute:[...document.querySelectorAll('body *')].filter(node=>node.getBoundingClientRect().right>innerWidth+1).slice(0,5).map(node=>({tag:node.tagName,cls:node.className,width:node.getBoundingClientRect().width,txt:node.textContent.slice(0,50)}))}))}));
  check(errors.length===0,'no browser script errors: '+errors.join('; '));check(external.length===0,'no external browser requests');
  await context.close();
  const plain=await browser.newContext({javaScriptEnabled:false});const nojs=await plain.newPage();await nojs.goto(fixture.url+'/editor');
  const last=nojs.locator('[data-model-field="'+name('members')+'"]').first().locator(':scope > [data-collection-items] > [data-collection-item]').last();
  await last.locator(':scope > summary').click();await last.locator('input[name$="['+name('name')+']"]').fill('No JavaScript guest');await nojs.locator('#save').click();await nojs.waitForURL('**/editor?saved=1*');
  check((await nojs.content()).includes('No JavaScript guest'),'ordinary HTML add/save works without JavaScript');await plain.close();
}finally{await browser.close();}
console.log(JSON.stringify({suite:'native-typed-fields-browser',checks,status:'passed',external_requests:0}));
