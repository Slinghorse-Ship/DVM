<?php declare(strict_types=1);
// Render only synthetic values: no database, browser session or transmission.
require __DIR__.'/fixtures/ebilanz-period-club-calendar.php';
require_once __DIR__.'/../src/Finance/Balance/BalanceTaxFiling.php';
require_once __DIR__.'/../src/Web/View.php';
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$n=0;
function check(bool $ok,string $why):void{global $n;if(!$ok)throw new RuntimeException($why);$n++;}
$dated=clubCalendarFixture('combined-tax');
$calendar=$dated['taxable']['period_club']['previous']['combined'];
$render=static function(array $allValues):string{
    $year=$allValues['year'];$wholeMapping=[];
    $money=static fn(int $c):string=>number_format($c/100,2,',','.').' €';
    ob_start();try{require __DIR__.'/../views/Finance/Balance/balance-tax-combined.php';return ob_get_contents();}finally{ob_end_clean();}
};
foreach(['dated'=>$dated,'calendar'=>$calendar] as $kind=>$values){
    $before=$values;$html=$render($values);
    check($before===$values,'display leaves original values untouched '.$kind);
    check(str_contains($html,'Gesamter Verein')&&str_contains($html,'Steuerliche Wertkorrekturen'),'whole and tax sections remain distinct '.$kind);
    check(str_contains($html,$kind==='dated'?'Ergebnis des Zeitraums':'Jahresergebnis'),'result label uses actual period '.$kind);
    check(str_contains($html,$kind==='dated'?'Abschreibung im Zeitraum':'Abschreibung im Jahr'),'depreciation label uses actual period '.$kind);
    check(!str_contains($html,$kind==='dated'?'Abschreibung im Jahr':'Abschreibung im Zeitraum'),'no contradictory depreciation label '.$kind);
    foreach($values['whole']['asset_schedule']['items'] as $id=>$item){
        $basis=$item['plan']['basis_cents']??$item['basis_cents']??$item['cost_closing_cents'];
        check(str_contains($html,'Anschaffungskosten: '.number_format($basis/100,2,',','.').' €'),'original acquisition amount '.$kind.' '.$id);
    }
}
$bad=$dated;$key=array_key_first($bad['whole']['asset_schedule']['items']);$bad['whole']['asset_schedule']['items'][$key]['name']='<script>synthetic()</script>';
try{$render($bad);throw new RuntimeException('Changed original asset was displayed.');}catch(DomainException){check(true,'changed original asset is rejected before display');}
// Consistent synthetic presentation fixture, never a persisted/approved report.
$bad['whole']['asset_tax']['tax_schedule']['items'][$key]['original_sha256']=\Kulturhus\Verein\BalanceSegmentCalculation::hash($bad['whole']['asset_schedule']['items'][$key]);
$html=$render($bad);check(str_contains($html,'&lt;script&gt;synthetic()&lt;/script&gt;')&&!str_contains($html,'<script>synthetic()'),'escape original asset names');
echo "$n combined calendar/period display checks passed; synthetic values only\n";
