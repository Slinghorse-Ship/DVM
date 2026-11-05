<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';require __DIR__.'/../src/Web/View.php';
use Kulturhus\Verein\{Database,Documents};
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);$db->exec("INSERT INTO clubs VALUES('a','Synthetic');INSERT INTO actors(id,club_id,role) VALUES('manager','a','members')");
$_SESSION=['actor'=>'manager','csrf'=>'synthetic'];$_GET=['loan'=>'loan'];
$stateChecks=0;function checkState(bool $ok,string $label):void{global $stateChecks;if(!$ok)throw new RuntimeException($label);$stateChecks++;}
$inventory=new class{
 function categories(){return [];}function purchaseUnits($id){return [];}function canWrite(){return true;}function canRead(){return true;}function canWriteLoans(){return true;}function canReadLoans(){return true;}function people(){return [];}function locations(){return [['id'=>'loc','name'=>'Synthetic','active'=>1],['id'=>'loc2','name'=>'Other','active'=>1]];}
 function loan($id){return ['id'=>$id,'number'=>1,'purpose'=>'Synthetic','contact_name'=>'Synthetic','contact_id'=>'person','state'=>'issued','starts_on'=>'2026-09-01','due_on'=>'2026-09-30','current_place'=>'Hall','version'=>9,'notes'=>'','history'=>[],'lines'=>[['id'=>'line','item_id'=>'item','item_name'=>'Stage','item_code'=>'S1','location_name'=>'Hall','quantity'=>4,'unit'=>'Stück','returned'=>0,'lost'=>0]]];}
};
$documents=new Documents($db,'manager');$tab='loans';$canFinance=false;$canRead=false;
$_POST=['action'=>'inventory-return','target'=>'loan','version'=>'2','request_key'=>'original','data'=>['date'=>'2026-09-08','reason'=>'Synthetic damaged edge','lines'=>[['line'=>'line','good'=>'2','damaged'=>'1','lost'=>'1','location'=>'loc2']]]];
require_once __DIR__.'/../src/Auth/WorkspaceAccess.php';$workspaceAccess=new \Kulturhus\Verein\WorkspaceAccess($db,(string)$_SESSION['actor']);$view='inventory';$tab=$tab??'';
ob_start();require __DIR__.'/../views/Inventory/inventory.php';$html=ob_get_clean();
foreach(['name="version" value="2"','name="request_key" value="original"','[good]" value="2"','[damaged]" value="1"','[lost]" value="1"','value="loc2" selected','Synthetic damaged edge','value="2026-09-08"'] as $needle)checkState(str_contains($html,$needle),'Return preserves '.$needle);
$_POST['target']='another';ob_start();require __DIR__.'/../views/Inventory/inventory-loans.php';$html=ob_get_clean();checkState(!str_contains($html,'Synthetic damaged edge'),'another loan POST does not enter this form');
$registry=new class{function family($id){return ['id'=>'family','name'=>'Saved family','version'=>8,'payer_name'=>'First','payer_contact_id'=>'one','people'=>[['id'=>'one','name'=>'First'],['id'=>'two','name'=>'Second']]];}function families(){return [['id'=>'family','name'=>'Saved family','payer_name'=>'First']];}};
$finance=new class{function canWrite(){return false;}};$profile=['id'=>'person'];$canAdmissions=false;
$_POST=['action'=>'save-family','id'=>'person','version'=>'2','family_name'=>'Changed family','payer'=>'two'];ob_start();require __DIR__.'/../views/People/Members/member-family.php';$html=ob_get_clean();
checkState(str_contains($html,'name="version" value="2"')&&str_contains($html,'value="Changed family"')&&str_contains($html,'value="two" selected'),'family draft and stale version survive while saved summary remains separate');
$_POST=['action'=>'assign-family','id'=>'person','expected_family'=>'previous','family'=>'new','family_name'=>'New synthetic family'];ob_start();require __DIR__.'/../views/People/Members/member-family.php';$html=ob_get_clean();
checkState(str_contains($html,'name="expected_family" value="previous"')&&str_contains($html,'value="new" selected')&&str_contains($html,'value="New synthetic family"'),'assignment preserves expected family and new-family draft');
echo "$stateChecks member/loan rendered state checks passed\n";
