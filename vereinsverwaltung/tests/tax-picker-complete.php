<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';require __DIR__.'/../src/Web/View.php';
use Kulturhus\Verein\TaxCatalog;
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect();\Kulturhus\Verein\Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Synthetic account picker');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('admin','a','member',1)");
$_SESSION=['actor'=>'admin'];$_GET=[];$_POST=['document_date'=>'2026-01-01'];
$all=TaxCatalog::accounts();
$finance=new \Kulturhus\Verein\Finance($db,'admin');
$positionKind='expense';$positionForm=[['description'=>'Synthetic item','amount'=>'10,00','sphere'=>'','account'=>'','tax_rate'=>'0']];
ob_start();require __DIR__.'/../views/Settings/positions-editor.php';$html=ob_get_clean();
$dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$html);$xp=new DOMXPath($dom);$n=0;
function check(bool $ok,string $message):void{global $n;if(!$ok)throw new RuntimeException($message);$n++;}
$expected=count(TaxCatalog::commonInvoiceAccounts());$leadingZeroCode='06300';
$selects=$xp->query('//select[@data-position-account]');
check($selects->length===2,'Existing row and dynamic template have account selection');
foreach($selects as $select){check($xp->query('./option[@value!=""]',$select)->length===$expected,'Every standard account available in each selector');check($xp->query('./option[@value="'.$leadingZeroCode.'"]',$select)->length===1,'Standard account keeps leading zero');}
$blocked=array_values(array_filter($all,static fn($a)=>empty($a['active'])))[0]['code'];
check($xp->query('//select[@data-position-account]/option[@value="'.$blocked.'"]')->length===0,'Blocked source account not newly selectable');
check(str_contains($html,'Was bedeuten Sphäre, Steuerkonto und BU-Schlüssel?'),'Shared accounting help retained');
$positionForm[0]['account']=$blocked;ob_start();require __DIR__.'/../views/Settings/positions-editor.php';$historic=ob_get_clean();
check(str_contains($historic,'value="'.$blocked.'" selected'),'Previously selected historical account preserved');
echo "$n complete tax picker checks passed\n";
