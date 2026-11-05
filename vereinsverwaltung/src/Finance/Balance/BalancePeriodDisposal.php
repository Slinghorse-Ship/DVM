<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
require_once __DIR__.'/BalancePeriodAssets.php';
require_once dirname(__DIR__, 2).'/Finance/Assets/AssetDisposal.php';

/** Dated asset movements. Original cost and prior rounded depreciation never restart. */
final class BalancePeriodDisposal
{
    public static function calculate(array $plan,array $period,?array $previous,?array $proof,?string $dayPolicy=null):array
    {
        $period=BalancePeriod::check($period);if($dayPolicy===null)BalancePeriod::fullMonths($period);
        elseif($dayPolicy!==BalancePeriodDayAllocation::POLICY)throw new DomainException('Bestätigten internen Tagesnachweis verwenden.');
        if($proof===null)return BalancePeriodAssets::calculate($plan,$period,$previous,$dayPolicy);
        AssetDisposal::check($plan,$proof);
        if($proof['date']>$period['end'])throw new DomainException('Abgang liegt nach diesem Abschlusszeitraum.');
        if(($proof['kind']??null)==='partial_scrap')$s=AssetPartialDisposal::period($plan,$proof,$period,$dayPolicy);
        elseif($proof['date']<$period['start']){
            $s=['period'=>$period]+array_fill_keys(['cost_opening_cents','additions_cents','disposals_cents','cost_closing_cents',
                'accumulated_opening_cents','depreciation_cents','accumulated_disposals_cents','accumulated_closing_cents',
                'book_opening_cents','book_disposals_cents','book_closing_cents'],0);
        }else{
            // Depreciation includes the disposal month; the next month is never charged.
            if($dayPolicy===null){
                $end=(new \DateTimeImmutable($proof['date']))->modify('last day of this month')->format('Y-m-d');
                $s=BalancePeriodAssets::calculate($plan,['start'=>$period['start'],'end'=>$end],$previous);
            }else{
                BalancePeriodAssets::calculate($plan,$period,$previous,$dayPolicy);
                $s=($plan['method']??null)==='degressive'?BalancePeriodDailyDegressive::calculate($plan,$period,$previous,$proof['date']):BalancePeriodDayAllocation::linear($plan,$period,$previous,$proof['date']);
            }
            $s['period']=$period;$s['disposals_cents']=$s['cost_closing_cents'];
            $s['accumulated_disposals_cents']=$s['accumulated_closing_cents'];
            $s['book_disposals_cents']=$s['book_closing_cents'];
            $s['cost_closing_cents']=$s['accumulated_closing_cents']=$s['book_closing_cents']=0;
            unset($s['degressive_state']); // A derecognised asset has no future depreciation state.
        }
        if($previous!==null){
            if(!isset($previous['period'])||!BalancePeriod::follows($previous['period'],$period))throw new DomainException('Abgang benötigt den unmittelbar vorherigen Anlagenstand.');
            foreach(['cost','accumulated','book'] as $key)if(($previous[$key.'_closing_cents']??null)!==$s[$key.'_opening_cents'])throw new DomainException('Abgang und vorgetragener Anlagenwert stimmen nicht überein.');
        }elseif($plan['acquired_on']<$period['start'])throw new DomainException('Vorgetragenen Anlagenwert vor dem Abgang prüfen.');
        self::reconcile($s);return $s;
    }
    public static function reconcile(array $s):void
    {
        foreach(['cost_opening','additions','disposals','cost_closing','accumulated_opening','depreciation','accumulated_disposals','accumulated_closing','book_opening','book_disposals','book_closing'] as $key){
            $v=$s[$key.'_cents']??null;if(!is_int($v)||$v<0||$v>BalanceCalculation::LIMIT)throw new DomainException('Anlagenbewegungen müssen vollständige, nicht negative Centbeträge sein.');
        }
        if($s['cost_opening_cents']+$s['additions_cents']-$s['disposals_cents']!==$s['cost_closing_cents']
            ||$s['accumulated_opening_cents']+$s['depreciation_cents']-$s['accumulated_disposals_cents']!==$s['accumulated_closing_cents']
            ||$s['cost_opening_cents']-$s['accumulated_opening_cents']!==$s['book_opening_cents']
            ||$s['cost_closing_cents']-$s['accumulated_closing_cents']!==$s['book_closing_cents']
            ||$s['disposals_cents']-$s['accumulated_disposals_cents']!==$s['book_disposals_cents']
            ||$s['book_opening_cents']+$s['additions_cents']-$s['depreciation_cents']-$s['book_disposals_cents']!==$s['book_closing_cents'])throw new DomainException('Anschaffungskosten, Abschreibung und Restbuchwert des Abgangs sind nicht abgestimmt.');
    }
}
