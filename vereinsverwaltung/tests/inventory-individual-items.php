<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\{Database,Registry,Finance,Settings,Inventory,Documents};
set_error_handler(static function($severity,$message,$file,$line){throw new ErrorException($message,0,$severity,$file,$line);});
$tests=0;
function check(bool $ok,string $label):void {global $tests;if(!$ok)throw new RuntimeException($label);$tests++;echo "PASS $label\n";}
function denied(callable $fn,string $label,string $message=''):void {
    try{$fn();}catch(DomainException $e){check($message===''||str_contains($e->getMessage(),$message),$label);return;}
    throw new RuntimeException('Not denied: '.$label);
}
function sqlDenied(callable $fn,string $label):void {try{$fn();}catch(PDOException){check(true,$label);return;}throw new RuntimeException('Not denied: '.$label);}
$key=fn()=>bin2hex(random_bytes(16));

// Re-running native MySQL migration preserves existing quantities and non-unique serials.
$old=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');
Database::migrate($old);
$old->exec("INSERT INTO clubs VALUES('legacy','Legacy');INSERT INTO inventory_locations(club_id,id,name) VALUES('legacy','place','Lager');INSERT INTO inventory_items(club_id,id,code,name,kind,serial_number,unit_value_cents,value_note) VALUES('legacy','old','ALT-1','Vorhandene Bühnen','reusable','gleich',10000,'Altbestand'),('legacy','old2','ALT-2','Vorhandene Boxen','reusable','gleich',20000,'Altbestand');INSERT INTO inventory_stock(club_id,item_id,location_id,shelf) VALUES('legacy','old','place',4)");
$oldItems=$old->query('SELECT * FROM inventory_items ORDER BY id')->fetchAll();$oldStock=$old->query('SELECT * FROM inventory_stock')->fetchAll();
Database::migrate($old);Database::migrate($old);
$upgraded=$old->query('SELECT * FROM inventory_items ORDER BY id')->fetchAll();
check(array_map(fn($r)=>array_intersect_key($r,$oldItems[0]),$upgraded)===$oldItems&&$old->query('SELECT * FROM inventory_stock')->fetchAll()===$oldStock,'migration preserves all existing quantities, values, identities and references');
check(array_unique(array_column($upgraded,'tracking_mode'))===['quantity']&&array_unique(array_column($upgraded,'inventory_group_id'))===[''],'migration opts no existing records into individual tracking');

