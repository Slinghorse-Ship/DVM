<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
require_once __DIR__.'/BalancePeriodSphereTax.php';
require_once __DIR__.'/BalanceTaxAssetValues.php';

/** Item-specific tax expenses, never journal postings. Inputs are rebuilt tax values. */
final class BalancePeriodAssetExpenseSources
{
    public const PREFIX='asset-depreciation:';
    public static function build(array $report,array $valuation):array
    {
        $codes=[];$sources=[];
        foreach($report['asset_schedule']['items']??[] as $item)if(isset($item['sphere_change']))$codes[BalanceTaxAssetValues::depreciationCode($item['plan']['account'])]=true;
        if(!$codes)return ['blocked_codes'=>[],'sources'=>[]];
        foreach($report['asset_schedule']['items'] as $id=>$item){
            $code=BalanceTaxAssetValues::depreciationCode($item['plan']['account']);if(!isset($codes[$code]))continue;
            $tax=$valuation['asset_tax']['tax_schedule']['items'][$id]??null;$hash=BalanceSegmentCalculation::hash($item);
            if(!is_array($tax)||($tax['original_sha256']??null)!==$hash)throw new DomainException('Abschreibung und ursprünglichen Gegenstand erneut prüfen.');
            if(isset($item['sphere_change'])){
                $proof=BalancePeriodSphereTax::item($item);
                if(($tax['sphere_change']??null)!==$item['sphere_change'])throw new DomainException('Steuerlichen Bereichswechsel unverändert nachweisen.');
                $cents=$proof['tax']['depreciation_cents'];
            }else $cents=$item['plan']['sphere']==='4'?$tax['schedule']['depreciation_cents']:0;
            if(!is_int($cents)||$cents<0||$cents>BalanceCalculation::LIMIT)throw new DomainException('Steuerliche Abschreibung in ganzen Cent prüfen.');
            if(!$cents)continue;
            // No guessed account when several original accounts share an SKR code.
            $row=['account'=>null,'group'=>'expense','cents'=>$cents,'asset_id'=>$id,'asset_sha256'=>$hash,
                'tax_value_sha256'=>BalanceSegmentCalculation::hash($tax),'name'=>$item['name'],'skr_code'=>$code];
            $key=self::PREFIX.BalanceSegmentCalculation::hash(['period'=>$report['period'],'source'=>$row]);$sources[$key]=$row;
        }
        return ['blocked_codes'=>$codes,'sources'=>$sources];
    }
}
