import fs from 'node:fs';
const f=JSON.parse(fs.readFileSync(0,'utf8'));
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE);
const browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE,headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
let checks=0,posts=0;
const check=(ok,label)=>{if(!ok)throw new Error(label);checks++;};
const url=(mode,account)=>f.origin+(mode==='integrated'?'/vereinsverwaltung/':'/')+'?'+new URLSearchParams(mode==='integrated'?{view:'chat',native_item:account.thread}:{view:'chat',item:account.thread});
const compose=p=>p.locator('[data-chat-compose]');
async function fit(p,label){await p.waitForLoadState('load');await p.waitForTimeout(150);const ok=await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1);if(!ok)await p.screenshot({path:'/tmp/chat-files-overflow.png',fullPage:true});check(ok,'actual multi-file chat fits '+label);}
try{
  for(const mode of ['native','integrated'])for(const js of [true,false]){
    const flow=js?'js':'basic',account=f.accounts[mode][flow];console.log('Checking actual file chat '+mode+'/'+flow);
    const c=await browser.newContext({viewport:{width:390,height:1000},hasTouch:true,javaScriptEnabled:js});
    await c.route('**/*',r=>new URL(r.request().url()).origin===f.origin?r.continue():r.abort('blockedbyclient'));
    const p=await c.newPage(),errors=[];p.on('pageerror',e=>errors.push(e.message));p.on('request',r=>{if(r.method()==='POST')posts++;});p.on('dialog',d=>d.type()==='beforeunload'?d.accept():d.dismiss());
    await p.goto(f.origin+'/own-test-enter?mode='+mode+'&flow='+flow);await compose(p).waitFor();
    await p.locator('#chat-message-'+account.source_seq+' [data-chat-reply]').tap();
    const body='Actual personally selected files '+mode+'/'+flow+' ä🙂';await compose(p).locator('[name=body]').fill(body);
    const key=await compose(p).locator('[name=request_key]').inputValue();const picker=compose(p).locator('[type=file]');
    await compose(p).locator('[data-chat-files] > summary').tap();
    check(await picker.getAttribute('multiple')!==null,'actual composer supports one or several original files');
    if(!js){
      await picker.setInputFiles(f.root+'/bad.bin');await compose(p).getByRole('button',{name:'Nachricht senden',exact:true}).tap();await compose(p).waitFor();
      check(await compose(p).locator('[name=body]').inputValue()===body&&await compose(p).locator('[name=request_key]').inputValue()===key,'plain invalid upload retains exact human body and original retry token');
      check(await compose(p).locator('[name=reply_to]').inputValue()===String(account.source_seq),'plain invalid upload retains the exact original human reply target');
      await compose(p).locator('[data-chat-files] > summary').tap();
    }
    const currentPicker=compose(p).locator('[type=file]');
    await currentPicker.setInputFiles(js?[f.root+'/large-phone.jpg',f.root+'/source.png',f.root+'/own-camera.heic',f.root+'/own-camera.dng',f.root+'/source.txt']:[f.root+'/source.png',f.root+'/source.txt']);
    if(js){
      await p.waitForFunction(()=>{const el=document.querySelector('[data-chat-compose] input[type=file]');return !el.disabled&&el.files.length===5&&[...el.files].every(f=>!['heic','dng'].includes(f.name.split('.').pop().toLowerCase()));},null,{timeout:90000});
      check((await compose(p).locator('[data-chat-file-count]').innerText()).includes('5 ausgewählt'),'real converted raster/HEIC/RAW selection shows its complete file count');
      check(await compose(p).locator('[data-chat-file-selection] li').count()===5,'actual compact selection keeps five independent original filenames');
    }
    let originalBody=null,headers=null;
    if(mode==='native'&&js){
      const prepared=await currentPicker.evaluate(async el=>Promise.all([...el.files].map(file=>new Promise((resolve,reject)=>{const reader=new FileReader();reader.onload=()=>resolve({name:file.name,base64:reader.result.split(',')[1]});reader.onerror=reject;reader.readAsDataURL(file);})))) ;
      await c.route('**/*',async route=>{
        const r=route.request(),text=r.postDataBuffer()?.toString('latin1')||'';
        if(!originalBody&&r.method()==='POST'&&text.includes('chat-send')){
          headers=await r.allHeaders();const raw=r.postDataBuffer(),boundary=/boundary=([^;]+)/.exec(headers['content-type'])[1];
          const pattern=/name="chat_file\[\]"; filename="([^\r\n]*)"\r\nContent-Type:[^\r\n]*\r\n\r\n/g,chunks=[];let cursor=0,index=0,match;
          while((match=pattern.exec(text))){const begin=match.index+match[0].length,end=text.indexOf('\r\n--'+boundary,begin);if(end<0||match[1]!==prepared[index]?.name)throw new Error('Own ordered multipart original unavailable.');chunks.push(raw.subarray(cursor,begin),Buffer.from(prepared[index++].base64,'base64'));cursor=end;pattern.lastIndex=end;}
          if(index!==prepared.length)throw new Error('Own whole multipart selection unavailable.');chunks.push(raw.subarray(cursor));originalBody=Buffer.concat(chunks);headers['content-length']=String(originalBody.length);
          const accepted=await route.fetch({postData:originalBody,headers});
          if(accepted.status()!==200){let error='';try{error=(await accepted.json()).error||'';}catch{}throw new Error('Owned original multipart send was not accepted '+accepted.status()+': '+error.slice(0,180));}
          await route.abort('failed');
        }else await route.fallback();
      });
    }
    await compose(p).getByRole('button',{name:'Nachricht senden',exact:true}).tap();
    if(mode==='native'&&js){
      await p.waitForFunction(()=>document.querySelector('#notice')?.classList.contains('error'));
      check(await compose(p).locator('[name=body]').inputValue()===body&&await compose(p).locator('[name=request_key]').inputValue()===key,'actual lost accepted multipart acknowledgement retains personal text and original send key');
      check(await currentPicker.evaluate(el=>el.files.length)===5,'actual network error retains all converted files for exact original retry');
      const replay=await c.request.post(url(mode,account),{data:originalBody,headers});
      check(replay.status()===200,'actual exact multipart HTTP replay confirms the same accepted original without duplicate send');
      await p.goto(url(mode,account));
    }
    await p.locator('[data-chat-message]').filter({hasText:body}).waitFor();
    const message=p.locator('[data-chat-message]').filter({hasText:body});
    check((await message.locator('.chat-reply').innerText()).includes(account.source_body),'actual grouped file message retains its original human reply context');
    if(js){
      check(await message.locator('.chat-attachment-card').count()===5&&await message.locator('.chat-more-files > summary').innerText()==='1 weitere Dateien','actual large selection shows four initial cards and bounded remaining-file control');
      await message.locator('.chat-more-files > summary').tap();
    }else check(await message.locator('.chat-attachment-card').count()===2,'actual plain multipart form accepts both original files as one message');
    const link=message.locator('a.chat-file').last(),download=await c.request.get(new URL(await link.getAttribute('href'),f.origin).href);
    check(download.status()===200&&download.headers()['content-disposition'].startsWith('attachment;')&&download.headers()['cache-control']==='no-store'&&Buffer.compare(await download.body(),fs.readFileSync(f.root+'/source.txt'))===0,'actual ordered private download returns exact independent original bytes');
    const head=await c.request.head(new URL(await link.getAttribute('href'),f.origin).href);check(head.status()===200&&(await head.body()).length===0&&head.headers()['content-length']===download.headers()['content-length'],'actual ordered private HEAD preserves length without disclosing body');
    const wrong=new URL(await link.getAttribute('href'),f.origin);wrong.searchParams.set('attachment','20');check((await c.request.get(wrong.href)).status()>=400,'actual unavailable extra ordinal cannot expose another private original');
    for(const width of [320,390,820])for(const scale of [1,2]){
      await p.setViewportSize({width,height:1000});await p.goto(url(mode,account));await compose(p).waitFor();if(scale===2)await p.evaluate(()=>document.documentElement.style.fontSize='200%');
      await fit(p,mode+'/'+flow+'/'+width+'/'+scale);
      check((await compose(p).getByRole('button',{name:'Nachricht senden',exact:true}).boundingBox()).height>=44,'actual multiple-file send remains a real touch target');
    }
    check(errors.length===0,'actual file chat has no JavaScript error');await c.close();
  }
  console.log(JSON.stringify({browser_checks:checks,actual_posts:posts,viewport_states:24,external_actions:0}));
}finally{await browser.close();}
