<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';require_once __DIR__.'/../src/Finance/Balance/BalanceSegmentExport.php';require __DIR__.'/fixtures/balance-segments.php';
use Kulturhus\Verein\{BalanceSegmentCalculation as S,BalanceSegmentExport as E};
$checks=0;function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);++$checks;}
[$report,$units,$alloc]=segmentFixture();$state=['journal'=>$report,'allocation'=>S::build($report,$units,$alloc),'version'=>3,'current_approval'=>true,'carry_current'=>true,'carry_forward'=>null,'history'=>[]];
$files=E::files($state);check(count($files)===5,'five explicit workpapers');
$json=json_decode($files['bereiche/datenstand.json'],true,512,JSON_THROW_ON_ERROR);
check($json['current_approval']&&!$json['tax_export_ready']&&$json['allocation']===$state['allocation'],'exact snapshot not a tax filing');
check(str_contains($files['bereiche/LESEN.txt'],'keine E-Bilanz'),'scope unambiguous');
function csv(string $bytes):array{$f=fopen('php://temp','r+');fwrite($f,substr($bytes,3));rewind($f);$rows=[];while(($row=fgetcsv($f,0,';','"',''))!==false)$rows[]=$row;fclose($f);return $rows;}
$rows=csv($files['bereiche/uebersicht.csv']);check(count($rows)===6,'all five units present');
check(array_sum(array_column(array_slice($rows,1),9))===$report['calculation']['annual_result_cents'],'CSV aggregates retain exact result');
$proof=csv($files['bereiche/zuordnungsnachweis.csv']);$sums=[];foreach(array_slice($proof,1) as $row)$sums[$row[0].':'.$row[1]]=($sums[$row[0].':'.$row[1]]??0)+(int)$row[8];
foreach(S::sources($report) as $key=>$source)check($sums[$key]===$source['cents'],'every allocation cent and source retained');
$state['current_approval']=false;check(str_contains(E::files($state)['bereiche/LESEN.txt'],'ENTWURF'),'unapproved complete data remains draft');
$state['current_approval']=true;$state['carry_current']=false;check(!json_decode(E::files($state)['bereiche/datenstand.json'],true)['current_approval'],'superseded carry cannot be reported approved');
$state['carry_current']=true;unset($alloc['opening:0']);$state['allocation']=S::build($report,$units,$alloc);$files=E::files($state);
check(str_contains($files['bereiche/zuordnungsnachweis.csv'],'Offen'),'missing source visible');
$rows=csv($files['bereiche/uebersicht.csv']);check($rows[1][5]===''&&!json_decode($files['bereiche/datenstand.json'],true)['current_approval'],'incomplete does not export false zero');
$units['food']['name']='=1+2';$state['allocation']=S::build($report,$units,$alloc);check(str_contains(E::files($state)['bereiche/uebersicht.csv'],"'=1+2"),'CSV formula injection neutralized');
$state['allocation']['sha256']=str_repeat('0',64);$blocked=false;try{E::files($state);}catch(DomainException){$blocked=true;}check($blocked,'tampered allocation blocked');
echo "$checks segment export checks passed; synthetic data, no database or transport\n";
