<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');
$catalogueOldBrowser=getenv('DVM_MEMBER_PROFILE_ORIGINAL_BROWSER');putenv('DVM_MEMBER_PROFILE_ORIGINAL_BROWSER=0');try{require __DIR__.'/native-member-profile-original.php';}finally{if($catalogueOldBrowser===false)putenv('DVM_MEMBER_PROFILE_ORIGINAL_BROWSER');else putenv('DVM_MEMBER_PROFILE_ORIGINAL_BROWSER='.$catalogueOldBrowser);}
use Kulturhus\Verein\Web\InventoryCatalogueForm;
$checks=0;$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException('FAIL '.$message);++$checks;echo 'PASS '.$message."\n";};
$catalogueInventory=new \Kulturhus\Verein\Inventory($db,'own-list-admin');$catalogueKey=static fn():string=>bin2hex(random_bytes(16));$cataloguePlace=$catalogueInventory->saveLocation('',0,['name'=>'Owned literal filter place'],$catalogueKey());$catalogueItem=$catalogueInventory->saveItem('',0,['name'=>'Owned literal stock','code'=>'OWN-LITERAL','home_location'=>$cataloguePlace,'initial_quantity'=>'1'],$catalogueKey());
$tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);$before=ownOriginalDatabaseHash($db,$tables);
$check(count($catalogueInventory->whereabouts())===1,'actual nonzero named original stock exists before zero selection tests');foreach(['member','location','item','q']as$field)$check($catalogueInventory->whereabouts([$field=>'0'])===[],'literal zero filter cannot silently become all actual original stock '.$field);

foreach(InventoryCatalogueForm::FIELDS as $tab=>$fields){
 $q=['view'=>$tab==='material'?'material':'inventory','tab'=>$tab];$check(!InventoryCatalogueForm::state($q,$tab)['blocked'],'original default catalogue remains supported '.$tab);
 foreach($fields as $field=>$limit){$q=['view'=>$tab==='material'?'material':'inventory','tab'=>$tab,$field=>['0','Whole original <&> ä🙂'],'password'=>'must-never-enter-original'];$s=InventoryCatalogueForm::state($q,$tab);$check($s['blocked']&&$s['original'][$field]===$q[$field]&&!isset($s['original']['password']),'whole query shape survives before scalar filtering '.$tab.'/'.$field);}
 $page=array_key_last($fields);if($fields[$page]!==0)continue;foreach(['0','01','1000001','1wrong']as$value){$q=['view'=>'inventory','tab'=>$tab,$page=>$value];$s=InventoryCatalogueForm::state($q,$tab);$check($s['blocked']&&$s['original'][$page]===$value,'unsupported original page is reviewable '.$tab.'/'.$value);}
 $q=['view'=>'inventory','tab'=>$tab,$page=>'1000000'];$check(!InventoryCatalogueForm::state($q,$tab)['blocked'],'existing last-page clamping contract remains supported '.$tab);
}
foreach(['where'=>'state','items'=>'status','loans'=>'state']as$tab=>$field)$check(InventoryCatalogueForm::state(['view'=>'inventory','tab'=>$tab,$field=>'unknown-original'],$tab)['blocked'],'unknown original status cannot become default selection '.$tab);
$check(InventoryCatalogueForm::applies(['item'=>['0']],'items')&&InventoryCatalogueForm::state(['view'=>'inventory','tab'=>'items','item'=>['0']],'items')['blocked'],'whole opened item source cannot turn silently into catalogue');
$check(!InventoryCatalogueForm::applies(['item'=>'owned-id'],'items')&&!InventoryCatalogueForm::applies(['new_item'=>'1'],'items')&&!InventoryCatalogueForm::applies(['loan'=>'owned-id'],'loans'),'existing detail and explicit creation routes remain separate');
$check(InventoryCatalogueForm::options([''=>'All'],'0','Unavailable')===['0'=>'Unavailable',''=>'All'],'unavailable literal zero filter remains selected explicitly');
$check(!InventoryCatalogueForm::state(['view'=>'material','q'=>'0'],'material')['blocked'],'literal zero material search remains supported');
try{(new \Kulturhus\Verein\Inventory($db,'own-list-reader'))->memberItems();$check(false,'current inventory permission required for material source');}catch(DomainException){$check(true,'current inventory permission is required for actual material source');}
$check(ownOriginalDatabaseHash($db,$tables)===$before,'all whole earlier business rows and schema remain exact through refused catalogue queries');
if(getenv('DVM_INVENTORY_CATALOGUE_BROWSER')==='1'||getenv('DVM_INVENTORY_MATERIAL_BROWSER')==='1'){require __DIR__.'/support/inventory-catalogue-interface-browser.php';runOwnedInventoryCatalogueBrowser($db,$other);}
echo json_encode(['suite'=>'native-inventory-catalogue-interface','checks'=>$checks,'original_tables'=>count($tables),'external_actions'=>0],JSON_THROW_ON_ERROR)."\n";
