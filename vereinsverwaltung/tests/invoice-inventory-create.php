<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\{Database,Documents,Finance,Inventory,InvoiceInbox,InvoiceInventoryDraft,InvoicePositionReview};
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Synthetic A'),('b','Synthetic B');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('admin','a','member',1),('foreign','b','member',1),('cash','a','treasurer',0),('member','a','member',0)");
$n=0;function check($ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);++$n;echo "PASS $label\n";}
function denied(callable $fn,string $label):void{try{$fn();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
$key=fn()=>bin2hex(random_bytes(16));$docs=new Documents($db,'admin');$finance=new Finance($db,'admin');$inventory=new Inventory($db,'admin');$inbox=new InvoiceInbox($db,'admin');
$location=$inventory->saveLocation('',0,['name'=>'Synthetic store'],$key());$foreignLocation=(new Inventory($db,'foreign'))->saveLocation('',0,['name'=>'Foreign store'],$key());
$original='<Invoice xmlns="urn:synthetic:test"/>';$upload=fn()=>$docs->upload('test.xml',$original,['title'=>'Synthetic joint invoice','kind'=>'receipt','scope'=>'finance','links'=>[]],$key());
$new=['name'=>'Synthetic platform','home_location'=>$location,'location_version'=>'1','unit'=>'Stück','content_quantity'=>'1','content_unit'=>'Stück','category'=>'Synthetic stage'];
$bill=['description'=>'Synthetic equipment invoice','amount'=>'1336','counterparty'=>'Synthetic supplier','document_date'=>'2026-09-08','due_date'=>'2026-09-30','category'=>'inventory','use_invoice_positions'=>'1','positions_reviewed'=>'1','invoice_positions'=>[
 ['description'=>'Pos. 1 · Synthetic platform','amount'=>'1196','tax_rate'=>'19','quantity'=>'4','unit'=>'Stück','unit_price'=>'299','price_basis'=>'gross','new_item'=>$new],
 ['description'=>'Pos. 2 · Synthetic feet','amount'=>'140','tax_rate'=>'19','quantity'=>'4','unit'=>'Satz','unit_price'=>'35','price_basis'=>'gross','new_item'=>array_replace($new,['name'=>'Synthetic feet','unit'=>'Satz','content_quantity'=>'4'])],
]];
$doc=$upload();$request=$key();$entry=$inbox->create($doc,1,$bill,$request,'new_item','SYNTHETIC');$items=$inventory->items();$byName=array_column($items,null,'name');$platform=$inventory->item($byName['Synthetic platform']['id']);$feet=$inventory->item($byName['Synthetic feet']['id']);
check(count($items)===2&&count($finance->entries())===1,'one save creates exactly one invoice and two quantity items');
check($platform['source_entry']===$entry&&$feet['source_entry']===$entry,'both items use the same existing invoice');
check($platform['stock'][0]['shelf']===4&&$feet['stock'][0]['shelf']===4,'initial stock comes from each reviewed line quantity');
check($platform['unit_value_cents']===29900&&$feet['unit_value_cents']===3500,'each gross unit value derived from its own line');
check($feet['content_quantity']===4&&$feet['unit']==='Satz'&&count($inventory->whereabouts())===2,'four sets of four parts appear immediately in whereabouts');
check(count(InvoicePositionReview::forEntry($db,'admin',$entry))===2,'both position assignments persist with item and stock');
check($docs->file($doc)['bytes']===$original&&count($docs->document($doc)['links'])===1,'original preserved without duplicate document or widened scope');
check($finance->entry($entry)['amount_cents']===133600&&$db->query('SELECT COUNT(*) FROM finance_transactions')->fetchColumn()===0,'single invoice gross total and no payment transaction');
check(InvoiceInventoryDraft::position($db,'admin',$entry,1)['defaults']['name']==='Synthetic platform','OCR position prefix not copied into inventory name');
check(InvoiceInventoryDraft::position($db,'admin',$entry,1)['existing']===$platform['id'],'later inventory draft offers the already-created item');
$counts=fn()=>array_map(fn($table)=>(int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn(),['finance_entries','accounting_positions','invoice_position_details','invoice_position_items','inventory_items','inventory_stock','inventory_movements','inventory_events','audit_events','document_links']);
$before=$counts();check($inbox->create($doc,1,$bill,$request,'new_item','SYNTHETIC')===$entry&&$counts()===$before,'double submit does not duplicate any invoice item stock or audit');
$changed=$bill;$changed['invoice_positions'][0]['new_item']['name']='Different name';denied(fn()=>$inbox->create($doc,1,$changed,$request,'new_item','SYNTHETIC'),'changed retry rejected instead of creating more items');
$changed=$bill;unset($changed['invoice_positions'][0]['new_item']);denied(fn()=>$inbox->create($doc,1,$changed,$request,'new_item','SYNTHETIC'),'retry cannot silently remove one requested item');
check($counts()===$before,'rejected retries preserve every saved record');
denied(fn()=>$inventory->createFromInvoiceInTransaction([]),'explicit joint-write endpoint requires caller transaction');
$ordinary=$bill;foreach($ordinary['invoice_positions'] as &$row)unset($row['new_item']);unset($row);$ordinaryDoc=$upload();$ordinaryKey=$key();$inbox->create($ordinaryDoc,1,$ordinary,$ordinaryKey,'new_item','ORDINARY');
denied(fn()=>$inbox->create($ordinaryDoc,1,$bill,$ordinaryKey,'new_item','ORDINARY'),'same request cannot append new items after an ordinary invoice save');

$attempt=function(array $data,string $label,string $actor='admin')use($upload,$key,$db,$docs,$counts):void{$d=$upload();$before=$counts();$beforeDoc=$docs->document($d);denied(fn()=>(new InvoiceInbox($db,$actor))->create($d,1,$data,$key(),'new_item','SYNTHETIC'),$label);check($counts()===$before&&$docs->document($d)['version']===$beforeDoc['version']&&!$docs->document($d)['links'],$label.' leaves invoice original and all inventory unchanged');};
$bad=$bill;$bad['invoice_positions'][1]['new_item']['home_location']='';$attempt($bad,'missing second location');
$bad=$bill;$bad['invoice_positions'][1]['new_item']['home_location']=$foreignLocation;$attempt($bad,'foreign second location');
$bad=$bill;$bad['invoice_positions'][1]['new_item']['location_version']='99';$attempt($bad,'stale second location');
$bad=$bill;$bad['invoice_positions'][0]['new_item']['unit']='Unknown';$attempt($bad,'unsupported inventory unit');
$bad=$bill;$bad['invoice_positions'][0]['new_item']['initial_quantity']='999';$attempt($bad,'forged independent stock field');
$bad=$bill;$bad['invoice_positions'][0]['new_item']['unit_value']='1';$attempt($bad,'forged independent unit price');
$bad=$bill;$bad['invoice_positions'][0]['items']=[$platform['id'].'|1'];$attempt($bad,'same position cannot create and link existing item');
$bad=$bill;$bad['positions_reviewed']='';$attempt($bad,'unreviewed invoice positions');
$bad=$bill;$bad['use_invoice_positions']='';$attempt($bad,'disabled invoice positions with new item data');
$bad=$bill;$bad['invoice_positions'][0]['quantity']='1.5';$bad['invoice_positions'][0]['unit_price']='100';$bad['invoice_positions'][0]['amount']='150';$bad['amount']='290';$attempt($bad,'fractional physical quantity');
$attempt($bill,'finance without inventory write','cash');$attempt($bill,'member without finance write','member');
$db->exec("CREATE TRIGGER fail_second_item BEFORE INSERT ON invoice_position_items FOR EACH ROW BEGIN IF NEW.ordinal=2 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic second item failure'; END IF; END");
$failedDoc=$upload();$before=$counts();try{$inbox->create($failedDoc,1,$bill,$key(),'new_item','FAIL');throw new RuntimeException('expected SQL failure');}catch(PDOException){check($counts()===$before&&!$docs->document($failedDoc)['links'],'second item failure rolls back first item, invoice, stock, catalog and audit');}
$db->exec('DROP TRIGGER fail_second_item');
$unknown=$bill;$unknown['invoice_positions'][0]['unit_price']='';$unknownEntry=$inbox->create($upload(),1,$unknown,$key(),'new_item','UNKNOWN');$unknownDraft=InvoiceInventoryDraft::position($db,'admin',$unknownEntry,1);$unknownItem=$inventory->item($unknownDraft['existing']);
check($unknownItem['unit_value_cents']===null&&str_contains($unknownItem['value_note'],'Kein eindeutiger Einzelwert'),'missing explicit unit price stays unknown; no average invented');
check(\Kulturhus\Verein\Tests\MySqlFixture::integrity($db)==='ok'&&!(\Kulturhus\Verein\Tests\MySqlFixture::foreignKeyErrors($db)[0]??false),'database and foreign keys intact');
echo "$n joint invoice/inventory checks passed; isolated database, no network/mail/payment\n";
