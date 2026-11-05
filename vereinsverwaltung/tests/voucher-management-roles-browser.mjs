import fs from 'node:fs';
import {createHash} from 'node:crypto';

export async function runVoucherRoles(o){
 const {page,ctx,origin,js,locale,t,label,data,f,check,fit,url,context,login,language}=o;
 async function download(p,link,expected,label){const [file]=await Promise.all([p.waitForEvent('download'),link.click()]);const bytes=fs.readFileSync(await file.path());check(bytes.subarray(0,5).toString()==='%PDF-'&&createHash('sha256').update(bytes).digest('hex')===expected,label);}
 await page.goto(url(origin,{voucher_event:data.event}));
 await download(page,page.locator('[data-card-identity="'+data.piece.tickets[0].id+'"] a[href*="voucher_manager_pdf"]').first(),data.pdf_sha256,'actual general PDF click downloads only the exact current original recipient file '+label);
 const budget=await ctx.request.get(origin+'/vereinsverwaltung/?'+new URLSearchParams({voucher_manager_pdf:f.data.large_event,voucher_person:f.data.person}));check(budget.status()===422&&!((await budget.headers())['content-type']||'').includes('application/pdf'),'actual oversized general PDF request refuses two hundred one cards without a substitute file '+label);
 for(const role of['helper','reader','foreign']){
  const roleContext=await context(origin,js),p=await roleContext.newPage();p.on('dialog',dialog=>dialog.accept());await login(p,origin,role);await language(p,origin,locale);
  let response=await p.goto(url(origin,{voucher_event:data.event}));
  if(role==='helper'){
   check(response.status()===200&&await p.locator('[data-voucher-card]').count()===9&&await p.locator('[data-voucher-card-action="event-voucher-redeem"]').count()===9&&await p.locator('[data-voucher-card-action="event-voucher-revoke"]').count()===0,'actual assigned helper without a member profile reads the event and only redemption controls '+label);
   await download(p,p.locator('[data-card-identity="'+data.piece.tickets[0].id+'"] a[href*="voucher_manager_pdf"]').first(),data.pdf_sha256,'actual current helper downloads the exact authorized other-recipient PDF '+label);
   for(const task of['grant','configure']){response=await p.goto(url(origin,{voucher_event:data.event,voucher_task:task}));check(response.status()===403&&await p.locator('[data-voucher-selection]').count()===0&&await p.locator('[data-voucher-card]').count()===0,'actual helper cannot open administrative '+task+' fields or substitute cards '+label);}
   response=await roleContext.request.get(origin+'/vereinsverwaltung/?voucher_original='+data.receipt);check(response.status()===403,'actual helper cannot download another creating account original confirmation '+label);
  }else{
   check(response.status()===403&&await p.locator('[data-voucher-card],[data-personal-voucher-card]').count()===0,'actual '+role+' cannot enter general redemption or fall back to unrelated private cards '+label);
   const refused=await roleContext.request.get(origin+'/vereinsverwaltung/?'+new URLSearchParams({voucher_manager_pdf:data.event,voucher_person:f.data.person}));check(refused.status()===403,'actual '+role+' cannot download a general-purpose file through personal rights '+label);
   if(role==='reader'){
    response=await p.goto(url(origin,{voucher_event:data.event,voucher_scope:'own'}));check(response.status()===200&&await p.locator('[data-personal-voucher-card]').count()===3,'actual ordinary member retains only their own original cards '+label);
    await download(p,p.getByRole('link',{name:t('Eigene verfügbare Gutscheine als PDF','Own available vouchers as a PDF'),exact:true}),data.pdf_sha256,'actual personal PDF click bypasses soft navigation and downloads the exact original file '+label);
   }
  }
  await roleContext.unrouteAll({behavior:'wait'});await roleContext.close();
 }
}
