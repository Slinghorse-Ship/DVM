<?php declare(strict_types=1);
$mappingReview='operating-r16';require __DIR__.'/balance-operating-r15.php';
foreach([2025,2026] as $year){
    foreach(['48000','48100','48150','48160','48180','76410'] as $code){
        $account=['name'=>'Synthetic automatic signed fact','skr_code'=>$code,'group'=>$code[0]==='4'?'revenue':'expense'];
        foreach([null,true,1.5,'100'] as $invalid){
            $a=$account+['amount_cents'=>$invalid];
            $p=\Kulturhus\Verein\BalanceTaxMapping::preview($year,['test'=>$a]);
            check($p['mapping']===[]&&!$p['rows']['test']['choice_required'],'missing or untyped balance never guessed');
        }
    }
    $rates=\Kulturhus\Verein\BalanceTaxMapping::revenueRates($year);
    check($rates['48620']===19&&$rates['48630']===7,'source-defined rental VAT retained');
    foreach(['42900','40000','49410','49450','49470'] as $code)check(!isset($rates[$code]),'presentation does not fabricate ordinary invoice VAT '.$code);
}
echo "$n total revision-16 checks passed; no database or external actions\n";
