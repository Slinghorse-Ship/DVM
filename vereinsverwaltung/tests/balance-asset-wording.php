<?php declare(strict_types=1);
// Pure report rendering: no database, credentials or transport.
require __DIR__.'/fixtures/ebilanz-values.php';
require_once __DIR__.'/../src/Web/View.php';
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$n=0;
foreach(['asset-sale-gain'=>'Verkauft:','asset-sale-loss'=>'Verkauft:','asset-scrap'=>'Ausgemustert:'] as $variant=>$label){
 [$balanceReport]=ebilanzFixture($variant);
 ob_start();require __DIR__.'/../views/Finance/Balance/balance-report.php';$html=ob_get_clean();
 foreach([str_contains($html,$label),str_contains($html,'Restwert beim Abgang'),!str_contains($html,$label==='Verkauft:'?'Ausgemustert:':'Verkauft:')] as $ok){if(!$ok)throw new RuntimeException('Asset report wording: '.$variant);++$n;}
}
echo "$n asset report wording checks passed; no database\n";
