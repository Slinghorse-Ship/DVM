<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\{Database,Inventory,Registry};
set_error_handler(static function($severity,$message,$file,$line){throw new ErrorException($message,0,$severity,$file,$line);});
$checks=0;
function check(bool $ok,string $label):void {global $checks;if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";}
function denied(callable $fn,string $label):void {try{$fn();}catch(DomainException){check(true,$label);return;}throw new RuntimeException('Not denied: '.$label);}
function rows(PDO $db,string $table):array{return \Kulturhus\Verein\Tests\MySqlFixture::rows($db,$table);}
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Synthetic club');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('writer','a','member',1),('reader','a','member',0)");
$inventory=new Inventory($db,'writer');$key=static fn()=>bin2hex(random_bytes(16));
$location=$inventory->saveLocation('',0,['name'=>'Synthetic storage'],$key());$contact=(new Registry($db,'writer'))->saveContact(null,0,'person','Synthetic borrower','','');
$create=['name'=>'Synthetic platform feet','unit'=>'Stück','content_quantity'=>'4','content_unit'=>'Stück','home_location'=>$location,'initial_quantity'=>'4','value_note'=>'Synthetic original invoice','purchase_units'=>[['unit_value'=>'35,90','serial_number'=>'S1'],['unit_value'=>'35,90'],['unit_value'=>'35,90'],['unit_value'=>'37,90']]];
$id=$inventory->saveItem('',0,$create,$key());
$reserve=static fn()=>$inventory->reserve(['contact'=>$contact,'purpose'=>'Synthetic event','starts_on'=>'2026-09-01','due_on'=>'2026-09-10','lines'=>[['item'=>$id,'location'=>$location,'quantity'=>'1','stock_version'=>(string)$inventory->item($id)['stock_version']]]],$key());
$issued=$reserve();$inventory->transition($issued,1,'issue',['date'=>'2026-09-01','reason'=>'Complete set handed over','current_place'=>'Synthetic hall'],$key());$reserved=$reserve();
$inventory->move($id,$inventory->item($id)['stock_version'],['kind'=>'block','location'=>$location,'quantity'=>'1','reason'=>'Synthetic incomplete set'],$key());
$before=$inventory->item($id);$stock=rows($db,'inventory_stock');$moves=rows($db,'inventory_movements');$pieces=rows($db,'inventory_purchase_units');$loans=rows($db,'inventory_loans');$lines=rows($db,'inventory_loan_lines');$events=rows($db,'inventory_events');$value=$inventory->assetsOverview()['total'];
check($stock[0]['shelf']===2&&$stock[0]['reserved']===1&&$stock[0]['lent']===1&&$stock[0]['blocked']===1,'fixture includes available, reserved, issued and blocked sets');
$edit=['code'=>$before['code'],'name'=>$before['name'],'unit'=>'Satz'];$request=$key();$inventory->saveItem($id,$before['version'],$edit,$request);
$fresh=(new Inventory($db,'writer'))->item($id);
check($fresh['unit']==='Satz'&&$fresh['version']===$before['version']+1,'unit correction persists through a fresh service read');
check($fresh['content_quantity']===4&&$fresh['content_unit']==='Stück'&&$fresh['stock_version']===$before['stock_version'],'contents and stock version remain unchanged');
check(rows($db,'inventory_stock')===$stock&&rows($db,'inventory_movements')===$moves,'unit correction never creates or alters stock movements');
check(rows($db,'inventory_purchase_units')===$pieces&&$inventory->assetsOverview()['total']===$value,'serials, original purchase prices and valuation remain unchanged');
check(rows($db,'inventory_loans')===$loans&&rows($db,'inventory_loan_lines')===$lines,'open reservation and issued loan keep original unit/content snapshots');
check(array_slice(rows($db,'inventory_events'),0,count($events))===$events&&count(rows($db,'inventory_events'))===count($events)+1,'existing history remains intact and one metadata event records the correction');
check(Inventory::contentSummary($fresh,4)==='4 Sätze (je 4 Stück) = 16 Stück','current total displays the corrected set label without multiplying stock');
$where=$inventory->whereabouts(['item'=>$id]);$current=array_values(array_filter($where,static fn($row)=>$row['state']!=='issued'));$historical=array_values(array_filter($where,static fn($row)=>$row['state']==='issued'));
check(array_unique(array_column($current,'unit'))===['Satz']&&array_sum(array_column($where,'quantity'))===4,'current whereabouts use Satz and still total four inventory units');
check($historical[0]['unit']==='Stück'&&$historical[0]['content_quantity']===4,'already issued whereabouts preserve the handover snapshot');
$inventory->saveItem($id,$before['version'],$edit,$request);check($inventory->item($id)['version']===$fresh['version']&&count(rows($db,'inventory_events'))===count($events)+1,'retry is idempotent');
denied(fn()=>$inventory->saveItem($id,$before['version'],$edit,$key()),'stale unit edit rejected');
$omit=['code'=>$fresh['code'],'name'=>$fresh['name']];$inventory->saveItem($id,$fresh['version'],$omit,$key());$fresh=$inventory->item($id);
check($fresh['unit']==='Satz'&&$fresh['content_quantity']===4,'unrelated edit without unit does not fall back to Stück');
foreach(['invented unit',''] as $invalid){denied(fn()=>$inventory->saveItem($id,$fresh['version'],$omit+['unit'=>$invalid],$key()),'invalid or empty new unit rejected');}
denied(fn()=>$inventory->saveItem('',0,['name'=>'Invalid unit','unit'=>'invented unit'],$key()),'new item cannot invent a unit');
denied(fn()=>$inventory->saveItem($id,$fresh['version'],$omit+['active'=>'0'],$key()),'archiving with stock is still rejected');
denied(fn()=>$inventory->saveItem($id,$fresh['version'],$omit+['kind'=>'consumable'],$key()),'changing kind with stock is still rejected');
denied(fn()=>$inventory->saveItem($id,$fresh['version'],$omit+['tracking_mode'=>'individual'],$key()),'quantity tracking cannot be changed through a unit correction');
denied(fn()=>(new Inventory($db,'reader'))->saveItem($id,$fresh['version'],$edit,$key()),'unit correction still requires inventory write permission');
check(rows($db,'inventory_stock')===$stock&&rows($db,'inventory_purchase_units')===$pieces&&rows($db,'inventory_loan_lines')===$lines,'failed edits preserve stock, prices and loan snapshots');
$line=$inventory->loan($issued)['lines'][0];$inventory->returnLoan($issued,2,['date'=>'2026-09-02','reason'=>'Original full unit returned','lines'=>[['line'=>$line['id'],'good'=>'1','location'=>$location]]],$key());
check($inventory->stock($id)[0]['shelf']===3&&$inventory->stock($id)[0]['lent']===0&&count($inventory->purchaseUnits($id))===4,'old-unit loan returns exactly one stock unit after correction');
$newLoan=$reserve();check($inventory->loan($newLoan)['lines'][0]['unit']==='Satz','new reservations snapshot the corrected unit');
$group=$inventory->createIndividualItems(['name'=>'Synthetic individual set','location'=>$location],[['code'=>'ONE-SET','serial_number'=>'ONE-SERIAL']],$key());$single=$inventory->items(['group'=>$group])[0];
$inventory->saveItem($single['id'],$single['version'],['code'=>$single['code'],'name'=>$single['name'],'unit'=>'Satz'],$key());$single=$inventory->item($single['id']);
check($single['tracking_mode']==='individual'&&$single['inventory_group_id']===$group&&$single['serial_number']==='ONE-SERIAL'&&$single['stock'][0]['shelf']===1,'individual unit correction preserves group, serial and quantity one');
denied(fn()=>$inventory->move($single['id'],$single['stock_version'],['kind'=>'receive','location'=>$location,'quantity'=>'1','reason'=>'Would duplicate one piece'],$key()),'individual maximum remains enforced after unit correction');
// Historical free unit labels may survive unchanged, but cannot be newly introduced.
$db->prepare('UPDATE inventory_items SET unit=? WHERE club_id=? AND id=?')->execute(['Historical bundle','a',$single['id']]);$single=$inventory->item($single['id']);$inventory->saveItem($single['id'],$single['version'],['code'=>$single['code'],'name'=>$single['name']],$key());
check($inventory->item($single['id'])['unit']==='Historical bundle','omitted historical free unit is preserved without blocking unrelated edits');
$single=$inventory->item($single['id']);$inventory->saveItem($single['id'],$single['version'],['code'=>$single['code'],'name'=>$single['name'],'unit'=>'Satz'],$key());check($inventory->item($single['id'])['unit']==='Satz','historical label can be corrected to a standard unit');
check(\Kulturhus\Verein\Tests\MySqlFixture::integrity($db)==='ok'&&(\Kulturhus\Verein\Tests\MySqlFixture::foreignKeyErrors($db)[0]??false)===false,'integrity and foreign keys remain valid');
echo "$checks inventory unit correction checks passed\n";
