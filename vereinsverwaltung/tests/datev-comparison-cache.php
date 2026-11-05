<?php declare(strict_types=1);
namespace Kulturhus\Verein {
 function file_get_contents(string $path):string|false{$raw=\file_get_contents($path);return !empty($GLOBALS['comparison_changed'])&&str_contains($path,'comparison-only-2025-r82.json')?$raw.' ':$raw;}
}
namespace {
 require __DIR__.'/../src/Finance/Tax/TaxCatalog.php';
 use Kulturhus\Verein\{TaxCatalog,DatevComparisonCatalog};
 $rows=TaxCatalog::accounts(2025);if(!$rows['41850']['blocked'])throw new RuntimeException('Original comparison guard missing');
 $GLOBALS['comparison_changed']=true;$rejected=false;
 try{TaxCatalog::accounts(2025);}catch(DomainException){$rejected=true;}
 unset($GLOBALS['comparison_changed']);if(!$rejected)throw new RuntimeException('Warmed PHP cache hid a changed original proof');
 if(!TaxCatalog::accounts(2025)['41850']['blocked']||TaxCatalog::hasYear(2024))throw new RuntimeException('Catalogue fallback or blocked meaning changed');
 echo "Original comparison classification remains source-bound after PHP cache warmup; no database writes.\n";
}
