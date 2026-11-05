<?php declare(strict_types=1);
require __DIR__.'/fixtures/ebilanz-period-club-calendar.php';
use Kulturhus\Verein\{BalanceCalendarClubSource as Calendar, BalancePeriodClubFilingValues as Values};
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$n=0;function check(bool $ok,string $why):void{global $n;if(!$ok)throw new RuntimeException($why);$n++;}
function denied(callable $f,string $why):void{try{$f();}catch(DomainException){check(true,$why);return;}throw new RuntimeException($why);}
foreach(['book','tax','book-successor','tax-successor','combined-book','combined-tax','combined-book-successor','combined-tax-successor'] as $case){
    $v=clubCalendarFixture($case);$t=$v['taxable']??$v;$p=$t['period_club'];
    check(Values::build($p['income'],$t['period_source']['review'],$p['previous'])===$t,'exact calendar reconstruction '.$case);
    check($t['result_difference_cents']===(str_contains($case,'tax')?600:0),'current depreciation difference once '.$case);
    check(count($p['whole']['asset_schedule']['items'])===2&&count($t['asset_schedule']['items'])===1,'original exempt and taxable assets '.$case);
    $old=$p['previous'];if(!isset($old['calendar_club']))$old=$old['period_club']['previous'];check(Calendar::verify($old)===$old,'unchanged calendar proof '.$case);
    $bad=$old;$bad['calendar_club']['review']['journal_sha256']=str_repeat('0',64);denied(fn()=>Calendar::verify($bad),'unrelated old review '.$case);
    $bad=$old;$bad['whole']['book']['income_cents']++;denied(fn()=>Calendar::verify($bad),'changed original whole '.$case);
    denied(fn()=>Values::build($p['income'],$t['period_source']['review']),'missing calendar carry '.$case);
}
echo "$n calendar aggregate/combined value checks passed; no database or transport\n";
