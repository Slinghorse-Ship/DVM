<?php declare(strict_types=1);
require __DIR__.'/fixtures/ebilanz-period-assets.php';
use Kulturhus\Verein\BalancePeriodFilingValues as Filing;
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$n=0;
foreach(['book','tax','book-successor','tax-successor','gwg','gwg-successor','degressive','degressive-successor','fully','fully-successor'] as $variant){
    $value=datedAssetFixture($variant);$original=serialize($value);$p=$value['period_source'];
    if(Filing::build($p['source'],$p['review'],$p['previous'])!==$value||serialize($value)!==$original)throw new RuntimeException('Non-deterministic or mutated source: '.$variant);
    if($value['transmission']!=='unavailable')throw new RuntimeException('No send permission allowed');
    $n+=2;
}
echo "$n dated asset generator-boundary checks passed; no database, certificate or transport\n";
