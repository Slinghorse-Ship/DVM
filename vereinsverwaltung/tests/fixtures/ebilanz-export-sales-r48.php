<?php declare(strict_types=1);
require_once __DIR__.'/ebilanz-values.php';require_once __DIR__.'/ebilanz-export-sale-2026-r48.php';
use Kulturhus\Verein\{BalanceTaxValues as T,BalanceWholeClubReport as W};
function exportR48(int $year,string $vatKind,string $variant,bool $whole=false):array {
 if(!in_array($year,[2025,2026],true)||!in_array($vatKind,['export_4_1a','intra_eu_4_1b'],true)||!in_array($variant,['gain','loss','cross','cents'],true))throw new DomainException('Exact original export-sale fixture required.');
 [$r,$review]=$year===2025?ebilanzFixture('asset-tax-sale-'.$variant,null,null,null,$vatKind):ebilanzSale2026Fixture('asset-tax-sale-'.$variant,null,null,null,$vatKind);
 foreach($r['entries'] as &$entry)$entry['id']=hash('md5','synthetic-original-export-'.$year.'-'.$vatKind.'-'.$entry['id']);unset($entry);$r['calculation']=\Kulturhus\Verein\BalanceCalculation::build($year,$r['accounts'],$r['opening'],$r['entries']);
 $r['previous_sha256']=null;$r['result_account']='capital';$r['closed_sha256']=hash('sha256',json_encode([$r['year'],$r['accounts'],$r['opening'],$r['entries'],$r['asset_schedule']],JSON_THROW_ON_ERROR));$review['journal_sha256']=$r['closed_sha256'];
 return T::build($whole?W::fromReport($r):$r,$review);
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});echo json_encode(exportR48((int)$argv[1],$argv[2],$argv[3],($argv[4]??'')==='whole'),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
