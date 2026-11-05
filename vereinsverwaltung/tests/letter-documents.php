<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\{Database,Documents,Settings,LetterDocuments};
$checks=0;function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);++$checks;}
function denied(callable $call,string $label):void{try{$call();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Synthetischer Verein');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('admin','a','member',1),('reader','a','member',0)");
$settings=new Settings($db,'admin');$docs=new Documents($db,'admin');$reader=new Documents($db,'reader');
$role=$settings->saveRole('',0,['name'=>'Dokumente lesen','members_access'=>'none','finance_access'=>'none','documents_access'=>'read']);$settings->assignRole('reader',$role,0);
$input=['recipient'=>"Synthetische Empfängerin\nTestweg 1\n00000 Testort",'subject'=>'Synthetischer Ausgangstest','body'=>'Guten Tag, dies ist ein lokaler Testbrief.','date'=>'2026-09-05'];
$key=bin2hex(random_bytes(16));$id=LetterDocuments::create($docs,$settings,$input,$key);$letter=$docs->document($id);
check($letter['path']==='/Orga/2026/Ausgang - Briefe','generated letter belongs to outbox');
check(str_starts_with($docs->file($id)['bytes'],'%PDF-'),'stored output is a PDF');
check(LetterDocuments::create($docs,$settings,$input,$key)===$id,'repeated form submission returns the same document');
check(count($docs->listing())===1,'repeated submission does not duplicate letter');
denied(fn()=>LetterDocuments::create($docs,$settings,array_replace($input,['body'=>'Geändert']),$key),'request key cannot be reused for different text');
denied(fn()=>LetterDocuments::create($reader,new Settings($db,'reader'),$input,bin2hex(random_bytes(16))),'read-only role cannot create letter');
check((int)$db->query('SELECT COUNT(*) FROM finance_entries')->fetchColumn()===0,'letter does not create a financial entry');
$upload=$docs->upload('Test.txt','Synthetischer Eingang',['title'=>'Synthetischer Eingang','kind'=>'other','scope'=>'orga','document_date'=>'2026-09-05'],bin2hex(random_bytes(16)));
check(str_ends_with($docs->document($upload)['path'],'/Eingang - noch zuordnen'),'ordinary uploaded document remains in inbox');
check(count($reader->listing())>=1&&count($reader->folders())>=1,'bulk reads include authorized documents and folders');
check($reader->writableSources()===[]&&$reader->writableFolders()===[],'read permission does not become a writable selection');
$settings->saveRole($role,1,['name'=>'Dokumente gesperrt','members_access'=>'none','finance_access'=>'none','documents_access'=>'none']);
check($reader->listing()===[]&&$reader->folders()===[],'same service rechecks role after a completed bulk read');
denied(fn()=>$reader->file($upload),'revoked access also blocks download');
$oldPath=$docs->document($upload)['path'];$folder=$docs->folder($docs->document($upload)['folder_id']);
$docs->folders();$docs->saveFolder($folder['id'],(int)$folder['version'],'Umbenannter Testeingang',$folder['parent_id'],$folder['scope']);
check($docs->document($upload)['path']!==$oldPath&&str_ends_with($docs->listing(['q'=>'Synthetischer Eingang'])[0]['path'],'/Umbenannter Testeingang'),'folder snapshot never survives into the next operation');
echo "$checks letter and document read-context checks passed\n";
