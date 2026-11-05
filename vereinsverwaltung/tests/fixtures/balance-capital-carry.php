<?php declare(strict_types=1);
/** Entirely invented data. No database, external transport or user information. */
function capitalCarryFixture(int $income=10000,int $loss=10000,bool $crossSphere=false,int $year=2025):array
{
    $units=['club'=>['name'=>'Allgemeine Vereinsarbeit','sphere'=>'1'],'food'=>['name'=>'Gastronomie','sphere'=>'4'],'shop'=>['name'=>'Verkauf','sphere'=>'4']];
    $chart=\Kulturhus\Verein\BalanceCalculation::chart([
        'cash'=>['name'=>'Testbank','group'=>'cash','skr_code'=>'18000'],
        'equity'=>['name'=>'Vereinskapital','group'=>'equity','skr_code'=>'25000'],
        'result'=>['name'=>'Ergebnisvortrag','group'=>'equity','skr_code'=>'29700'],
        'liabilities'=>['name'=>'Offene Testrechnungen','group'=>'liabilities','skr_code'=>'33000'],
        'revenue'=>['name'=>'Testerlöse','group'=>'revenue','skr_code'=>''],
        'expense'=>['name'=>'Testausgaben','group'=>'expense','skr_code'=>''],
    ]);
    $line=static fn(string $a,int $d,int $c,string $s=''):array=>['account'=>$a,'debit_cents'=>$d,'credit_cents'=>$c,'sphere'=>$s];
    $opening=[$line('cash',30000,0),$line('equity',0,30000)];
    $alloc=[];foreach($opening as $i=>$_)$alloc['opening:'.$i]=['parts'=>array_fill_keys(array_keys($units),10000),'reason'=>'Synthetic opening evidence'];
    $profitUnit=$crossSphere?'club':'food';$entries=[];
    foreach([['gain',[$line('cash',$income,0),$line('revenue',0,$income,$units[$profitUnit]['sphere'])],$profitUnit],['loss',[$line('expense',$loss,0,'4'),$line('liabilities',0,$loss)],'shop']] as [$name,$lines,$unit]){
        $id=md5('synthetic '.$name);$entries[]=['id'=>$id,'date'=>$year.'-06-01','description'=>'Synthetic '.$name,'lines'=>$lines];
        foreach($lines as $i=>$l)$alloc[$id.':'.$i]=['parts'=>[$unit=>$l['debit_cents']+$l['credit_cents']],'reason'=>'Synthetic original invoice'];
    }
    $report=['year'=>$year,'state'=>'closed','closed_sha256'=>hash('sha256','synthetic capital carry '.$year.' '.$income.' '.$loss.' '.(int)$crossSphere),'previous_sha256'=>null,'result_account'=>'result','issues'=>[],'accounts'=>$chart,'opening'=>$opening,'entries'=>$entries,
        'calculation'=>\Kulturhus\Verein\BalanceCalculation::build($year,$chart,$opening,$entries),
        'asset_schedule'=>['year'=>$year,'items'=>[],'totals'=>array_fill_keys(\Kulturhus\Verein\BalanceClubTaxReport::FIELDS,0),'issues'=>[],'ready_for_xbrl'=>false]];
    return clubTaxState($report,$units,$alloc);
}

function capitalCarryNext(array $state):array
{
    $r=$state['journal'];$next=$r;$next['year']++;$next['previous_sha256']=$r['closed_sha256'];$next['closed_sha256']=hash('sha256','synthetic next '.$r['closed_sha256']);
    $next['opening']=\Kulturhus\Verein\BalanceCalculation::carryForward($r['calculation'],$r['result_account']);$next['entries']=[];$next['asset_schedule']['year']=$next['year'];
    $next['calculation']=\Kulturhus\Verein\BalanceCalculation::build($next['year'],$next['accounts'],$next['opening'],[]);
    $carry=\Kulturhus\Verein\BalanceSegmentCalculation::carry($r,$state['allocation'],$next);
    return clubTaxState($next,$carry['units'],$carry['allocations'],2,$carry['capital_carry']??[]);
}
