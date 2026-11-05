<?php declare(strict_types=1);
namespace Kulturhus\Verein {function date(string $format,?int $time=null):string{return \gmdate($format,$time??\strtotime('2029-01-15 UTC'));}}
namespace {
require __DIR__.'/../src/bootstrap.php';require __DIR__.'/fixtures/balance-club-income.php';
use Kulturhus\Verein\{BalanceClubIncomeReconciliation as I,BalanceSegmentCalculation as S,BalanceCalculation as C,BalanceInternalClearing as W};
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$checks=0;function check(bool $ok,string $why):void{global $checks;if(!$ok)throw new RuntimeException($why);$checks++;}
function denied(callable $f,string $why):void{try{$f();}catch(DomainException){check(true,$why);return;}throw new RuntimeException($why);}
for($paid=0;$paid<=334;$paid++){
    $state=incomeFixture($paid);$saved=$state;$v=I::fromState($state);
    check($state===$saved&&I::rebuild($v['scope'])===$v,'source unchanged and replay exact');
    check($v['selected_units']===['food','shop'],'all taxable businesses, never selected subset');
    check($v['result']['taxable_book_cents']===1006&&$v['result']['whole_cents']===4012,'payment and partial settlement cannot change accrued profit');
    check($v['internal_balances']['closing_received_cents']===1003-$paid,'boundary balance remains separate');
    check(!$v['tax_values_reviewed']&&!$v['tax_export_ready'],'book workpaper is not a tax review or release');
    foreach($v['accounts'] as $id=>$r)check(in_array($r['group'],['expense','revenue'],true)&&$r['whole_cents']===$r['taxable_book_cents']+$r['other_areas_cents'],'no artificial assets, debt or income');
    $next=incomeNext($state,334-$paid);$n=I::fromState($next,$v);
    check($n['result']['taxable_book_cents']===0&&$n['internal_balances']['opening_received_cents']===1003-$paid&&$n['internal_balances']['closing_received_cents']===669,'next-year settlement is not an expense');
}
$s=incomeFixture();$v=I::fromState($s);$next=incomeNext($s);$n=I::fromState($next,$v);
$third=incomeNext($next,0);$t=I::fromState($third,$n);check($t['internal_balances']['closing_received_cents']===669,'three-year evidence retained');
foreach(['current_approval','source_current','carry_current'] as $flag){$bad=$s;$bad[$flag]=false;denied(fn()=>I::fromState($bad),'stale or missing review '.$flag);}
$bad=$s;$bad['segments']['carry_current']=false;denied(fn()=>I::fromState($bad),'stale opening');
$bad=$s;$bad['state']='draft';denied(fn()=>I::fromState($bad),'draft not approved');
$bad=$s;$bad['segments']['version']++;denied(fn()=>I::fromState($bad),'different source revision');
$bad=$s;$bad['history'][0]['proof']['totals']['closing_cents']++;denied(fn()=>I::fromState($bad),'altered reviewed proof');
denied(fn()=>I::fromState($next),'missing prior');
$bad=$v;$bad['result']['taxable_book_cents']++;denied(fn()=>I::fromState($next,$bad),'prior result alteration');
$bad=$n['scope'];$bad['previous']['club_id']='another';denied(fn()=>I::rebuild($bad),'other club prior');
$bad=$v['scope'];$bad['allocation']['units']['food']['sphere']='3';denied(fn()=>I::rebuild($bad),'cannot remove a taxable business');
$bad=$v['scope'];$bad['review']['clearing']['revision']=1;denied(fn()=>I::rebuild($bad),'a first draft is not a reviewed clearing');
$bad=$v['scope'];$bad['source']['calculation']['annual_result_cents']++;denied(fn()=>I::rebuild($bad),'invented original profit');
// A return/credit note reverses expense rather than becoming a new source of taxable revenue.
$r=$s['segments']['journal'];$a=$s['segments']['allocation'];$voucher=str_repeat('f',32);
$r['entries'][]=['id'=>$voucher,'date'=>'2025-08-01','description'=>'Synthetic supplier credit','lines'=>[['account'=>'cash','debit_cents'=>50,'credit_cents'=>0,'sphere'=>''],['account'=>'expense','debit_cents'=>0,'credit_cents'=>50,'sphere'=>'4']]];
foreach([0,1] as $ordinal)$a['allocations'][$voucher.':'.$ordinal]=['parts'=>['food'=>50],'reason'=>'Synthetic supplier credit'];
$r['calculation']=C::build(2025,$r['accounts'],$r['opening'],$r['entries']);$a=S::build($r,$a['units'],$a['allocations']);$w=W::build('synthetic',$r,$a['units'],$a['allocations'],$s['proof']['instructions']);
$credit=I::fromState(incomeState($r,$a,$w));check($credit['result']['taxable_book_cents']===1056&&$credit['accounts']['expense']['taxable_credit_cents']===50,'credit note retains original side');
$summary=I::summary($credit);check(str_contains($summary,'10,56 EUR')&&str_contains($summary,'keine Steuererklärung')&&str_contains($summary,$credit['sha256']),'human-readable source-bound summary');
$bad=$credit;$bad['result']['taxable_book_cents']=0;denied(fn()=>I::summary($bad),'summary cannot render invented results');
// Transfers within the taxable group, entirely outside it and in the reverse direction.
foreach([['food','shop'],['club','rent'],['food','club'],['rent','food']] as [$from,$to]){
    $state=incomeFixture();$r=$state['segments']['journal'];$a=$state['segments']['allocation'];$input=$state['proof']['instructions'];
    $voucher=substr(hash('sha256','boundary '.$from.' '.$to),0,32);$case=substr(hash('sha256','case '.$from.' '.$to),0,32);
    $r['entries'][]=['id'=>$voucher,'date'=>'2025-10-01','description'=>'Synthetic boundary advance','lines'=>[['account'=>'cash','debit_cents'=>173,'credit_cents'=>0,'sphere'=>''],['account'=>'cash','debit_cents'=>0,'credit_cents'=>173,'sphere'=>'']]];
    foreach([$to,$from] as $ordinal=>$unit)$a['allocations'][$voucher.':'.$ordinal]=['parts'=>[$unit=>173],'reason'=>'Synthetic original transfer'];
    $input['advances'][$case]=['voucher'=>$voucher,'from'=>$from,'to'=>$to,'cents'=>173,'repay_by'=>'2026-10-01','reason'=>'Synthetic boundary case'];
    $r['calculation']=C::build(2025,$r['accounts'],$r['opening'],$r['entries']);$a=S::build($r,$a['units'],$a['allocations']);$w=W::build('synthetic',$r,$a['units'],$a['allocations'],$input);
    $b=I::fromState(incomeState($r,$a,$w));check($b['result']['taxable_book_cents']===1006,'all directions have zero profit effect');
    $field=match($from.'-'.$to){'food-shop'=>'within_taxable_closing_cents','club-rent'=>'outside_taxable_closing_cents','food-club'=>'closing_advanced_cents',default=>'closing_received_cents'};
    check($b['internal_balances'][$field]===($field==='closing_received_cents'?1076:173),'gross directions not incorrectly netted');
}
echo "$checks club-income reconciliation checks passed; synthetic, no database or transport\n";
}
