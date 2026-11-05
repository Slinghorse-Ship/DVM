<?php declare(strict_types=1);
// Future closure is confined to this disposable test process, not the web server.
namespace Kulturhus\Verein {function date(string $format,?int $time=null):string{return \gmdate($format,$time??\strtotime('2027-01-15 UTC'));}}
namespace {
// The transport adapter here is a local fixture, never the installed ELSTER runtime.
require __DIR__.'/balance-tax-filing.php';
use Kulturhus\Verein\{BalanceLedger as L,BalanceTaxFiling as F,TaxFilings as T};
use Kulturhus\Verein\Tests\MySqlFixture;
$baseChecks=$checks;$spec=json_decode(file_get_contents(__DIR__.'/../resources/accounting/reviews/operating-r16.json'),true,64,JSON_THROW_ON_ERROR);
function deniedSourceDirection(callable $fn,string $label):void{
    try{$fn();}catch(DomainException $e){check($e->getMessage()==='Nicht unterstützte Kontenzuordnung: code-48000',$label);return;}
    throw new RuntimeException($label);
}
foreach($spec['years'] as $edition)foreach([false,true] as $whole){
    $year=$edition['year'];$club='source16-'.$year.'-'.(int)$whole;$finance=$club.'-finance';$board=$club.'-board';$member=$club.'-member';
    $db->prepare('INSERT INTO clubs VALUES(?,?)')->execute([$club,'Synthetic mixed source purposes']);
    foreach([[$finance,'treasurer',0],[$board,'treasurer',1],[$member,'member',0]] as [$actor,$role,$super])$db->prepare('INSERT INTO actors(id,club_id,role,is_superuser) VALUES(?,?,?,?)')->execute([$actor,$club,$role,$super]);
    $db->prepare("INSERT INTO club_settings(club_id,section,payload,version,updated_at) VALUES(?,'tax',?,1,?)")->execute([$club,json_encode($settings),gmdate('c')]);
    $chart=L::defaultChart();$chart['cash']['skr_code']='18000';$chart['equity']['skr_code']='25000';$lines=[];$net=0;$selected=[];
    foreach($edition['groups'] as $g)foreach($g['sources'] as $source){
        $code=$source['code'];$key='code-'.$code;$chart[$key]=['name'=>'Synthetic '.$code,'group'=>$g['group'],'skr_code'=>$code];
        // Both revenue decreases and tax refunds coexist with normal facts.
        $amount=in_array($code,['48000','48150','48180','76040','76070','76410','76430','76920','76940'],true)?-1234:1234;
        $debit=$g['group']==='expense'?$amount:-$amount;$net+=$debit;
        $lines[]=['account'=>$key,'debit_cents'=>max(0,$debit),'credit_cents'=>max(0,-$debit),'sphere'=>$whole?(string)((count($lines)%4)+1):'4'];
        if($g['conditional'])$selected[$key]=$g['choices'][0];
    }
    $lines[]=['account'=>'cash','debit_cents'=>max(0,-$net),'credit_cents'=>max(0,$net),'sphere'=>''];
    $ledger=new L($db,$finance);$ledger->create($year);$ledger->setup($year,1,$chart,$pair('cash','equity',10000000),'equity','Synthetic opening',true);
    $ledger->post($year,2,bin2hex(random_bytes(16)),$year.'-06-30','Synthetic mixed purposes','Synthetic original evidence',$lines);
    (new L($db,$board))->close($year,3,['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true],'Synthetic full review');
    $before=MySqlFixture::rows($db,'balance_entries');$closed=MySqlFixture::rows($db,'balance_years');
    $service=new F($db,$finance);$tax=new T($db,$finance);$id=$service->create($year,$whole?'complete_club_ledger':'separate_business_ledger');
    $p=$profile;$p['business_name']='Synthetic mixed source purposes';$p['tax_privileged']=!$whole;
    $r=['complete'=>true,'opening_adjustments'=>[],'current_adjustments'=>[]];
    $up='is.netIncome.regular.operatingTC.grossTradingProfit.totalOutput.inventoryChange.increasefinished';
    $down='is.netIncome.regular.operatingTC.grossTradingProfit.totalOutput.inventoryChange.decreasefinished';
    deniedSourceDirection(fn()=>$service->save($id,1,$p,$selected+['code-48000'=>$up],$r),'signed mapping contradicting negative original balance rejected on save');
    $service->save($id,1,$p,$selected,$r);check($tax->validate($id,2)['valid'],'all new mixed purposes generate together '.$year.' '.(int)$whole);
    check($tax->get($id)['form_data']['balance_preparation']['mapping']===$selected,'only genuinely ambiguous choices persisted');
    denied(fn()=>(new F($db,$member))->approve($id,3,true),'member cannot approve source-family filing');
    denied(fn()=>$service->approve($id,3,true),'treasurer cannot grant board approval');
    (new F($db,$board))->approve($id,3,true);
    check(isset($tax->get($id)['form_data']['balance_preparation']['approval']),'board approves exact mixed XML');
    // A tax correction reverses the balance, without reversing the retained book.
    $r['current_adjustments']=[['id'=>'inventory-sign-change','reason'=>'Synthetic tax valuation changes sign','lines'=>[
        ['account'=>'cash','debit_cents'=>2468,'credit_cents'=>0,'sphere'=>''],['account'=>'code-48000','debit_cents'=>0,'credit_cents'=>2468,'sphere'=>'4']]]];
    deniedSourceDirection(fn()=>$service->save($id,4,$p,$selected+['code-48000'=>$down],$r),'old book-direction mapping rejected after tax correction');
    $service->save($id,4,$p,$selected,$r);
    check($tax->get($id)['xml_data']===null&&!isset($tax->get($id)['form_data']['balance_preparation']['approval']),'changed tax values clear XML and approval');
    check($tax->validate($id,5)['valid'],'positive corrected tax balance uses opposite automatic fact');
    check($before===MySqlFixture::rows($db,'balance_entries')&&$closed===MySqlFixture::rows($db,'balance_years'),'commercial books retained unchanged');
    check($tax->attempts($id)===[],'mixed purposes never sent');
}
echo ($checks-$baseChecks)." new mixed-purpose MySQL checks passed; $baseChecks base checks; no external transport\n";
}
