<?php declare(strict_types=1);
use Kulturhus\Verein\{BalanceCalculation,BalanceLedger};
function segmentFixture():array
{
    $chart=BalanceLedger::defaultChart();
    $pair=static fn($d,$c,$amount,$sphere)=>[['account'=>$d,'debit_cents'=>$amount,'credit_cents'=>0,'sphere'=>$d==='expense'?$sphere:''],['account'=>$c,'debit_cents'=>0,'credit_cents'=>$amount,'sphere'=>$c==='revenue'?$sphere:'']];
    $units=['club'=>['name'=>'Allgemeine Vereinsarbeit','sphere'=>'1'],'rent'=>['name'=>'Vermietung','sphere'=>'2'],'culture'=>['name'=>'Kultur','sphere'=>'3'],'food'=>['name'=>'Gastronomie','sphere'=>'4'],'shop'=>['name'=>'Verkauf','sphere'=>'4']];
    $opening=$pair('cash','equity',100000,'');$entries=[];$allocations=[];
    foreach($opening as $i=>$_)$allocations['opening:'.$i]=['parts'=>array_fill_keys(array_keys($units),20000),'reason'=>'Synthetisch bestätigte Anfangsbestände je Bereich.'];
    foreach($units as $id=>$unit){
        $key=substr(hash('sha256',$id),0,32);$amount=1001+count($entries);
        $entries[]=['id'=>$key,'description'=>$unit['name'],'date'=>'2025-02-01','lines'=>$pair('cash','revenue',$amount,$unit['sphere'])];
        for($i=0;$i<2;$i++)$allocations[$key.':'.$i]=['parts'=>[$id=>$amount],'reason'=>'Synthetischer Einzelbeleg.'];
    }
    $key=str_repeat('a',32);$entries[]=['id'=>$key,'description'=>'Gemeinsamer Einkauf','date'=>'2025-03-01','lines'=>$pair('expense','cash',1003,'4')];
    for($i=0;$i<2;$i++)$allocations[$key.':'.$i]=['parts'=>['food'=>334,'shop'=>669],'reason'=>'Belegpositionen 334 und 669 Cent.'];
    $report=['year'=>2025,'state'=>'closed','closed_sha256'=>hash('sha256','synthetic closed segment fixture'),'result_account'=>'equity','issues'=>[],'accounts'=>$chart,'opening'=>$opening,'entries'=>$entries,'calculation'=>BalanceCalculation::build(2025,$chart,$opening,$entries)];
    return [$report,$units,$allocations];
}
