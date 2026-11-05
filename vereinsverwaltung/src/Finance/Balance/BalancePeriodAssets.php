<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
require_once __DIR__.'/BalanceCalculation.php';
require_once dirname(__DIR__, 2).'/Finance/Assets/GwgDepreciation.php';
require_once __DIR__.'/BalancePeriodDegressive.php';
require_once dirname(__DIR__, 2).'/Finance/Assets/AssetValuation.php';

/** Original whole-month plans, or an explicitly selected internal day allocation. */
final class BalancePeriodAssets
{
    public static function calculate(array $plan,array $period,?array $previous=null,?string $dayPolicy=null):array
    {
        AssetOriginalDepreciation::code($plan);
        if($dayPolicy!==null&&$dayPolicy!==BalancePeriodDayAllocation::POLICY)throw new DomainException('Bestätigte interne Aufteilungsregel auswählen.');
        $period=BalancePeriod::check($period);
        if(($plan['method']??null)==='cost'){
            $original=AssetValuation::fromBookPlan($plan);
            if(!in_array($original['account'],AssetTypes::codes($original['kind']),true))throw new DomainException('Geprüfte Kostenanlage und Originalkonto stimmen nicht überein.');
            if($previous!==null)throw new DomainException('Kostenbewertung aus ihrem vollständigen Originalvortrag berechnen.');
            $value=AssetValuation::calculate($original,$period);
            return ['period'=>$period,'depreciation_cents'=>0]+$value['book'];
        }
        if($dayPolicy===null)BalancePeriod::fullMonths($period);
        $basis=$plan['basis_cents']??null;$residual=$plan['residual_cents']??null;$months=$plan['months']??null;
        $date=$plan['acquired_on']??null;$method=$plan['method']??'linear_monthly';
        if(!is_int($basis)||$basis<=0||$basis>BalanceCalculation::LIMIT||!is_int($residual)||$residual<0||$residual>=$basis
            ||!is_string($date)||!TaxCatalog::documentYear($date)||!is_int($months))throw new DomainException('Ursprünglichen Anlagenplan vollständig prüfen.');
        if(isset($plan['disposal'])||isset($plan['partial_plan'])||isset($plan['sphere_changes']))throw new DomainException('Anlagenbewegungen benötigen einen eigenen zeitraumbezogenen Nachweis.');
        if($method==='degressive'&&!isset($plan['gwg']))return $dayPolicy===null?BalancePeriodDegressive::calculate($plan,$period,$previous):BalancePeriodDailyDegressive::calculate($plan,$period,$previous);
        if(isset($plan['degressive']))throw new DomainException('Abschreibungsmethode und Nachweis stimmen nicht überein.');
        if($method==='gwg_immediate'){
            if($months!==1||$residual!==0||($plan['account']??null)!=='06700')throw new DomainException('GWG-Plan prüfen.');
            GwgDepreciation::check($plan['gwg']??null,$basis,$date);
        }elseif($method!=='linear_monthly'||$months<12||$months>1200||$months%12!==0||isset($plan['gwg']))throw new DomainException('Zeitraumbezogene Berechnung benötigt einen linearen Anlagenplan oder einen bestätigten GWG-Nachweis.');
        if($dayPolicy!==null)return BalancePeriodDayAllocation::linear($plan,$period,$previous);
        $before=$date<$period['start'];$acquired=$date<=$period['end'];$additions=$acquired&&!$before?$basis:0;
        $elapsed=static function(string $boundary)use($date,$months):int{
            $count=((int)substr($boundary,0,4)-(int)substr($date,0,4))*12+(int)substr($boundary,5,2)-(int)substr($date,5,2);
            return max(0,min($months,$count));
        };
        $rounded=static fn(int $n):int=>intdiv(($basis-$residual)*$n+intdiv($months,2),$months);
        if($method==='gwg_immediate'){$openingDep=$before?$basis:0;$closingDep=$acquired?$basis:0;}
        else{$openingDep=$before?$rounded($elapsed($period['start'])):0;
            $closingDep=$acquired?$rounded(min($months,$elapsed($period['end'])+($period['end']>=$date?1:0))):0;}
        $costOpening=$before?$basis:0;$costClosing=$acquired?$basis:0;
        return ['period'=>$period,'cost_opening_cents'=>$costOpening,'additions_cents'=>$additions,'cost_closing_cents'=>$costClosing,
            'accumulated_opening_cents'=>$openingDep,'depreciation_cents'=>$closingDep-$openingDep,'accumulated_closing_cents'=>$closingDep,
            'book_opening_cents'=>$costOpening-$openingDep,'book_closing_cents'=>$costClosing-$closingDep];
    }
}
