<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\{Database,Finance,Documents,FinanceBundle};
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','A'),('b','B');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('admin','a','members',1),('other','b','members',1),('member','a','member',0)");
$n=0;function check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);echo 'PASS '.$label."\n";++$n;}
$key=fn()=>bin2hex(random_bytes(16));$f=new Finance($db,'admin');$d=new Documents($db,'admin');
$bill=['kind'=>'expense','description'=>'=TEST()','amount'=>'42.50','document_date'=>'2026-09-01','due_date'=>'2026-09-10','category'=>'culture'];
$one=$f->createEntry($bill,$key());$two=$f->createEntry(array_replace($bill,['description'=>'No file','document_date'=>'2026-08-01']),$key());
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
$doc=$d->upload('rechnung.png',$png,['title'=>'Original invoice','kind'=>'invoice','document_date'=>'2026-09-01','links'=>['entry:'.$one]],$key());
(new \Kulturhus\Verein\InvoicePaymentReports($db,'admin'))->save($one,1,0,'2026-09-01','Synthetic reported transfer',$key());
$bundle=new FinanceBundle($db,'admin');$out=$bundle->build([]);check($out['count']===2&&$out['missing']===1,'missing originals counted');
$unzip=function($bytes){$p=tempnam(sys_get_temp_dir(),'kh-test-');file_put_contents($p,$bytes);$z=new ZipArchive();try{check($z->open($p)===true,'valid ZIP');$files=[];for($i=0;$i<$z->numFiles;$i++)$files[$z->getNameIndex($i)]=$z->getFromIndex($i);$z->close();return $files;}finally{unlink($p);}};
$files=$unzip($out['bytes']);$manifest=json_decode($files['zuordnung.json'],true,512,JSON_THROW_ON_ERROR);$entry=array_column($manifest['entries'],null,'id')[$one];$meta=$entry['documents'][0];
check($entry['payment_report']['state']==='reported'&&$entry['payment_state']==='open'&&count($entry['payment_report']['history'])===1,'payment report exported separately without false paid state');
check($files[$meta['path']]===$png,'original bytes preserved');check($meta['sha256']===hash('sha256',$png),'hash matches original');check($meta['revision']===1,'document version traced');check(str_contains($files['finanzliste.csv'],"'=TEST()"),'CSV formula injection escaped');
check($bundle->build(['from'=>'2026-09-01'])['count']===1,'date filter applied');
check((new FinanceBundle($db,'other'))->build([])['count']===0,'other club excluded');
try{(new FinanceBundle($db,'member'))->build([]);throw new RuntimeException('member access allowed');}catch(DomainException){check(true,'finance rights required');}
check(!$db->inTransaction(),'denied export leaves transaction closed');check($bundle->build([])['count']===2,'repeat export leaves entries unchanged');
echo "$n bundle checks passed\n";
