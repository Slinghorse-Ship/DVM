<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';require __DIR__.'/../src/Web/View.php';
use Kulturhus\Verein\{Database,Registry,Settings,SettingsSchema,VolunteerForm,DocumentPreview};
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);$checks=0;
function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function denied(callable $work,string $label):void{try{$work();}catch(DomainException){check(true,$label);return;}throw new RuntimeException('Not rejected: '.$label);}
$db->exec("INSERT INTO clubs VALUES('a','Synthetic Club'),('b','Other Club');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('admin','a','members',1),('own','a','member',0),('passive','a','member',0),('foreign','b','members',1),('board','a','member',0);INSERT INTO admission_board VALUES('a','board','2. Vorstand',1,1)");
(new Settings($db,'admin'))->save('club',array_replace(SettingsSchema::defaults('club'),['name'=>'Musterverein e. V.','street'=>'Vereinsweg 7','postcode'=>'26349','city'=>'Musterort']),0);
$r=new Registry($db,'admin');$active=$r->saveContact(null,0,'person','Alex Muster','active@example.invalid',"Musterweg 12\n26349 Musterort");$passive=$r->saveContact(null,0,'person','Robin Beispiel','','');$contact=$r->saveContact(null,0,'person','Nur Kontakt','','');
$chair=$r->saveContact(null,0,'person','Erika Beispielvorstand','','');$successor=$r->saveContact(null,0,'person','Robin Ersatzvorstand','','');$foreignChair=(new Registry($db,'foreign'))->saveContact(null,0,'person','Fremder Beispielvorstand','','');
$db->prepare("INSERT INTO actors(id,club_id,role,contact_id) VALUES('chair','a','member',?),('successor','a','member',?)")->execute([$chair,$successor]);
$db->prepare("UPDATE actors SET contact_id=? WHERE id='foreign'")->execute([$foreignChair]);
$db->exec("INSERT INTO admission_board VALUES('a','chair','1. Vorstand',1,1),('a','successor','1. Vorstand',0,1),('b','foreign','1. Vorstand',1,1)");
$db->prepare("INSERT INTO club_settings VALUES('a','account:admin',?,1,?)")->execute([json_encode(['display_name'=>'Nur Administrator, kein erster Vorstand']),gmdate('c')]);
$r->addMembership($active,'M-001','aktiv','2015-01-01',false,null,'year');$r->addMembership($passive,'M-002','passiv','2020-04-02',false);
$db->prepare('UPDATE actors SET contact_id=? WHERE id=?')->execute([$active,'own']);$db->prepare('UPDATE actors SET contact_id=? WHERE id=?')->execute([$passive,'passive']);
$service=new VolunteerForm($db,'admin','general');$self=new VolunteerForm($db,'own','general');$other=new VolunteerForm($db,'passive','general');
$input=['first_name'=>'Alex','last_name'=>'Muster','birthday'=>'','street'=>'Musterweg 12','city'=>'26349 Musterort','activity'=>"Aufbau der Bühnentechnik bei Vereinsveranstaltungen.\nBetreuung der Gäste und Hilfe beim Abbau.",'period_from'=>'2022-05-01','period_to'=>'','effort'=>'Etwa 6 Stunden im Monat'];
check($self->prefill()['membership']['status']==='aktiv','active status from membership, not form');
check(count($service->people())===2,'general selector excludes nonmembers');
denied(fn()=>$service->build($contact,$input,false),'contact cannot receive membership confirmation');
denied(fn()=>$self->build($passive,$input,false),'own-only access');
denied(fn()=>(new VolunteerForm($db,'foreign','general'))->build($active,$input,false),'tenant isolation');
denied(fn()=>$self->build($active,$input,true),'no member signature escalation');
denied(fn()=>$service->build($active,array_replace($input,['activity'=>'']),false),'active tasks required');
check($service->build($active,$input,false)['volunteer_general']['year']===(int)date('Y'),'annual confirmation uses current calendar year');
check($service->build($active,array_replace($input,['confirmation_year'=>'2999']),false)['volunteer_general']['year']===(int)date('Y'),'forged future year cannot alter annual confirmation');
$data=$self->build($active,$input,false);check(isset($data['volunteer_general'])&&!isset($data['volunteer_form']),'general renderer separate from hygiene');
$expectedSigner=['actor_id'=>'chair','office'=>'1. Vorstand','board_version'=>1,'contact_id'=>$chair,'profile_version'=>1,'name'=>'Erika Beispielvorstand'];
check($data['volunteer_general']['signer']===$expectedSigner,'current first chair resolved from own club and contact, without new board-check requirement');
check($service->build($active,array_replace($input,['signer'=>['actor_id'=>'admin','name'=>'FORGED SIGNER'],'signer_name'=>'FORGED SIGNER','board_name'=>'FORGED SIGNER']),false)['volunteer_general']['signer']===$expectedSigner,'forged POST signer cannot replace the assigned first chair');
check($data['volunteer_general']['membership']['starts_precision']==='year','historical year precision retained');
$passiveInput=['first_name'=>'Robin','last_name'=>'Beispiel','activity'=>'FORGED ACTIVE WORK','period_from'=>'2999-01-01'];$passiveData=$other->build($passive,$passiveInput,false);
check($passiveData['volunteer_general']['activity']===''&&$passiveData['volunteer_general']['effort']==='','passive confirmation cannot assert activity');
check($passiveData['volunteer_general']['membership']['status']==='passiv','passive status comes from profile');
$key=bin2hex(random_bytes(16));$hash=VolunteerForm::digest($data);$doc=$self->save($active,$input,false,$key,$hash);
check($doc===$self->save($active,$input,false,$key,$hash),'repeat save is idempotent');
check(str_starts_with($self->file($doc)['bytes'],'%PDF-'),'self can download own certificate');
denied(fn()=>$other->file($doc),'unrelated member cannot download');
check(str_starts_with((new VolunteerForm($db,'board','general'))->file($doc)['bytes'],'%PDF-'),'board can download member certificate');
$snap=json_decode($db->query('SELECT snapshot FROM document_files')->fetchColumn(),true);check($snap['purpose']==='volunteer_general'&&!isset($snap['signature']),'snapshot purpose and no raw signature');
check($snap['volunteer_general']['signer']===$expectedSigner,'saved snapshot records exact first-chair identity, office and profile versions');
$originalPdf=$self->file($doc)['bytes'];
$db->prepare('UPDATE contacts SET name=?,version=version+1 WHERE id=?')->execute(['Erika Umbenannt',$chair]);
check($self->build($active,$input,false)['volunteer_general']['signer']['name']==='Erika Umbenannt','new preview follows current first-chair name');
denied(fn()=>$self->save($active,$input,false,bin2hex(random_bytes(16)),$hash),'first-chair rename invalidates old preview');
$db->prepare('UPDATE contacts SET name=?,version=version+1 WHERE id=?')->execute(['Erika Beispielvorstand',$chair]);
$beforeOffice=VolunteerForm::digest($self->build($active,$input,false));
$db->exec("UPDATE admission_board SET enabled=0,version=version+1 WHERE actor_id='chair';UPDATE admission_board SET enabled=1,version=version+1 WHERE actor_id='successor'");
check($self->build($active,$input,false)['volunteer_general']['signer']['name']==='Robin Ersatzvorstand','new preview follows changed first-chair assignment');
denied(fn()=>$self->save($active,$input,false,bin2hex(random_bytes(16)),$beforeOffice),'first-chair office change invalidates old preview');
check($self->file($doc)['bytes']===$originalPdf&&json_decode($db->query('SELECT snapshot FROM document_files')->fetchColumn(),true)['volunteer_general']['signer']===$expectedSigner,'saved PDF and snapshot remain immutable after rename and office change');
$db->exec("UPDATE admission_board SET enabled=1,version=version+1 WHERE actor_id='chair';UPDATE admission_board SET enabled=0,version=version+1 WHERE actor_id='successor'");
$invalidSigners=[
 'missing first chair'=>fn()=>$db->exec("UPDATE admission_board SET enabled=0 WHERE actor_id='chair'"),
 'ambiguous eligible office holders'=>fn()=>$db->exec("UPDATE admission_board SET enabled=1 WHERE actor_id='successor'"),
 'inactive first chair'=>fn()=>$db->exec("UPDATE actors SET active=0 WHERE id='chair'"),
 'disabled ProcessWire identity'=>fn()=>$db->exec("INSERT INTO processwire_identities VALUES(991,'chair',0,1)"),
 'editor is not an eligible first chair'=>fn()=>$db->exec("UPDATE actors SET role='editor' WHERE id='chair'"),
 'foreign actor cannot fill local office'=>fn()=>$db->exec("UPDATE admission_board SET actor_id='foreign' WHERE actor_id='chair'"),
 'archived linked contact'=>fn()=>$db->prepare('UPDATE contacts SET archived_at=? WHERE id=?')->execute([gmdate('c'),$chair]),
 'linked organisation is not a person'=>fn()=>$db->prepare("UPDATE contacts SET kind='organisation' WHERE id=?")->execute([$chair]),
 'blank person name'=>fn()=>$db->prepare("UPDATE contacts SET name=' ' WHERE id=?")->execute([$chair]),
 'no contact and no explicit account name cannot use admin or defaults'=>fn()=>$db->exec("UPDATE actors SET contact_id=NULL WHERE id='chair'"),
];
foreach($invalidSigners as $label=>$mutate){$db->beginTransaction();try{$mutate();denied(fn()=>$self->build($active,$input,false),$label);}finally{$db->rollBack();}}
try{$db->prepare("UPDATE actors SET contact_id=? WHERE id='chair'")->execute([$foreignChair]);throw new RuntimeException('Cross-club contact binding accepted');}catch(PDOException $e){check((int)($e->errorInfo[1]??0)===1452,'schema rejects foreign linked contact before resolver can read it');}
$db->beginTransaction();
try{
 $db->exec("UPDATE actors SET contact_id=NULL WHERE id='chair'");
 $db->prepare("INSERT INTO club_settings VALUES('a','account:chair',?,7,?)")->execute([json_encode(['display_name'=>'Erika Kontoname']),gmdate('c')]);
 $accountSigner=$self->build($active,$input,false)['volunteer_general']['signer'];
 check($accountSigner['name']==='Erika Kontoname'&&$accountSigner['actor_id']==='chair'&&$accountSigner['contact_id']===null&&$accountSigner['profile_version']===7,'unlinked office holder may use its explicitly saved account name with version');
 $db->prepare("UPDATE actors SET contact_id=? WHERE id='chair'")->execute([$chair]);
 check($self->build($active,$input,false)['volunteer_general']['signer']['name']==='Erika Beispielvorstand','linked contact name takes priority over account display name');
 $db->prepare('UPDATE contacts SET archived_at=? WHERE id=?')->execute([gmdate('c'),$chair]);
 denied(fn()=>$self->build($active,$input,false),'archived contact cannot fall back to account display name');
}finally{$db->rollBack();}
// Native MySQL savepoints do not start a transaction; isolate temporary fixture changes explicitly.
$db->beginTransaction();
try{$db->exec("UPDATE admission_board SET enabled=1 WHERE actor_id='successor';UPDATE actors SET active=0 WHERE id='successor'");check($self->build($active,$input,false)['volunteer_general']['signer']['actor_id']==='chair','inactive stale alternative does not displace unique current holder');}finally{$db->rollBack();}
$hash=VolunteerForm::digest($self->build($active,$input,false));
$db->prepare("UPDATE memberships SET status='passiv',version=version+1 WHERE contact_id=?")->execute([$active]);
denied(fn()=>$self->save($active,$input,false,bin2hex(random_bytes(16)),$hash),'membership change invalidates preview');
$db->prepare("UPDATE memberships SET status='aktiv',version=version+1 WHERE contact_id=?")->execute([$active]);
$_SESSION=['actor'=>'passive','csrf'=>'synthetic'];$_GET=['volunteer_kind'=>'general'];$_POST=[];ob_start();require __DIR__.'/../views/Settings/volunteer-form.php';$html=(string)ob_get_clean();
check(str_contains($html,'Passive Mitgliedschaft')&&!str_contains($html,'name="activity"'),'passive UI has no irrelevant task fields');
$_SESSION=['actor'=>'own','csrf'=>'synthetic'];ob_start();require __DIR__.'/../views/Settings/volunteer-form.php';$html=(string)ob_get_clean();
check(str_contains($html,'name="activity"')&&str_contains($html,'Bestätigung für das Kalenderjahr '.date('Y')),'active UI has suggested tasks and actual year');
$root=__DIR__.'/../../.local/volunteer-general';if(!is_dir($root))mkdir($root,0700,true);
file_put_contents($root.'/active.pdf',DocumentPreview::pdf($data));file_put_contents($root.'/passive.pdf',DocumentPreview::pdf($passiveData));
$chairVersion=(int)$db->query("SELECT version FROM admission_board WHERE club_id='a' AND actor_id='chair'")->fetchColumn();
$boardGrants=new \Kulturhus\Verein\AdmissionVotes($db,'admin');$boardGrants->grant('chair',$chairVersion,'1. Vorstand',true,true);
check((int)$db->query("SELECT version FROM admission_board WHERE club_id='a' AND actor_id='chair'")->fetchColumn()===$chairVersion,'real confirmation records provenance without changing legacy office version');
$image=imagecreatetruecolor(200,60);imagefill($image,0,0,imagecolorallocate($image,255,255,255));imageline($image,20,40,180,15,imagecolorallocate($image,20,20,20));ob_start();imagepng($image);$signature=(string)ob_get_clean();(new \Kulturhus\Verein\BoardSignature($db,'admin'))->save($signature,0,true);
$signed=$service->build($active,$input);check($signed['signature_used']&&isset($signed['signature']),'authorized board automatically inserts stored signature');file_put_contents($root.'/signed.pdf',DocumentPreview::pdf($signed));
check($signed['volunteer_general']['signer']['name']==='Erika Beispielvorstand'&&$signed['signature_owner_proof']['upload_sequence']>$signed['signature_owner_proof']['office_sequence'],'signed sample binds current fictional chair to later real upload');
check(VolunteerForm::digest($signed)!==VolunteerForm::digest(array_replace($signed,['signature_owner_proof'=>[]])),'signature provenance participates in preview digest');
check(!$self->build($active,$input)['signature_used'],'stored signature remains unavailable to self-service');
$db->beginTransaction();
try{
 $db->exec("INSERT INTO settings_assets(club_id,id,mime,bytes,sha256) SELECT club_id,'untracked-signature',mime,bytes,sha256 FROM settings_assets WHERE club_id='a';UPDATE club_settings SET payload=json_set(payload,'$.signature','untracked-signature') WHERE club_id='a' AND section='paper'");
 denied(fn()=>$service->build($active,$input),'signature with no original revision is rejected');
 check(!$service->build($active,$input,false)['signature_used'],'unsigned draft remains possible when signature provenance is missing');
 $db->exec("INSERT INTO settings_revisions(club_id,section,version,payload,actor_id,happened_at) SELECT club_id,section,version+1,payload,'admin',updated_at FROM club_settings WHERE club_id='a' AND section='paper'");
 denied(fn()=>$service->build($active,$input),'revision without matching upload audit is rejected');
}finally{$db->rollBack();}
$db->beginTransaction();
try{
 $db->prepare("INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES('a','admin','identity.assigned','chair',2,?)")->execute([gmdate('c')]);
 denied(fn()=>$service->build($active,$input),'identity reassignment after upload requires renewed signature');
 check(!$service->build($active,$input,false)['signature_used'],'unsigned draft remains possible after identity reassignment');
}finally{$db->rollBack();}
// Use real services for office changes and uploads; each owns its transaction.
$boardGrants->grant('chair',$chairVersion,'1. Vorstand',false,false);
$successorVersion=(int)$db->query("SELECT version FROM admission_board WHERE club_id='a' AND actor_id='successor'")->fetchColumn();
$boardGrants->grant('successor',$successorVersion,'1. Vorstand',true,true);
denied(fn()=>$service->build($active,$input),'old chair signature cannot be printed below successor name');
$unsignedSuccessor=$service->build($active,$input,false);
check(!$unsignedSuccessor['signature_used']&&$unsignedSuccessor['volunteer_general']['signer']['name']==='Robin Ersatzvorstand','unsigned successor draft remains usable');
$settings=new Settings($db,'admin');$paper=$settings->section('paper');$settings->save('paper',$paper['data'],$paper['version']);
denied(fn()=>$service->build($active,$input),'resaving unchanged paper does not renew the old signature asset provenance');
$signatureStore=new \Kulturhus\Verein\BoardSignature($db,'admin');$signatureStore->save($signature,$signatureStore->state()['version'],true);
$renewed=$service->build($active,$input);
check($renewed['signature_used']&&$renewed['volunteer_general']['signer']['name']==='Robin Ersatzvorstand'&&$renewed['signature_owner_proof']['asset_id']!==$signed['signature_owner_proof']['asset_id'],'explicit new upload allows current successor name even with identical image bytes');
check($renewed['signature_owner_proof']['upload_sequence']>$renewed['signature_owner_proof']['office_sequence'],'renewed upload follows successor office confirmation');
denied(fn()=>$service->save($active,$input,true,bin2hex(random_bytes(16)),VolunteerForm::digest($signed)),'old signed preview cannot be saved after succession and renewed upload');
$paper=$settings->section('paper');$settings->save('paper',$paper['data'],$paper['version']);
check($service->build($active,$input)['signature_owner_proof']===$renewed['signature_owner_proof'],'unrelated paper save preserves the first actual upload proof');
$long=$input;$long['activity']=str_repeat('Aufbau, Betreuung und Abbau bei Kulturveranstaltungen. ',32);file_put_contents($root.'/long.pdf',DocumentPreview::pdf($self->build($active,$long,false)));
check((int)$db->query('SELECT COUNT(*) FROM documents')->fetchColumn()===1,'only explicitly saved certificate persisted');
echo "PASS $checks membership and volunteer certificate checks\n";
