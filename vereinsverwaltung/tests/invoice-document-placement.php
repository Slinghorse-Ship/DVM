<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\{Database,Documents,DocumentCatalog,Finance,Inventory};
$n=0;
function check(bool $ok,string $message):void{global $n;if(!$ok)throw new RuntimeException($message);++$n;echo "PASS $message\n";}
function denied(callable $work,string $message):void{try{$work();}catch(DomainException){check(true,$message);return;}throw new RuntimeException($message);}
set_error_handler(static function(int $severity,string $message,string $file,int $line):bool{if(!(error_reporting()&$severity))return false;throw new ErrorException($message,0,$severity,$file,$line);});
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Synthetic A'),('b','Synthetic B');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('a','a','member',1),('b','b','member',1),('reader','a','member',0)");
$d=new Documents($db,'a');$f=new Finance($db,'a');$key=static fn()=>bin2hex(random_bytes(16));
$bill=['kind'=>'expense','category'=>'general','description'=>'Synthetic old invoice','amount'=>'42.00','document_date'=>'2022-04-04','due_date'=>'2022-04-30'];
$upload=static fn(array $extra=[])=>$d->upload('synthetic.xml','<Invoice xmlns="urn:synthetic:placement"/>',array_replace(['title'=>'Synthetic old original','kind'=>'receipt','scope'=>'finance','document_date'=>'2026-09-08','links'=>[]],$extra),$key());
$doc=$upload();$before=$d->document($doc);$fileBefore=$d->file($doc);
$mail='From: sender@example.invalid\r\nSubject: Synthetic original\r\n\r\nOriginal';$mailHash=hash('sha256',$mail);
$db->prepare("INSERT INTO invoice_mail_imports(club_id,content_hash,document_id,sender,subject,received_at,imported_at,message_uid,mailbox_key,message_id,original_date,mailbox_label) VALUES('a',?,?,'sender@example.invalid','Synthetic old invoice','2026-09-08','2026-09-08T10:00:00Z','1:1','synthetic','<synthetic@example.invalid>','Tue, 8 Sep 2026','Synthetic inbox')")->execute([$fileBefore['sha256'],$doc]);
$db->prepare("INSERT INTO invoice_mail_originals VALUES('a','synthetic','1:1',?,?,'2026-09-08T10:00:00Z')")->execute([$mailHash,$mail]);
$db->prepare("INSERT INTO invoice_mail_original_links VALUES('a',?,'synthetic','1:1')")->execute([$doc]);
$mailState=static fn()=>[$db->query('SELECT * FROM invoice_mail_imports')->fetchAll(),$db->query('SELECT * FROM invoice_mail_originals')->fetchAll(),$db->query('SELECT * FROM invoice_mail_original_links')->fetchAll()];$mailBefore=$mailState();
$entry=$f->createEntry($bill,$key(),fn($created)=>$d->linkExpense($doc,1,$created));$after=$d->document($doc);
check($before['path']==='/Finanzen/2026/Eingang - noch zuordnen','fixture starts in automatic import-year inbox');
check(str_starts_with($after['path'],'/Finanzen/2022/Ausgaben/'),'confirmed historical expense moves automatic intake to invoice-year folder');
check($after['document_date']==='2022-04-04'&&$d->source('entry',$entry)['document_date']==='2022-04-04','original metadata and finance source use confirmed invoice date');
check($after['folder_id']===$d->source('entry',$entry)['folder_id'],'original belongs to the exact finance record folder');
check($d->file($doc)===$fileBefore&&$after['revision']===$before['revision']&&$after['created_at']===$before['created_at'],'original bytes, revision and upload timestamp unchanged');
check($mailState()===$mailBefore,'mail dates, source bytes, import timestamps and links unchanged');
$q=$db->prepare("SELECT details FROM document_history WHERE document_id=? AND action='expense-linked'");$q->execute([$doc]);$audit=json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
check($audit['before']===['document_date'=>'2026-09-08','folder_id'=>$before['folder_id']]&&$audit['after']===['document_date'=>'2022-04-04','folder_id'=>$after['folder_id']],'audit records exact old and new date/folder');
$count=$db->query('SELECT COUNT(*) FROM document_history')->fetchColumn();$d->linkExpense($doc,1,$entry);
check($d->document($doc)===$after&&$db->query('SELECT COUNT(*) FROM document_history')->fetchColumn()===$count,'identical association replay changes no metadata, versions or audit');
$manual=DocumentCatalog::folder($db,'a',['Finanzen','Vereinseigene Ablage'],'finance');
$inventory=new Inventory($db,'a');$item=$inventory->saveItem('',0,['name'=>'Synthetic existing equipment'],$key());$itemSource=$d->source('item',$item);
$gema=DocumentCatalog::folder($db,'a',['Orga','GEMA','Originale'],'orga');
foreach(['manual'=>['folder'=>$manual],'inventory'=>['folder'=>$itemSource['folder_id'],'links'=>['item:'.$item]],'GEMA'=>['folder'=>$gema]] as $label=>$data){
 $id=$upload($data);$old=$d->document($id);$e=$f->createEntry($bill,$key());$d->linkExpense($id,1,$e);$new=$d->document($id);
 check($new['folder_id']===$old['folder_id']&&$new['path']===$old['path'],$label.' chosen folder remains unchanged');
 check($new['document_date']==='2022-04-04'&&str_starts_with($d->source('entry',$e)['path'],'/Finanzen/2022/Ausgaben/'),$label.' retains historical finance link and confirmed document date');
 check(count($new['links'])===count($old['links'])+1,$label.' existing links retained');
}
// Same label does not prove that a manually created intake was automatic.
$other=new Documents($db,'b');$otherFinance=new Finance($db,'b');$root=$other->saveFolder('',0,'Finanzen','','finance');$year=$other->saveFolder('',0,'2026',$root,'finance');$lookalike=$other->saveFolder('',0,'Eingang - noch zuordnen',$year,'finance');
$id=$other->upload('manual.xml','<Invoice/>',['title'=>'Manual lookalike','kind'=>'receipt','folder'=>$lookalike,'document_date'=>'2026-09-08'],$key());$e=$otherFinance->createEntry($bill,$key());$other->linkExpense($id,1,$e);
check($other->document($id)['folder_id']===$lookalike,'manual folder with identical intake label is preserved');
$renamed=$upload();$r=$d->document($renamed);$folder=$d->folder($r['folder_id']);$d->saveFolder($folder['id'],$folder['version'],'Bewusst umbenannte Ablage',$folder['parent_id'],'finance');$e=$f->createEntry($bill,$key());$d->linkExpense($renamed,1,$e);
check($d->document($renamed)['folder_id']===$r['folder_id'],'renamed automatic intake is treated as intentional');
$stale=$upload(['document_date'=>'2025-09-08']);$state=$d->document($stale);$entries=count($f->entries());
denied(fn()=>$f->createEntry($bill,$key(),fn($e)=>$d->linkExpense($stale,999,$e)),'stale original blocks association');
check($d->document($stale)===$state&&count($f->entries())===$entries,'failed association rolls back new expense and original placement');
denied(fn()=>(new Documents($db,'reader'))->linkExpense($stale,1,$entry),'unprivileged association denied');
$auditDoc=$upload(['document_date'=>'2025-09-08']);$state=$d->document($auditDoc);$entries=count($f->entries());
$db->exec("CREATE TRIGGER synthetic_placement_audit_failure BEFORE INSERT ON audit_events FOR EACH ROW BEGIN IF NEW.action='document.expense-linked' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic audit failure'; END IF; END");
try{$f->createEntry($bill,$key(),fn($e)=>$d->linkExpense($auditDoc,1,$e));throw new RuntimeException('Audit error was swallowed');}catch(PDOException){}$db->exec('DROP TRIGGER synthetic_placement_audit_failure');
check($d->document($auditDoc)===$state&&count($f->entries())===$entries,'audit failure rolls back date, folder, link and finance entry');
check(\Kulturhus\Verein\Tests\MySqlFixture::integrity($db)==='ok'&&!\Kulturhus\Verein\Tests\MySqlFixture::foreignKeyErrors($db),'database integrity preserved');
echo "$n invoice document placement checks passed; isolated memory database, no network or mail\n";
