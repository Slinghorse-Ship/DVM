<?php declare(strict_types=1);
use Kulturhus\Verein\BalanceCalculation as CapitalC;

/** Independent synthetic opening/transfer evidence, never generated from mapping rules. */
function clubCapitalFixture(array $state):array
{
    $r=$state['journal'];$alloc=$state['allocation']['allocations'];$units=$state['allocation']['units'];$decisions=[];
    $r['accounts']['equity']['skr_code']='25000';
    $r['result_account']='carry_gain';
    $codes=['bound'=>'20000','replacement'=>'20800','working'=>'20900','free'=>'21000','free_other'=>'22000','shares'=>'22500',
        'bound_capital'=>'23000','reallocation'=>'24000','investment'=>'28000','other'=>'28500','statutory'=>'29500','other_second'=>'29600','carry_gain'=>'29700','carry_loss'=>'29780'];
    foreach($codes as $key=>$code){
        $equity=in_array($key,['bound_capital','reallocation','carry_gain','carry_loss'],true);$loss=$key==='carry_loss';
        $r['accounts'][$key]=['name'=>'Synthetic '.$key,'group'=>$equity?'equity':'reserves','skr_code'=>$code];
        $ordinal=count($r['opening']);$r['opening'][]=['account'=>$key,'debit_cents'=>$loss?500:0,'credit_cents'=>$loss?0:500,'sphere'=>''];$alloc['opening:'.$ordinal]=['parts'=>array_fill_keys(array_keys($units),100),'reason'=>'Synthetic capital opening evidence'];
        $r['opening'][0]['debit_cents']+=$loss?-500:500;
        foreach($alloc['opening:0']['parts'] as &$amount)$amount+=$loss?-100:100;unset($amount);
    }
    foreach([['equity','replacement',250],['replacement','equity',100]] as $i=>[$debit,$credit,$amount]){
        $id=hash('md5','synthetic reserve transfer '.$i);$lines=[['account'=>$debit,'debit_cents'=>$amount,'credit_cents'=>0,'sphere'=>''],['account'=>$credit,'debit_cents'=>0,'credit_cents'=>$amount,'sphere'=>'']];
        $r['entries'][]=['id'=>$id,'date'=>'2025-06-01','description'=>'Synthetic reserve creation/release','lines'=>$lines];
        foreach($lines as $j=>$line){$alloc[$id.':'.$j]=['parts'=>array_fill_keys(array_keys($units),intdiv($amount,5)),'reason'=>'Synthetic internal transfer evidence'];$decisions['journal:'.$id.':'.$j]=['kind'=>'reclassification','reason'=>'Synthetic reserve resolution'];}
    }
    $r['accounts']=CapitalC::chart($r['accounts']);$r['calculation']=CapitalC::build($r['year'],$r['accounts'],$r['opening'],$r['entries']);
    return [clubTaxState($r,$units,$alloc),$decisions];
}
