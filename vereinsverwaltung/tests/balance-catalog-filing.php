<?php declare(strict_types=1);
// Future closure is confined to this disposable test process.
namespace Kulturhus\Verein {function date(string $format,?int $time=null):string{return \gmdate($format,$time??\strtotime('2027-01-15 UTC'));}}
namespace {
// Actual MySQL journal -> saved draft -> generator -> role-bound approval.
// Only the existing offline transport boundary fixture is used; never transmit.
require __DIR__.'/balance-tax-filing.php';
use Kulturhus\Verein\Tests\MySqlFixture;
use Kulturhus\Verein\{BalanceLedger,BalanceTaxFiling,TaxFilings};
$baseChecks=$checks;
$specs=json_decode(file_get_contents(__DIR__.'/fixtures/ebilanz-balance-accounts.json'),true,64,JSON_THROW_ON_ERROR);
foreach([2025,2026] as $year){
    $covered=[];
    // Generic stock totals and their detailed alternatives are separate journals.
    // Combining a parent total with its children must not silently double-count.
    foreach(['generic','detail'] as $variant){
        $club='catalog-'.$year.'-'.$variant;$finance=$club.'-finance';$board=$club.'-board';$member=$club.'-member';
        $db->prepare('INSERT INTO clubs VALUES(?,?)')->execute([$club,'Synthetic catalog club']);
        foreach([[$finance,'treasurer',0],[$board,'treasurer',1],[$member,'member',0]] as [$actor,$role,$super])
            $db->prepare('INSERT INTO actors(id,club_id,role,is_superuser) VALUES(?,?,?,?)')->execute([$actor,$club,$role,$super]);
        $db->prepare("INSERT INTO club_settings(club_id,section,payload,version,updated_at) VALUES(?,'tax',?,1,?)")->execute([$club,json_encode($settings),gmdate('c')]);
        $chart=BalanceLedger::defaultChart();$chart['cash']['skr_code']='18000';$chart['equity']['skr_code']='25000';$lines=[];$net=0;$explicitMapping=[];
        foreach($specs as $spec){
            $parent=in_array($spec['target'],['bs.ass.currAss.inventory.inProgress','bs.ass.currAss.inventory.finishedAndMerch'],true);
            if(($variant==='generic')!==$parent)continue;
            foreach($spec['codes'] as $code){
                $key='code-'.$code;$covered[$code]=true;$asset=in_array($spec['group'],['inventory','receivables','other_assets'],true);
                $chart[$key]=['name'=>'Synthetic '.$code,'group'=>$spec['group'],'skr_code'=>$code];
                if(($spec['requires_review']??false)===true)$explicitMapping[$key]=$spec['target'];
                $lines[]=['account'=>$key,'debit_cents'=>$asset?1234:0,'credit_cents'=>$asset?0:1234,'sphere'=>''];
                $net+=$asset?1234:-1234;
            }
        }
        if($net!==0)$lines[]=['account'=>'cash','debit_cents'=>max(0,-$net),'credit_cents'=>max(0,$net),'sphere'=>''];
        $ledger=new BalanceLedger($db,$finance);$ledger->create($year);
        $ledger->setup($year,1,$chart,$pair('cash','equity',10000000),'equity','Synthetic catalog opening',true);
        $ledger->post($year,2,bin2hex(random_bytes(16)),$year.'-06-30','Synthetic original positions','Synthetic source document',$lines);
        (new BalanceLedger($db,$board))->close($year,3,['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true],'Synthetic full review');
        $source=MySqlFixture::rows($db,'balance_entries');$closed=MySqlFixture::rows($db,'balance_years');
        $service=new BalanceTaxFiling($db,$finance);$tax=new TaxFilings($db,$finance);$id=$service->create($year);
        $p=$profile;$p['business_name']='Synthetic catalog '.$year.' '.$variant;
        $service->save($id,1,$p,$explicitMapping,['complete'=>true,'opening_adjustments'=>[],'current_adjustments'=>[]]);
        check($tax->validate($id,2)['valid'],'entire mixed catalog journal generates '.$year.' '.$variant);
        $row=$tax->get($id);check($row['state']==='validated'&&$row['form_data']['balance_preparation']['mapping']===$explicitMapping,'only qualified manual choices are persisted');
        foreach($chart as $a)if($a['skr_code']!=='')check(str_contains($row['xml_data'],'>'.$a['skr_code'].'<'),'original account number retained '.$a['skr_code']);
        denied(fn()=>(new BalanceTaxFiling($db,$member))->approve($id,3,true),'member cannot approve catalog filing');
        denied(fn()=>$service->approve($id,3,true),'treasurer cannot approve own catalog filing');
        (new BalanceTaxFiling($db,$board))->approve($id,3,true);
        check(isset($tax->get($id)['form_data']['balance_preparation']['approval']),'approval is bound to generated mixed catalog');
        $service->save($id,4,$p,$explicitMapping,['complete'=>true,'opening_adjustments'=>[],'current_adjustments'=>[]]);
        $row=$tax->get($id);check($row['xml_data']===null&&!isset($row['form_data']['balance_preparation']['approval']),'editing invalidates catalog approval');
        check($source===MySqlFixture::rows($db,'balance_entries')&&$closed===MySqlFixture::rows($db,'balance_years'),'original accounting never overwritten');
        check($tax->attempts($id)===[],'no catalog transmission attempt');
    }
    check(count($covered)===232,'all new catalog codes persisted and generated '.$year);
}
echo ($checks-$baseChecks)." additional catalog filing checks passed; $baseChecks reused offline boundary checks; no network transport\n";
}
