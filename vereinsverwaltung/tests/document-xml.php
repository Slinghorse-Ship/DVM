<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\{Database,Documents};
$checks=0;
function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);++$checks;echo "PASS $label\n";}
function denied(callable $f,string $label):void{try{$f();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
$xml="<?xml version=\"1.0\" encoding=\"UTF-8\"?>\r\n<Invoice xmlns=\"urn:synthetic:invoice\"><ID>TEST-1</ID><Name>Muster &amp; Beispiel</Name></Invoice>\r\n";
$inspected=Documents::inspectFile('rechnung.xml',$xml);
check($inspected[1]==='application/xml'&&$inspected[2]===hash('sha256',$xml),'XML original has a dedicated MIME type and exact byte hash');
denied(fn()=>Documents::inspectFile('rechnung.xml','<Invoice>'),'malformed XML rejected');
denied(fn()=>Documents::inspectFile('rechnung.xml','<!DOCTYPE i SYSTEM "file:///never-read"><i/>'),'external DTD rejected before parsing');
denied(fn()=>Documents::inspectFile('rechnung.xml','<!DOCTYPE i [<!ENTITY e SYSTEM "https://example.invalid/never-fetch">]><i>&e;</i>'),'external entities rejected without network');
denied(fn()=>Documents::inspectFile('rechnung.xml','<!DOCTYPE i [<!ENTITY a "x"><!ENTITY b "&a;&a;">]><i>&b;</i>'),'internal entity expansion rejected');
denied(fn()=>Documents::inspectFile('rechnung.xml',"\xff\xfe<\0i\0/\0>\0"),'unsupported UTF-16 cannot hide a DTD from the byte check');
denied(fn()=>Documents::inspectFile('rechnung.xml','<?php echo "test"; ?>'),'PHP text is not an XML document');
denied(fn()=>Documents::inspectFile('rechnung.php',$xml),'XML content cannot authorize executable extension');
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Synthetic A'),('b','Synthetic B');INSERT INTO actors(id,club_id,role) VALUES('finance','a','treasurer'),('foreign','b','treasurer'),('member','a','member')");
$documents=new Documents($db,'finance');
$id=$documents->upload('rechnung.xml',$xml,['title'=>'XML-Testoriginal','kind'=>'receipt','scope'=>'finance','document_date'=>'2026-09-07','links'=>[]],bin2hex(random_bytes(16)));
check($documents->file($id)['bytes']===$xml,'XML archive retains original encoding declaration, CRLF and bytes');
$next=str_replace('TEST-1','TEST-2',$xml);$documents->newVersion($id,1,'rechnung.xml',$next,'Synthetic corrected document');
check($documents->file($id,1)['bytes']===$xml&&$documents->file($id,2)['bytes']===$next,'earlier XML file version remains available unchanged');
denied(fn()=>(new Documents($db,'member'))->file($id),'member cannot read finance XML');
denied(fn()=>(new Documents($db,'foreign'))->file($id),'other club cannot read XML');
check(\Kulturhus\Verein\Tests\MySqlFixture::integrity($db)==='ok'&&!(\Kulturhus\Verein\Tests\MySqlFixture::foreignKeyErrors($db)[0]??false),'XML history preserves database integrity');
echo "$checks XML archive checks passed; no network or mail\n";
