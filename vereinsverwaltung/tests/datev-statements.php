<?php declare(strict_types=1);
// Offline, no database, no network, no mail, no bank or tax submission.
require __DIR__.'/fixtures/ebilanz-values.php';
require_once __DIR__.'/../src/Finance/Balance/BalanceTaxMapping.php';
use Kulturhus\Verein\{BalanceTaxMapping as M,DatevStatementCatalog as S};
$n=0;
function check(bool $v,string $message):void{global $n;if(!$v)throw new RuntimeException($message);++$n;}
foreach([2025,2026] as $year){
    $positions=S::positions($year);$choices=M::chartChoices($year);
    foreach(['03000'=>'fixed_assets','48330'=>'revenue','48300'=>'revenue','63040'=>'expense','69920'=>'expense'] as $code=>$group){
        check($choices[$code]['group']===$group,'group from printed position '.$code);
        check($choices[$code]['statement']!=='','printed statement retained '.$code);
    }
    check($choices['48330']['statement']==='Umsatzerlöse','not misclassified as other revenue');
    check($positions['48250']['closing_marks']===['HB'],'commercial-accounting marker retained');
    check($positions['39500']['group']===null,'blank source never guesses a group');
    check($choices['18000']['group']==='cash','existing precise rule takes priority over mixed source position');
    check(!isset($choices['90000'])&&!isset($choices['01430']),'statistical and ambiguous accounts remain excluded');
    $accounts=['building'=>['name'=>'Synthetic building','skr_code'=>'03000','group'=>'fixed_assets','amount_cents'=>100]];
    check(M::preview($year,$accounts)['rows']['building']['status']==='review','source group does not grant unimplemented asset tax export');
    M::checkChart($year,$accounts);
    check(true,'valid source group accepted');
    $accounts['building']['group']='revenue';
    try{M::checkChart($year,$accounts);throw new RuntimeException('wrong group accepted');}catch(DomainException){++$n;}
}
check(S::positions(2099)===[],'no fallback from another year');
$next=['year'=>2027,'accounts'=>[['code'=>'03010','name'=>'Synthetic future building','function'=>'']]];
$p=['kind'=>'balance','label'=>'Grundstücke, grundstücksgleiche Rechte und Bauten einschließlich der Bauten auf fremden Grundstücken',
    'marks'=>[],'status'=>'printed','bbox'=>[50,120,100,200]];
check(S::classify($next,['03010'=>$p])['03010']['group']==='fixed_assets','future import reuses purpose without another account-number list');
$raw=$p;$p['label']=mb_strtoupper($p['label'])."\n";
check(S::classify($next,['03010'=>$p])['03010']['group']==='fixed_assets','label typography does not need new yearly rules');$p=$raw;
$next['accounts'][0]['code']='73010';
check(S::classify($next,['73010'=>$p])['73010']['group']===null,'class guard rejects contradictory source');
try{S::classify($next,[]);throw new RuntimeException('missing position accepted');}catch(DomainException){++$n;}
echo "$n printed-statement checks passed\n";