$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','A'),('b','B');INSERT INTO actors(id,club_id,role) VALUES('manager','a','members'),('other','b','members'),('inventory','a','members'),('reader','a','members');INSERT INTO actor_capabilities VALUES('manager','local_finance'),('other','local_finance')");
$i=new Inventory($db,'manager');$other=new Inventory($db,'other');$f=new Finance($db,'manager');$settings=new Settings($db,'manager');$reg=new Registry($db,'manager');
$person=$reg->saveContact(null,0,'person','Beispielperson','','');
$location=$i->saveLocation('',0,['name'=>'Vereinslager'],$key());$second=$i->saveLocation('',0,['name'=>'Nebenraum'],$key());$foreignLocation=$other->saveLocation('',0,['name'=>'Fremdes Lager'],$key());
$expenseData=['kind'=>'expense','description'=>'Bühnenkauf','amount'=>'300','document_date'=>'2026-09-01','due_date'=>'2026-09-01','category'=>'general'];
$expense=$f->createEntry($expenseData,$key());$expensive=$f->createEntry(array_replace($expenseData,['amount'=>'200']),$key());
$foreignExpense=(new Finance($db,'other'))->createEntry($expenseData,$key());
$counts=fn()=>array_map(fn($table)=>(int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn(),['inventory_items','inventory_stock','inventory_events','inventory_movements','audit_events','document_sources','finance_entries','finance_transactions']);
$common=['name'=>'Bühne','category'=>'Veranstaltung','location'=>$location,'unit_value'=>'100','value_note'=>'Kaufpreis je Einzelstück laut Rechnung','source_kind'=>'expense','source_id'=>$expense,'acquired_on'=>'2026-09-01','responsible_id'=>$person,'notes'=>'Gemeinsames Zubehör'];
$rows=[['code'=>'B-1','name'=>'Bühne 1','serial_number'=>'SN-1'],['code'=>'B-2','name'=>'Bühne 2','serial_number'=>'SN-2'],['code'=>'B-3','name'=>'Bühne 3','serial_number'=>''],['code'=>'B-4','name'=>'Bühne 4','serial_number'=>'SN-4','unit_value'=>'200','source_id'=>$expensive,'acquired_on'=>'2026-09-02','location'=>$second,'notes'=>'Eigene Tragetasche']];
$before=$counts();$request=$key();$group=$i->createIndividualItems($common,$rows,$request);$items=$i->items(['group'=>$group]);
check(preg_match('/^[a-f0-9]{32}$/D',$group)===1&&count($items)===4,'batch returns a group with exactly four individually identifiable pieces');
check(array_column($items,'total')===[1,1,1,1]&&array_unique(array_column($items,'tracking_mode'))===['individual'],'each new piece starts with one unit of real physical stock');
check($i->assetsOverview()['total']===50000,'three pieces at 100 and one at 200 total 500 without averaging');
check($items[3]['unit_value_cents']===20000&&$items[3]['source_entry']===$expensive&&$items[3]['acquired_on']==='2026-09-02'&&$i->stock($items[3]['id'])[0]['location_id']===$second,'per-row price invoice date and actual location override shared defaults');
check($items[0]['notes']==='Gemeinsames Zubehör'&&$items[3]['notes']==='Eigene Tragetasche','common text defaults and per-piece overrides are preserved');
check(count($i->linkedItems('expense',$expense))===3&&count($i->linkedItems('expense',$expensive))===1,'several pieces may reference the same invoice without duplicating the invoice');
$after=$counts();check(array_slice($before,6)===array_slice($after,6),'inventory creation creates neither financial entries nor money movements');
check($i->createIndividualItems($common,$rows,$request)===$group&&$counts()===$after,'replayed confirmed submission returns the original group without duplicate stock history or documents');
denied(fn()=>$i->createIndividualItems($common,array_replace($rows,[0=>['code'=>'CHANGED']]),$request),'changed replay payload is rejected','anderen Eingaben');
check($other->items(['group'=>$group])===[],'group filtering cannot read another club');
check(count((new Documents($db,'manager'))->source('item',$items[0]['id']))>0&&count($i->itemHistory($items[0]['id']))===1&&count($i->movements($items[0]['id']))===1,'each piece has its own document location metadata history and receipt movement');

$failureBefore=$counts();
denied(fn()=>$i->createIndividualItems($common,[['code'=>'ROLLBACK-1'],['code'=>'b-1']],$key()),'existing case-insensitive inventory number rejects the entire batch with its row','Einzelstück 2:');
check($counts()===$failureBefore,'bad second row rolls back earlier piece stock documents audit and group event');
denied(fn()=>$i->createIndividualItems($common,[['code'=>'DUP'],['code'=>'dup']],$key()),'duplicate inventory numbers inside batch identify offending row','Einzelstück 2:');
denied(fn()=>$i->createIndividualItems($common,[['code'=>'SER-1','serial_number'=>'PAIR'],['code'=>'SER-2','serial_number'=>'pair']],$key()),'duplicate serials inside batch identify offending row','Einzelstück 2:');
denied(fn()=>$i->createIndividualItems($common,[['code'=>'SER-3','serial_number'=>'sn-1']],$key()),'existing serial is not reusable under another inventory number','Seriennummer');
denied(fn()=>$i->createIndividualItems($common,[['code'=>'NO-PLACE','location'=>'']],$key()),'explicit blank location does not silently use common location','Lagerort');
denied(fn()=>$i->createIndividualItems($common,[['code'=>'FOREIGN','location'=>$foreignLocation]],$key()),'foreign warehouse is rejected with row','Einzelstück 1:');
denied(fn()=>$i->createIndividualItems($common,[['code'=>'FOREIGN-BILL','source_id'=>$foreignExpense]],$key()),'foreign invoice is rejected with row','Einzelstück 1:');
denied(fn()=>$i->createIndividualItems($common,[['code'=>'NO-CODE'],['name'=>'Ungültige Nummer','code'=>['array']]],$key()),'malformed inventory number is rejected','Einzelstück 2:');
denied(fn()=>$i->createIndividualItems($common,[['code'=>'BAD-DATE','acquired_on'=>'2026-02-30']],$key()),'invalid per-piece acquisition date is rejected','Datum');
denied(fn()=>$i->createIndividualItems($common,[['code'=>'CONSUME','kind'=>'consumable']],$key()),'quantity consumables cannot masquerade as individual pieces','Verbrauchsmaterial');
denied(fn()=>$i->createIndividualItems($common,[],$key()),'empty batch rejected');
denied(fn()=>$i->createIndividualItems($common,array_fill(0,51,['code'=>'OVER']),$key()),'batch above fifty pieces rejected');
check($counts()===$failureBefore,'all rejected batches leave database counts unchanged');
$db->exec("CREATE TRIGGER fail_individual_audit BEFORE INSERT ON audit_events FOR EACH ROW BEGIN IF NEW.action='inventory.receive' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test audit failure'; END IF; END");
sqlDenied(fn()=>$i->createIndividualItems($common,[['code'=>'AUDIT-FAIL']],$key()),'audit failure aborts batch');$db->exec('DROP TRIGGER fail_individual_audit');
check($counts()===$failureBefore,'audit failure leaves no partial item stock metadata or group');

$inventoryRole=$settings->saveRole('',0,['name'=>'Lager pflegen','members_access'=>'none','finance_access'=>'none','inventory_access'=>'write','loans_access'=>'write','settings_access'=>false]);$settings->assignRole('inventory',$inventoryRole,0);
$readerRole=$settings->saveRole('',0,['name'=>'Lager ansehen','members_access'=>'none','finance_access'=>'none','inventory_access'=>'read','loans_access'=>'read','settings_access'=>false]);$settings->assignRole('reader',$readerRole,0);
$writer=new Inventory($db,'inventory');$reader=new Inventory($db,'reader');$plain=['name'=>'Gerät','location'=>$location];
$plainGroup=$writer->createIndividualItems($plain,[['code'=>'NONE-1','source_kind'=>'none'],['code'=>'NONE-2','source_kind'=>'keep']],$key());
check(count($writer->items(['group'=>$plainGroup]))===2,'inventory writer without finance may create pieces with no source or keep default');
check(count(array_filter($i->items(['status'=>'all']),fn($r)=>$r['tracking_mode']==='individual'&&$r['serial_number']===''))>=3,'several unknown serial numbers may remain empty while inventory numbers identify each piece');
check(!isset($reader->item($items[0]['id'])['unit_value_cents'])&&!isset($reader->item($items[0]['id'])['source_entry']),'individual values and financial links remain hidden from non-finance readers');
check(!str_contains(json_encode($reader->itemHistory($items[0]['id'])),$expense)&&!str_contains(json_encode($reader->movements($items[0]['id'])),$expense),'piece history and movements do not expose purchase links without finance permission');
denied(fn()=>$reader->createIndividualItems($plain,[['code'=>'READONLY']],$key()),'read-only inventory user cannot create pieces');
denied(fn()=>$writer->createIndividualItems($plain,[['code'=>'NO-FINANCE','unit_value'=>'100','value_note'=>'Beleg']],$key()),'inventory writer cannot set a financial value without financial permission','Finanzberechtigung');
denied(fn()=>$writer->createIndividualItems($plain,[['code'=>'NO-LINK','source_kind'=>'expense','source_id'=>$expense]],$key()),'inventory writer cannot set a financial source without financial permission','Finanzberechtigung');
$free=$i->createIndividualItems($plain,[['code'=>'FREE','unit_value'=>'0','value_note'=>'Ohne Kaufpreis'],['code'=>'UNKNOWN','unit_value'=>'','value_note'=>'Kein Nachweis']],$key());$freeItems=$i->items(['group'=>$free]);
check($freeItems[0]['unit_value_cents']===0&&$freeItems[1]['unit_value_cents']===null,'zero value and unknown value remain different');
$goods=$f->createGoods(['donor'=>'Beispielspende','description'=>'Ein Gerät','received_on'=>'2026-09-01','amount'=>'10','valuation'=>'Beispielnachweis'],$key());
$donated=$i->createIndividualItems($plain,[['code'=>'GOODS','source_kind'=>'goods','source_id'=>$goods,'unit_value'=>'10','value_note'=>'Sachspende']],$key());
check($i->items(['group'=>$donated])[0]['source_goods']===$goods&&count($i->linkedItems('goods',$goods))===1,'individual piece may reference an existing goods donation');

$id=$items[0]['id'];$move=fn($kind,$n,$place,$extra=[])=>$i->move($id,(int)$i->item($id)['stock_version'],['kind'=>$kind,'quantity'=>(string)$n,'location'=>$place,'reason'=>'Bestand bestätigt']+$extra,$key());
denied(fn()=>$move('receive',1,$second),'second receipt cannot duplicate one physical piece across warehouses','Einzelstück');
denied(fn()=>$move('count',1,$second),'count cannot create a second copy at another warehouse','Einzelstück');
$move('transfer',1,$location,['destination'=>$second]);check($i->stock($id,$second)[0]['shelf']===1&&$i->stock($id,$location)[0]['shelf']===0,'transfer preserves single identity and moves actual location');
$loan=$i->reserve(['contact'=>$person,'purpose'=>'Testaufbau','starts_on'=>'2026-09-01','due_on'=>'2026-09-07','lines'=>[['item'=>$id,'location'=>$second,'quantity'=>'1','stock_version'=>(string)$i->item($id)['stock_version']]]],$key());
check($i->items(['group'=>$group])[0]['total']===1&&$i->items(['group'=>$group])[0]['reserved']===1,'reservation does not double count the physical piece');
denied(fn()=>$move('transfer',1,$second,['destination'=>$location]),'reserved piece cannot be moved away');
$i->transition($loan,1,'issue',['date'=>'2026-09-02','reason'=>'Übergeben','current_place'=>'Testsaal'],$key());
denied(fn()=>$move('receive',1,$location),'issued piece prevents a duplicate receipt at home','Einzelstück');
denied(fn()=>$move('count',1,$location),'issued piece prevents a duplicate inventory count at home','Einzelstück');
$line=$i->loan($loan)['lines'][0]['id'];$i->returnLoan($loan,2,['date'=>'2026-09-03','reason'=>'Einzeln geprüft','lines'=>[['line'=>$line,'damaged'=>'1','location'=>$location]]],$key());
check($i->items(['group'=>$group])[0]['blocked']===1&&$i->items(['group'=>$group])[0]['total']===1&&$i->loan($loan)['state']==='returned','damaged return to another warehouse preserves one physical piece');
denied(fn()=>$move('receive',1,$second),'blocked piece prevents duplicate receipt','Einzelstück');
$move('repair',1,$location);check($i->items(['group'=>$group])[0]['available']===1,'repair restores the same piece to available stock');

// Losing the expensive piece removes its own 200 value, not an averaged 125.
$expensiveId=$items[3]['id'];$i->move($expensiveId,(int)$i->item($expensiveId)['stock_version'],['kind'=>'block','quantity'=>'1','location'=>$second,'reason'=>'Defekt'],$key());
$i->move($expensiveId,(int)$i->item($expensiveId)['stock_version'],['kind'=>'discard','quantity'=>'1','location'=>$second,'reason'=>'Nicht reparierbar'],$key());
$groupAssets=array_filter($i->assetsOverview()['rows'],fn($r)=>$r['inventory_group_id']===$group);
check(array_sum(array_column($groupAssets,'documented_total'))===30000&&array_sum(array_column($i->items(['group'=>$group]),'total'))===3,'discarding the 200 piece leaves three identifiable 100 pieces valued at 300');
$fresh=$i->item($id);$edit=['code'=>$fresh['code'],'name'=>'Bühne einzeln umbenannt','serial_number'=>$fresh['serial_number']];
$i->saveItem($id,(int)$fresh['version'],$edit,$key());check($i->item($id)['tracking_mode']==='individual'&&$i->item($id)['inventory_group_id']===$group,'ordinary metadata edit preserves individual mode and grouping');
denied(fn()=>$i->saveItem($id,(int)$i->item($id)['version'],$edit+['tracking_mode'=>'quantity'],$key()),'individual tracking cannot be disabled through metadata save');
denied(fn()=>$i->saveItem($id,(int)$i->item($id)['version'],array_replace($edit,['serial_number'=>'SN-2']),$key()),'editing serial cannot reuse another individual serial');
sqlDenied(fn()=>$db->exec("UPDATE inventory_stock SET shelf=2 WHERE club_id='a' AND item_id='$id' AND location_id='$location'"),'database protects individual maximum even outside service');
sqlDenied(fn()=>$db->exec("UPDATE inventory_items SET tracking_mode='quantity' WHERE club_id='a' AND id='$id'"),'database prevents silent individual-to-quantity conversion');
sqlDenied(fn()=>$db->exec("UPDATE inventory_items SET serial_number='sn-2' WHERE club_id='a' AND id='$id'"),'database serial uniqueness also protects writes outside service');
$beforeReplay=$counts();$db->exec("UPDATE actors SET active=0 WHERE id='manager'");
denied(fn()=>$i->createIndividualItems($common,$rows,$request),'revoked actor permission is checked before request replay');$db->exec("UPDATE actors SET active=1 WHERE id='manager'");
check($counts()===$beforeReplay,'denied replay leaves existing records unchanged');
$legacy=$writer->saveItem('',0,['code'=>'inv-000050','name'=>'Legacy reserved number'],$key());$writer->archiveItem($legacy,1,0,['reason'=>'Synthetic archive'],$key());
$existingCodes=$db->query("SELECT id,code FROM inventory_items WHERE club_id='a' ORDER BY id")->fetchAll();$autoKey=$key();$autoRows=[['name'=>'Automatic one'],['name'=>'Automatic two','code'=>'']];$autoGroup=$writer->createIndividualItems($plain,$autoRows,$autoKey);$autoItems=$writer->items(['group'=>$autoGroup]);
check(array_column($autoItems,'code')===['INV-000051','INV-000052'],'server allocates free sequential codes beyond archived case-insensitive legacy code');
check($writer->createIndividualItems($plain,$autoRows,$autoKey)===$autoGroup&&count($writer->items(['group'=>$autoGroup]))===2,'automatic allocation replay keeps the same identities and numbers');
foreach($existingCodes as $existing)check($writer->item($existing['id'])['code']===$existing['code'],'automatic allocation never renumbers existing item');
$rollbackBefore=$counts();denied(fn()=>$writer->createIndividualItems($plain,[['name'=>'Auto rollback'],['name'=>'Invalid location','location'=>'missing']],$key()),'failed automatic batch rolls back allocation');check($counts()===$rollbackBefore,'failed automatic allocation leaves no items or stock');
$nextAuto=$writer->createIndividualItems($plain,[['name'=>'After rollback']],$key());check($writer->items(['group'=>$nextAuto])[0]['code']==='INV-000053','rolled back number remains available');
$quantityKey=$key();$quantityData=['name'=>'Automatic quantity','unit'=>'Stück'];$quantity=$writer->saveItem('',0,$quantityData,$quantityKey);$quantityItem=$writer->item($quantity);check($quantityItem['code']==='INV-000054'&&$quantityItem['tracking_mode']==='quantity','quantity creation gets a free automatic number from shared namespace');check($writer->saveItem('',0,$quantityData,$quantityKey)===$quantity&&$writer->item($quantity)['code']==='INV-000054','quantity replay preserves generated number');$writer->saveItem($quantity,$quantityItem['version'],['name'=>'Edited quantity','code'=>$quantityItem['code']],$key());check($writer->item($quantity)['code']===$quantityItem['code'],'editing existing quantity preserves its code');
$fiftyRows=array_map(fn($n)=>['code'=>'FIFTY-'.$n,'name'=>'Teststück '.$n],range(1,50));
$fifty=$writer->createIndividualItems($plain,$fiftyRows,$key());
check(count($writer->items(['group'=>$fifty]))===50&&array_sum(array_column($writer->items(['group'=>$fifty]),'total'))===50,'maximum supported batch of fifty distinct pieces succeeds atomically');
$balances=$db->query('SELECT s.shelf,s.reserved,s.lent,s.blocked,SUM(m.shelf_delta) a,SUM(m.reserved_delta) b,SUM(m.lent_delta) c,SUM(m.blocked_delta) d FROM inventory_stock s JOIN inventory_movements m ON m.club_id=s.club_id AND m.item_id=s.item_id AND m.location_id=s.location_id GROUP BY s.club_id,s.item_id,s.location_id')->fetchAll();
check(count(array_filter($balances,fn($r)=>(int)$r['shelf']!==(int)$r['a']||(int)$r['reserved']!==(int)$r['b']||(int)$r['lent']!==(int)$r['c']||(int)$r['blocked']!==(int)$r['d']))===0,'all individual movements reconcile to actual stock at each location');
echo "$tests individual inventory checks passed\n";
