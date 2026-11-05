<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\{Database,Registry,Documents,DocumentCatalog,ActorNames,ClubChat};
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect();Database::migrate($db);
function check(bool $value,string $label):void{if(!$value)throw new RuntimeException($label);echo "PASS $label\n";}
function denied(callable $run,string $label):void{try{$run();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
foreach([':memory:','/tmp/old.sqlite','sqlite:legacy'] as $old){try{Database::connect($old);throw new RuntimeException('Legacy connection accepted');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'MySQL-Datenbank erforderlich'),'file backend rejected');}}
check($db->query("SELECT 'Text' label,CAST(12 AS CHAR) value")->fetch()===['label'=>'Text','value'=>'12'],'SQL literals unchanged; native CAST CHAR');
$db->exec("INSERT INTO clubs VALUES('a','Synthetic');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('admin','a','members',1),('staff','a','members',0)");
$forms=new \Kulturhus\Verein\DonationForms($db,'admin');
foreach(['money','goods'] as $kind)check($forms->sourceChoices($kind,'Bühne 12.09.2026')['rows']===[],'native dynamic donation search has an aliased derived table: '.$kind);
$root=new Registry($db,'admin');$staff=new Registry($db,'staff');$data=['title'=>'Formal meeting','start'=>'2027-01-12T12:00','end'=>'2027-01-12T13:00','activity_kind'=>'assembly'];
$formal=$root->saveAppointment(null,0,$data,[]);
denied(fn()=>$staff->saveAppointment($formal,1,array_replace($data,['activity_kind'=>'event']),[]),'member administrator cannot downgrade existing assembly');
denied(fn()=>$staff->deleteAppointment($formal,1),'member administrator cannot delete existing assembly');
$normal=$staff->saveAppointment(null,0,array_replace($data,['activity_kind'=>'event']),[]);$staff->deleteAppointment($normal,1);check($root->appointment($formal)['activity_kind']==='assembly','ordinary events editable; formal meeting unchanged');
$adminDocs=new Documents($db,'admin');$staffDocs=new Documents($db,'staff');$plain=DocumentCatalog::folder($db,'a',['Mitglieder'],'members');
$restricted=$adminDocs->saveFolder('',0,'Private applications',$plain,'members');$db->prepare('UPDATE document_folders SET admission_only=1 WHERE id=?')->execute([$restricted]);
$parent=$adminDocs->saveFolder('',0,'Normal parent',$plain,'members');$child=$adminDocs->saveFolder('',0,'Normal child',$parent,'members');
$doc=$adminDocs->upload('synthetic.txt','Synthetic content',['folder'=>$child,'kind'=>'membership','title'=>'Synthetic'],bin2hex(random_bytes(16)));
check($staffDocs->file($doc)['bytes']==='Synthetic content','ordinary member document accessible before protection');
$adminDocs->saveFolder($parent,1,'Normal parent',$restricted,'members');
denied(fn()=>$staffDocs->file($doc),'moving populated folder in protects descendant document');
denied(fn()=>$staffDocs->saveFolder($parent,2,'Escape',$plain,'members'),'staff cannot move protected folder out');
$adminDocs->saveFolder($parent,2,'Moved back',$plain,'members');denied(fn()=>$staffDocs->file($doc),'moving populated folder out retains protection');
check($adminDocs->file($doc)['bytes']==='Synthetic content','authorized board access and original bytes retained');
$contact=$root->saveContact(null,0,'person','Synthetic recipient','member@example.invalid','');
$db->prepare("INSERT INTO actors(id,club_id,role,contact_id) VALUES('recipient','a','member',?)")->execute([$contact]);
$finance=new \Kulturhus\Verein\Finance($db,'admin');$entry=$finance->createEntry(['kind'=>'contribution','description'=>'Synthetic dues','amount'=>'48','document_date'=>'2026-01-01','due_date'=>'2026-02-01','category'=>'general'],bin2hex(random_bytes(16)));
$invoiceDoc=$adminDocs->upload('dues.txt','Synthetic invoice',['folder'=>$plain,'kind'=>'invoice','title'=>'Synthetic dues'],bin2hex(random_bytes(16)));
$db->prepare("INSERT INTO contribution_invoices(club_id,entry_id,document_id,recipient_contact_id,recipient_email,year,state,missing_fields,created_at) VALUES('a',?,?,?,'member@example.invalid',2026,'issued','[]',?)")->execute([$entry,$invoiceDoc,$contact,gmdate('c')]);
$portal=new \Kulturhus\Verein\PortalDocuments($db,'recipient');check($portal->file($invoiceDoc)['bytes']==='Synthetic invoice'&&count($portal->listing())===1,'recipient retains ordinary issued invoice access');
$old=$adminDocs->document($invoiceDoc);$adminDocs->update($invoiceDoc,$old['version'],['folder'=>$restricted,'kind'=>'invoice','title'=>$old['title'],'reason'=>'Synthetic privacy move','links'=>[]]);
denied(fn()=>$portal->file($invoiceDoc),'issued invoice portal also denies admission-protected files');check($portal->listing()===[],'protected issued invoice hidden from portal listing');
$old=$adminDocs->document($invoiceDoc);$adminDocs->update($invoiceDoc,$old['version'],['folder'=>$plain,'kind'=>'invoice','title'=>$old['title'],'reason'=>'Synthetic move back','links'=>[]]);
denied(fn()=>$portal->file($invoiceDoc),'portal protection remains after moving out');
check($db->query("SELECT CONCAT('Hallo ', '👋')")->fetchColumn()==='Hallo 👋','native connection supports four-byte UTF-8');
check(\Kulturhus\Verein\Tests\MySqlFixture::foreignKeyErrors($db)===[],'native MySQL foreign keys intact');
$inventory=new \Kulturhus\Verein\Inventory($db,'admin');$request=bin2hex(random_bytes(16));$peer=\Kulturhus\Verein\Tests\MySqlFixture::existing(\Kulturhus\Verein\Tests\MySqlFixture::dsn($db));
$lock='verein-write-'.substr(hash('sha256',(string)$db->query('SELECT DATABASE()')->fetchColumn()),0,48);$lockQuery=$peer->prepare('SELECT GET_LOCK(?,0)');
for($retry=0;$retry<2;$retry++){$location=$inventory->saveLocation('',0,['name'=>'Synthetic shelf'],$request);$lockQuery->execute([$lock]);check((int)$lockQuery->fetchColumn()===1,'inventory save/replay releases its writer lock');$peer->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);}
