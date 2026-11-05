<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\{Database,Finance,Documents,TaxPreparation,TaxPreparationFlow};
$n=0;function check(bool $v,string $why):void{global $n;if(!$v)throw new RuntimeException($why);$n++;echo "PASS $why\n";}
function denied(callable $f,string $why):void{try{$f();}catch(DomainException){check(true,$why);return;}throw new RuntimeException($why);}
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);$db->exec("INSERT INTO clubs VALUES('a','Synthetic A'),('b','Synthetic B');INSERT INTO actors(id,club_id,role) VALUES('finance','a','treasurer'),('member','a','member'),('other','b','treasurer')");
$service=new TaxPreparation($db,'finance');$docs=new Documents($db,'finance');$finance=new Finance($db,'finance');$key=fn()=>bin2hex(random_bytes(16));
$account=$finance->createAccount('Synthetic bank','offline','0','2025-01-01');
$input=['from_year'=>'2025','to_year'=>'2025','activity_report'=>'Synthetic activity','tax_year_notes'=>['2025'=>['record_completeness_note'=>'Synthetic original note']]];
$session=[];$preview=TaxPreparationFlow::preview($service,'finance',$input,$session);$token=$preview['token'];$save=$input+['tax_preview_token'=>$token];
check(count($session['tax_previews'])===1&&strlen(serialize($session))<2000,'session contains small receipts only');check(!str_contains(serialize($session),'Synthetic original note')&&!str_contains(serialize($session),'Synthetic activity'),'no report text or document bytes in session');
denied(function()use($service,$save,&$session){TaxPreparationFlow::save($service,'other',$save,$session);},'token bound to actor');
$expired=$session;$expired['tax_previews'][$token]['expires']=time()-1;denied(function()use($service,$save,&$expired){TaxPreparationFlow::save($service,'finance',$save,$expired);},'expired preview refused');
$changed=$save;$changed['activity_report']='Edited after preview';denied(function()use($service,$changed,&$session){TaxPreparationFlow::save($service,'finance',$changed,$session);},'changed form rejected');
$bad=$session;$bad['tax_previews'][$token]['hash']=str_repeat('0',64);denied(function()use($service,$save,&$bad){TaxPreparationFlow::save($service,'finance',$save,$bad);},'changed expected preview hash refused');check(count($docs->listing())===0,'failed preview saves create no documents');
for($i=0;$i<4;$i++)TaxPreparationFlow::preview($service,'finance',$input,$session);check(count($session['tax_previews'])===3&&!isset($session['tax_previews'][$token]),'at most three previews retained');
$preview=TaxPreparationFlow::preview($service,'finance',$input,$session);$save=$input+['tax_preview_token'=>$preview['token']];
$finance->createTransaction(['account'=>$account,'booked_on'=>'2025-05-01','direction'=>'income','amount'=>'10','description'=>'Synthetic later payment'],$key());
denied(function()use($service,$save,&$session){TaxPreparationFlow::save($service,'finance',$save,$session);},'database change after preview blocks save');check(count($docs->listing())===0,'database mismatch stores no document');
$pdf='%PDF-1.4'.str_repeat(' synthetic attachment',10000);$attachment=$docs->upload('synthetic.pdf',$pdf,['title'=>'Synthetic attachment','kind'=>'receipt','scope'=>'finance'],$key());$selected=$input+['tax_documents'=>[$attachment]];
$preview=TaxPreparationFlow::preview($service,'finance',$selected,$session);check(strlen(serialize($session))<4000,'large PDF remains outside session');$save=$selected+['tax_preview_token'=>$preview['token']];
$db->prepare('INSERT INTO document_files SELECT club_id,document_id,2,name,mime,bytes,sha256,created_at,created_by,snapshot FROM document_files WHERE document_id=? AND revision=1')->execute([$attachment]);$db->prepare('UPDATE documents SET version=version+1 WHERE id=?')->execute([$attachment]);
denied(function()use($service,$save,&$session){TaxPreparationFlow::save($service,'finance',$save,$session);},'attachment revision change refuses stale preview');check(count($docs->listing())===1,'revision mismatch creates no export');
$preview=TaxPreparationFlow::preview($service,'finance',$selected,$session);$save=$selected+['tax_preview_token'=>$preview['token']];$id=TaxPreparationFlow::save($service,'finance',$save,$session);$stored=$service->saved($id);$bytes=$docs->file($id)['bytes'];check($stored['hash']===$preview['hash']&&$stored['snapshot']===$preview['snapshot'],'saved snapshot exactly matches preview');
$finance->createTransaction(['account'=>$account,'booked_on'=>'2025-05-02','direction'=>'income','amount'=>'7','description'=>'Synthetic post-save payment'],$key());check(TaxPreparationFlow::save($service,'finance',$save,$session)===$id,'saved POST replay ignores later database changes');check(count($docs->listing())===2&&$docs->file($id)['bytes']===$bytes,'saved replay creates no duplicate and preserves ZIP bytes');
$newInput=$selected;$newInput['tax_year_notes']['2025']['record_completeness_note']='Later edited note';TaxPreparationFlow::preview($service,'finance',$newInput,$session);check($service->saved($id)['snapshot']===$stored['snapshot'],'later manual inputs cannot change saved snapshot');
denied(fn()=>(new TaxPreparation($db,'other'))->saved($id),'foreign club cannot read saved snapshot');denied(fn()=>(new TaxPreparation($db,'member'))->saved($id),'non-finance member cannot read snapshot');denied(fn()=>(new TaxPreparation($db,'member'))->build(2025,2025,''),'non-finance member cannot preview');
$other=new TaxPreparation($db,'other');denied(fn()=>$other->build(2025,2025,'',[$attachment]),'foreign attachment cannot enter preparation');
// Simulated storage corruption in isolated memory only, bypass immutable trigger explicitly.
$db->exec('DROP TRIGGER document_files_no_update');$tampered=$stored['snapshot'];$tampered['activity']='Tampered';$db->prepare('UPDATE document_files SET snapshot=? WHERE document_id=?')->execute([json_encode($tampered),$id]);denied(fn()=>$service->saved($id),'snapshot tampering detected');
check(\Kulturhus\Verein\Tests\MySqlFixture::integrity($db)==='ok'&&!(\Kulturhus\Verein\Tests\MySqlFixture::foreignKeyErrors($db)[0]??false),'flow preserves relational integrity');
echo "$n tax preparation flow checks passed; isolated memory, no network or mail\n";
