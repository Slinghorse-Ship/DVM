<?php declare(strict_types=1);
use Kulturhus\Verein\Inventory;use Kulturhus\Native\Shared\Db;
require __DIR__.'/native-inventory-loan-catalogue.php';
for($i=0;$i<101;$i++){
 $extra=$inventory->saveItem('',0,['code'=>'OWN-WHERE-'.$i,'name'=>'Own stock '.str_pad((string)$i,3,'0',STR_PAD_LEFT),'minimum'=>'2'],$key());
 $inventory->move($extra,$inventory->item($extra)['stock_version'],['kind'=>'receive','location'=>$location,'quantity'=>'1','reason'=>'Own original location confirmation'],$key());
}
$inventory->move($other,$inventory->item($other)['stock_version'],['kind'=>'block','location'=>$otherLocation,'quantity'=>'1','reason'=>'Own original maintenance confirmation'],$key());
$before=ownOriginalDatabaseHash($db,$tables);$original=$inventory->whereabouts();$first=$inventory->whereaboutsPage();
$check(count($original)>100&&$first['total']===count($original)&&count($first['rows'])===6,'all real stock portions counted while rendering six original cards');
$all=[];for($p=1;$p<=$first['pages'];$p++)array_push($all,...$inventory->whereaboutsPage([],$p)['rows']);$check($all===$original,'every whole stock and issued loan portion retains original order location confirmation and quantity');
foreach([['state'=>'available'],['state'=>'reserved'],['state'=>'blocked'],['state'=>'issued'],['location'=>$location],['location'=>$otherLocation],['item'=>$item],['q'=>'ä🙂'],['q'=>'Foreign']]as$filter){$old=$inventory->whereabouts($filter);$page=$inventory->whereaboutsPage($filter);$check($page['total']===count($old)&&$page['rows']===array_slice($old,0,6),'exact original whereabouts filter '.json_encode($filter,JSON_UNESCAPED_UNICODE));}
$check($inventory->whereaboutsPage(['location'=>$location,'state'=>'issued'])['total']===0,'issued stock never falsely appears at its previous shelf');
$items=$inventory->items();$loans=$inventory->loans();$expected=['items'=>count($items),'low'=>count(array_filter($items,fn($r)=>$r['available']<$r['minimum'])),'blocked'=>array_sum(array_column($items,'blocked')),'reserved'=>count(array_filter($loans,fn($r)=>$r['state']==='reserved')),'issued'=>count(array_filter($loans,fn($r)=>$r['state']==='issued')),'overdue'=>count(array_filter($loans,fn($r)=>$r['state']==='issued'&&$r['due_on']<Inventory::today()))];
$check($inventory->summary()===$expected,'compact dashboard SQL preserves exact original active stock and due-date counts');
$check($inventory->whereaboutsPage([],1000000)['page']===$first['pages'],'last whereabouts page clamps to existing original portions');foreach([0,'01',[],1000001]as$bad)$deny(fn()=>$inventory->whereaboutsPage([],$bad),'malformed whereabouts page rejected');
$check(ownOriginalDatabaseHash($db,$tables)===$before,'all stock pages filters and dashboard counts leave entire original database unchanged');
Db::run($db,"UPDATE settings_roles SET inventory_access='read',loans_access='read' WHERE id='own-movement-read'");$check($reader->whereaboutsPage()['total']===$first['total'],'read-only role can see full own original stock');Db::run($db,"UPDATE settings_roles SET inventory_access='none',loans_access='none' WHERE id='own-movement-read'");$deny(fn()=>$reader->whereaboutsPage(),'current role withdrawal blocks existing stock page service');
if(getenv('DVM_INVENTORY_WHERE_CATALOGUE_BROWSER')==='1'){
 require __DIR__.'/support/catalog-pricing-browser-fixture.php';$browser=ownCatalogPricingBrowser($db,['total'=>$first['total'],'pages'=>$first['pages'],'issued'=>$ids[502]]);Db::run($db,"UPDATE settings_roles SET inventory_access='read',loans_access='read',finance_access='none' WHERE id='own-price-reader'");$protected=['inventory_items','inventory_stock','inventory_loans','inventory_loan_lines','inventory_movements','inventory_events','finance_entries'];$hash=ownOriginalDatabaseHash($db,$protected);
 try{$proc=proc_open(['node',__DIR__.'/inventory-where-catalogue-browser.mjs'],[0=>['pipe','r'],1=>['pipe','w']],$pipes);fwrite($pipes[0],json_encode($browser['input'],JSON_THROW_ON_ERROR));fclose($pipes[0]);while(($line=fgets($pipes[1]))!==false)echo$line;fclose($pipes[1]);if(proc_close($proc)!==0)throw new RuntimeException('Actual whereabouts browser failed');}finally{proc_terminate($browser['process']);proc_close($browser['process']);fclose($browser['log']);}
 $check(ownOriginalDatabaseHash($db,$protected)===$hash,'actual browser pages original item and issued loan returns preserve all stock loans and finance');
}
echo json_encode(['checks'=>$n,'external_actions'=>0],JSON_THROW_ON_ERROR)."\n";
