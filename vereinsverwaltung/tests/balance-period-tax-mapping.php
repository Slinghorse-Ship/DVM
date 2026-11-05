<?php declare(strict_types=1);
require __DIR__.'/fixtures/ebilanz-period-subsidiary.php';
use Kulturhus\Verein\BalanceTaxMapping as M;
$n=0;function mapCheck(bool $ok,string $why):void{global $n;if(!$ok)throw new RuntimeException($why);++$n;}
foreach(['book','tax','off-balance','book-successor','tax-successor','off-balance-successor'] as $variant){
    $v=subsidiaryPeriodFixture($variant);$w=$v['whole'];$before=serialize($w);$a=$w['book']['accounts'];$schedule=$w['asset_schedule'];
    $m=M::preview($v['year'],$a,$schedule);
    foreach(['dep','softwaredep'] as $key)mapCheck($m['rows'][$key]['status']==='assigned','exact period depreciation maps '.$variant.' '.$key);
    if(!str_ends_with($variant,'successor'))foreach(['48490','48550','68890','68950','68960'] as $code)mapCheck($m['rows']['d'.$code]['status']==='assigned','dated disposal and original gain/loss branch maps '.$variant.' '.$code);
    else foreach(['48490','48550','68890','68950','68960'] as $code)mapCheck($m['rows']['d'.$code]['status']==='review','sale in previous period is not reused in same ending year '.$code);
    mapCheck(serialize($w)===$before,'mapping does not mutate retained values');
    $bad=$a;$bad['dep']['amount_cents']++;mapCheck(M::preview(2026,$bad,$schedule)['rows']['dep']['status']==='review','one-cent depreciation discrepancy not automatically assigned');
    $bad=$schedule;$bad['issues']=['Synthetic unresolved asset'];mapCheck(M::preview(2026,$a,$bad)['rows']['dep']['status']==='review','unresolved asset cannot authorize mapping');
    $balanceReport=$w['period_source']['source'];ob_start();require __DIR__.'/../views/Finance/Balance/balance-report.php';$html=ob_get_clean();
    mapCheck(str_contains($html,'Wert am Anfang')&&str_contains($html,'Restwert beim Abgang')&&str_contains($html,'Gewinn- und Verlustrechnung'),'dated report renders original assets without legacy keys');
}
echo "$n dated asset mapping checks passed; no database or transport\n";
