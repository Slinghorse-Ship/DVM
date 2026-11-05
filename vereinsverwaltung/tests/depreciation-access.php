<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
require_once __DIR__.'/../src/Finance/Accounting/FinanceNavigation.php';
use Kulturhus\Verein\{Database,FinanceNavigation,SettingsSchema,WorkspaceAccess};

$checks=0;
function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";}
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Synthetic');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('admin','a','member',1)");
$tax=SettingsSchema::defaults('tax');$tax['depreciation_enabled']=false;
$db->prepare('INSERT INTO club_settings(club_id,section,version,payload,updated_at) VALUES(?,?,1,?,?)')->execute(['a','tax',json_encode($tax),gmdate('c')]);
$access=new WorkspaceAccess($db,'admin');
check(!$access->allows(['view'=>'finance','tab'=>'book-values']),'depreciation route denied while disabled');
$labels=array_merge(...array_map(static fn(array $group):array=>array_column($group['children'],'label'),FinanceNavigation::visible($access)));
check(!in_array('Abschreibung & Buchwerte',$labels,true),'depreciation menu item hidden while disabled');
$tax['depreciation_enabled']=true;$db->prepare("UPDATE club_settings SET payload=? WHERE club_id='a' AND section='tax'")->execute([json_encode($tax)]);
check($access->allows(['view'=>'finance','tab'=>'book-values']),'depreciation route allowed after activation');
$labels=array_merge(...array_map(static fn(array $group):array=>array_column($group['children'],'label'),FinanceNavigation::visible($access)));
check(in_array('Abschreibung & Buchwerte',$labels,true),'depreciation menu item shown after activation');
echo "$checks depreciation access checks passed\n";
