<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';require __DIR__.'/../src/Web/View.php';require_once __DIR__.'/../src/Auth/WorkspaceAccess.php';
use Kulturhus\Verein\{Database,Inventory,Finance};
set_error_handler(static function($severity,$message,$file,$line){if(error_reporting()&$severity)throw new ErrorException($message,0,$severity,$file,$line);return false;});
$checks=0;function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function denied(callable $run,string $label):void{try{$run();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
function snapshots(PDO $db):array{$out=[];foreach(['inventory_stock','inventory_purchase_units','inventory_movements'] as $table)$out[$table]=\Kulturhus\Verein\Tests\MySqlFixture::rows($db,$table);return $out;}
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);$db->exec("INSERT INTO clubs VALUES('a','Synthetic club');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('writer','a','member',1),('inventory','a','members',0)");
$inventory=new Inventory($db,'writer');$finance=new Finance($db,'writer');$documents=new \Kulturhus\Verein\Documents($db,'writer');$requestKey=static fn()=>bin2hex(random_bytes(16));$location=$inventory->saveLocation('',0,['name'=>'Synthetic storage'],$requestKey());
$itemId=$inventory->saveItem('',0,['name'=>'Synthetic feet','category'=>'Stage','unit'=>'Satz','content_quantity'=>'4','content_unit'=>'Stück','home_location'=>$location,'initial_quantity'=>'2','value_note'=>'Original invoice evidence','purchase_units'=>[['unit_value'=>'35,00'],['unit_value'=>'39,00']]],$requestKey());
$_SESSION=['actor'=>'writer','csrf'=>'synthetic'];$_GET=['view'=>'inventory','tab'=>'items','item'=>$itemId];$_POST=[];$tab='items';$view='inventory';$canFinance=true;$workspaceAccess=new \Kulturhus\Verein\WorkspaceAccess($db,'writer');
ob_start();require __DIR__.'/../views/Inventory/inventory.php';$html=ob_get_clean();$dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$html);$xp=new DOMXPath($dom);$form='//form[input[@name="action"][@value="inventory-item"]]';
check($xp->query($form.'//textarea[@name="data[value_note]"]')->length===1&&$xp->query($form.'//input[@name="data[unit_value]"]')->length===0,'piece-managed item exposes its evidence without a flat price');
$item=$inventory->item($itemId);$initialVersion=$item['version'];$beforeStockSnapshot=snapshots($db);$expectedValueTotal=$inventory->assetsOverview()['total'];
$editData=['code'=>$item['code'],'name'=>$item['name'],'category'=>$item['category'],'unit'=>'Satz','content_quantity'=>'4','content_unit'=>'Stück','home_location'=>$location,'value_note'=>'Invoice SYN-47, position 2: 35 and 39 EUR gross','notes'=>'Saved metadata'];$request=$requestKey();
$inventory->saveItem($itemId,$initialVersion,$editData,$request);$fresh=(new Inventory($db,'writer'))->item($itemId);
check($fresh['value_note']===$editData['value_note'],'standalone value evidence persists through reload');
check($fresh['version']===$initialVersion+1&&$fresh['notes']==='Saved metadata','same metadata save changes the expected revision');
check(snapshots($db)===$beforeStockSnapshot&&$inventory->assetsOverview()['total']===$expectedValueTotal,'prices, piece evidence, stock and total value remain intact');
$itemId=$fresh['id'];ob_start();require __DIR__.'/../views/Inventory/inventory-item.php';$html=ob_get_clean();check(str_contains($html,$editData['value_note']),'reopened form displays the saved evidence');
$inventory->saveItem($itemId,$initialVersion,$editData,$request);check($inventory->item($itemId)['version']===$fresh['version'],'retry remains idempotent');
denied(fn()=>$inventory->saveItem($itemId,$initialVersion,$editData,$requestKey()),'stale edit remains rejected');
denied(fn()=>(new Inventory($db,'inventory'))->saveItem($itemId,$fresh['version'],$editData,$requestKey()),'inventory-only actor cannot write financial evidence');
check($inventory->item($itemId)['value_note']===$editData['value_note']&&snapshots($db)===$beforeStockSnapshot,'rejected edits do not partially change values or stock');
$without=$editData;unset($without['value_note']);$inventory->saveItem($itemId,$fresh['version'],$without,$requestKey());check($inventory->item($itemId)['value_note']===$editData['value_note'],'omitted evidence remains unchanged');
$fresh=$inventory->item($itemId);$cleared=$editData;$cleared['value_note']='';$inventory->saveItem($itemId,$fresh['version'],$cleared,$requestKey());check($inventory->item($itemId)['value_note']===''&&snapshots($db)===$beforeStockSnapshot,'explicit empty summary can be saved while individual piece evidence remains');
$flat=$inventory->saveItem('',0,['name'=>'Synthetic valued item','unit_value'=>'12,00','value_note'=>'Original proof'],$requestKey());$flatItem=$inventory->item($flat);$flatData=['code'=>$flatItem['code'],'name'=>$flatItem['name'],'value_note'=>'Revised proof'];$inventory->saveItem($flat,1,$flatData,$requestKey());
check($inventory->item($flat)['unit_value_cents']===1200&&$inventory->item($flat)['value_note']==='Revised proof','standalone evidence preserves existing flat amount');
$flatData['value_note']='';denied(fn()=>$inventory->saveItem($flat,2,$flatData,$requestKey()),'a documented flat value cannot lose its required evidence');
$db->exec("CREATE TRIGGER reject_note_audit BEFORE INSERT ON audit_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic'");$flatData['value_note']='Rollback proof';try{$inventory->saveItem($flat,2,$flatData,$requestKey());throw new RuntimeException('Expected audit failure');}catch(PDOException){}$db->exec('DROP TRIGGER reject_note_audit');check($inventory->item($flat)['value_note']==='Revised proof'&&$inventory->item($flat)['version']===2,'failed audit rolls the note and revision back');
check(\Kulturhus\Verein\Tests\MySqlFixture::integrity($db)==='ok'&&!\Kulturhus\Verein\Tests\MySqlFixture::foreignKeyErrors($db),'inventory roundtrip preserves integrity');
echo "$checks inventory value-note roundtrip checks passed\n";
