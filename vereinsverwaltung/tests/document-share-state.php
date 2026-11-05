<?php declare(strict_types=1);
require_once __DIR__.'/support/reviewed-document-share.php';
require __DIR__.'/../src/bootstrap.php';require __DIR__.'/../src/Web/View.php';
use Kulturhus\Verein\{Database,Documents,PortalDocuments,Registry};
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Synthetic A'),('b','Synthetic B');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('admin','a','members',1),('member','a','member',0),('other','b','members',1)");
$checks=0;function check(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);++$checks;echo "PASS $message\n";}
function denied(callable $run,string $message):void{try{$run();}catch(DomainException){check(true,$message);return;}throw new RuntimeException($message);}
$registry=new Registry($db,'admin');$documents=new Documents($db,'admin');$portal=new PortalDocuments($db,'admin');
$person=$registry->saveContact(null,0,'person','Alex Beispiel','','');$second=$registry->saveContact(null,0,'person','Kim Beispiel','','');
$db->prepare('UPDATE actors SET contact_id=? WHERE id=?')->execute([$person,'member']);
$docId=$documents->upload('test.txt','Version one',['title'=>'Synthetic file','scope'=>'orga','kind'=>'other'],bin2hex(random_bytes(16)));
$_SESSION=['actor'=>'admin','csrf'=>'synthetic'];$_POST=[];
function renderShares():string{global $db,$docId,$doc,$registry;ob_start();require __DIR__.'/../views/Documents/document-shares.php';return ob_get_clean();}
$doc=$documents->document($docId);check(str_contains(renderShares(),'noch für keine Person'),'initial state explicitly shows no grants');
ownReviewedDocumentShare($portal,$documents,$docId,$person,true);ownReviewedDocumentShare($portal,$documents,$docId,$person,true);
check(count($portal->documentShares($docId))===1,'repeated share creates one grant');
$html=renderShares();check(str_contains($html,'Alex Beispiel</strong>')&&str_contains($html,'Aktuelle Version freigegeben')&&$portal->documentShares($docId)[0]['revision']===1,'reopen shows persisted recipient and current saved revision status');
check(str_contains($html,'<option value="">Bitte Person auswählen</option>'),'new action requires conscious recipient selection');
$documents->newVersion($docId,(int)$documents->document($docId)['version'],'test.txt','Version two','Synthetic revision');$doc=$documents->document($docId);
check(str_contains(renderShares(),'Ältere Version bleibt freigegeben'),'new revision is not falsely displayed as shared');
check((new PortalDocuments($db,'member'))->file($docId)['bytes']==='Version one','existing recipient still sees explicitly shared revision');
ownReviewedDocumentShare($portal,$documents,$docId,$person,true);check((new PortalDocuments($db,'member'))->file($docId)['bytes']==='Version two','renewed grant serves current revision');
$_POST=['action'=>'portal-share','doc'=>$docId,'contact'=>$second,'enabled'=>'0'];$html=renderShares();
check(str_contains($html,'value="'.$second.'" selected')&&str_contains($html,'value="0" selected'),'failed revocation preserves person and action');
check(str_contains($html,'Alex Beispiel</strong>'),'saved list remains distinct from unsaved form draft');
$_POST['doc']='different';$html=renderShares();check(!str_contains($html,'value="'.$second.'" selected'),'unrelated form draft cannot select a recipient');
ownReviewedDocumentShare($portal,$documents,$docId,$person,false);check($portal->documentShares($docId)===[],'revocation disappears on reload');
denied(fn()=>(new PortalDocuments($db,'member'))->file($docId),'revoked recipient cannot download');
denied(fn()=>(new PortalDocuments($db,'member'))->documentShares($docId),'ordinary member cannot inspect other recipients');
denied(fn()=>(new PortalDocuments($db,'other'))->documentShares($docId),'foreign club cannot inspect recipients');
check(\Kulturhus\Verein\Tests\MySqlFixture::integrity($db)==='ok'&&!\Kulturhus\Verein\Tests\MySqlFixture::foreignKeyErrors($db),'integrity preserved');
echo "$checks document share state checks passed\n";
