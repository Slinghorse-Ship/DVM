<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';require __DIR__.'/../src/Inventory/InventoryInvoiceUpload.php';
use Kulturhus\Verein\{Database,Inventory,Finance,Documents,InvoiceInbox,InventoryInvoiceUpload};
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Synthetic'),('b','Other');INSERT INTO actors(id,club_id,role) VALUES('manager','a','members'),('inventory','a','members'),('cash','a','treasurer'),('member','a','member'),('other','b','members');INSERT INTO actor_capabilities VALUES('manager','local_finance'),('other','local_finance')");
$n=0;function check($ok,$why){global $n;if(!$ok)throw new RuntimeException($why);++$n;echo "PASS $why\n";}function denied($fn,$why){try{$fn();}catch(DomainException){check(true,$why);return;}throw new RuntimeException($why);}
$key=fn()=>bin2hex(random_bytes(16));$i=new Inventory($db,'manager');$f=new Finance($db,'manager');$docs=new Documents($db,'manager');$up=new InventoryInvoiceUpload($db,'manager');
$entry=$f->createEntry(['kind'=>'expense','description'=>'Synthetic original purchase','amount'=>'25','category'=>'culture','document_date'=>'2026-09-07','due_date'=>'2026-09-30'],$key());
$item=$i->saveItem('',0,['code'=>'SYN-01','name'=>'Synthetic item','source'=>'expense:'.$entry],$key());$before=$i->item($item);
$files=[['name'=>'first.xml','bytes'=>'<Invoice/>','title'=>'First original','document_date'=>'2026-08-01','scope'=>'orga','links'=>['contact:invalid'],'folder'=>'invalid'],['name'=>'second.xml','bytes'=>'<Invoice><ID>2</ID></Invoice>','title'=>'Second original','document_date'=>'2026-08-02']];
$request=$key();$ids=$up->upload($item,$files,['kind'=>'other','scope'=>'orga','links'=>[]],$request);
check(count($ids)===2,'two independent documents');check(count($f->entries([]))===1,'no new expense');check($i->item($item)===$before,'purchase source and inventory unchanged');
foreach($ids as $x=>$id){$doc=$docs->document($id);check($doc['title']===$files[$x]['title']&&$doc['document_date']===$files[$x]['document_date'],'individual metadata '.$x);check($doc['kind']==='receipt'&&$doc['scopes']===['finance','inventory'],'enforced scope '.$x);check(count($doc['links'])===1&&$doc['links'][0]['source_type']==='item'&&$doc['links'][0]['source_id']===$item,'enforced item '.$x);check((new InvoiceInbox($db,'manager'))->document($id)['inbox_status']==='open','unbooked inbox '.$x);}
check($up->upload($item,$files,['kind'=>'other','scope'=>'orga','links'=>[]],$request)===$ids,'replay returns same IDs');check(count($docs->listing())===2,'replay no duplicates');
foreach(['inventory','cash','member','other'] as $actor)denied(fn()=>(new InventoryInvoiceUpload($db,$actor))->upload($item,$files,[],$key()),'unauthorized upload '.$actor);
foreach(['inventory','cash','member','other'] as $actor)denied(fn()=>(new Documents($db,$actor))->document($ids[0]),'private receipt '.$actor);
denied(fn()=>$up->upload($item,[['name'=>'valid.xml','bytes'=>'<Invoice/>'],['name'=>'shell.php','bytes'=>'<?php echo 1;']],[],$key()),'executable extension rejected');
denied(fn()=>$up->upload($item,[['name'=>'valid.xml','bytes'=>'<Invoice/>'],['name'=>'bad.xml','bytes'=>'not xml']],[],$key()),'invalid second file rejected');check(count($docs->listing())===2,'failed batch stores no partial files');
denied(fn()=>$up->upload($item,[['name'=>'valid.xml','bytes'=>'<Invoice/>','title'=>'Good'],['name'=>'second.xml','bytes'=>'<Invoice/>','title'=>'']],[],$key()),'second metadata invalid');check(count($docs->listing())===2,'late failure rolls back first store');
$db->prepare('UPDATE inventory_items SET active=0 WHERE id=?')->execute([$item]);denied(fn()=>$up->upload($item,$files,[],$request),'archive blocks replay');$db->prepare('UPDATE inventory_items SET active=1 WHERE id=?')->execute([$item]);
denied(fn()=>$docs->uploadMany([['name'=>'race.xml','bytes'=>'<Invoice/>']],['kind'=>'receipt','links'=>['item:'.$item]],$key(),function()use($db,$item,$up){$db->prepare('UPDATE inventory_items SET active=0 WHERE id=?')->execute([$item]);$up->authorize($item);}), 'locked archive recheck blocks store');check($i->item($item)['active']==1&&count($docs->listing())===2,'failed callback atomic rollback');
$db->exec("DELETE FROM actor_capabilities WHERE actor_id='manager' AND capability='local_finance'");denied(fn()=>$up->upload($item,$files,[],$request),'revoked writer blocks replay');
echo "$n inventory invoice upload checks passed; in-memory synthetic data only\n";
