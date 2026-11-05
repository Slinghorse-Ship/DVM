<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use Brick\Math\BigRational;
use DomainException;

/** Exact fractional fiscal months, bound to the immediately confirmed original carry. */
final class BalancePeriodDailyDegressive
{
    private static function need(bool $ok,string $why='Degressiven Tagesvortrag und ursprünglichen Methodenwechsel vollständig abstimmen.'):void
    {if(!$ok)throw new DomainException($why);}
    private static function record(BigRational $value):array{return ['n'=>$value->getNumerator()->toInt(),'d'=>$value->getDenominator()->toInt()];}
    private static function fraction(mixed $value):BigRational
    {
        self::need(is_array($value)&&array_keys($value)===['n','d']&&is_int($value['n'])&&is_int($value['d'])&&$value['n']>=0&&$value['n']<=1000000000&&$value['d']>0&&$value['d']<=1000000000);
        $fraction=BigRational::of($value['n'])->dividedBy($value['d']);self::need(self::record($fraction)===$value);return $fraction;
    }
    public static function calculate(array $plan,array $period,?array $previous,?string $disposalDate=null):array
    {
        DegressiveDepreciation::check($plan['degressive']??null,$plan);$period=BalancePeriod::check($period);
        $date=$plan['acquired_on'];$basis=$plan['basis_cents'];$before=$date<$period['start'];
        if($disposalDate!==null&&(!BalancePeriod::contains($period,$disposalDate)||$disposalDate<$date))throw new DomainException('Den tatsächlichen degressiven Abgang im Abschlusszeitraum nachweisen.');
        $through=$disposalDate===null?$period['end']:BalancePeriodDayAllocation::month($disposalDate)['end'];
        $elapsed=BalancePeriodDayAllocation::age($date,BalancePeriodDayAllocation::before($period['start']));$endAge=BalancePeriodDayAllocation::age($date,$through);$active=$endAge->minus($elapsed);
        $openingDep=0;$linear=null;$switched=null;
        if($before){
            self::need($previous!==null&&isset($previous['period'])&&BalancePeriod::follows($previous['period'],$period),'Degressive Tagesaufteilung benötigt den bestätigten unmittelbar vorherigen Anlagenstand.');
            $openingDep=$previous['accumulated_closing_cents']??null;$opening=$previous['book_closing_cents']??null;$state=$previous['degressive_state']??null;
            self::need(is_int($openingDep)&&is_int($opening)&&$openingDep>=0&&$opening>=0&&$openingDep+$opening===$basis&&($previous['cost_closing_cents']??null)===$basis&&is_array($state));
            if(($state['schema']??null)===2){
                self::need(array_keys($state)===['schema','policy','elapsed','linear','switched_period']&&$state['policy']===BalancePeriodDayAllocation::POLICY&&self::fraction($state['elapsed'])->compareTo($elapsed)===0);
                $linear=$state['linear'];$switched=$state['switched_period'];
                if($linear!==null){
                    self::need(is_array($linear)&&array_keys($linear)===['basis','months','used']);$linear=['basis'=>$linear['basis'],'months'=>self::fraction($linear['months']),'used'=>self::fraction($linear['used'])];
                }
            }else{
                self::need(BalancePeriod::fullMonths($previous['period'])>0&&isset($state['elapsed_months'])&&is_int($state['elapsed_months'])&&$elapsed->compareTo($state['elapsed_months'])===0&&array_key_exists('linear',$state)&&array_key_exists('switched_period',$state));
                $linear=$state['linear'];$switched=$state['switched_period'];
                if($linear!==null){self::need(is_array($linear)&&is_int($linear['months']??null)&&is_int($linear['used']??null));$linear=['basis'=>$linear['basis']??null,'months'=>BigRational::of($linear['months']),'used'=>BigRational::of($linear['used'])];}
            }
            if($linear!==null){
                self::need(is_int($linear['basis'])&&$linear['basis']>0&&$linear['basis']<=$basis&&$linear['months']->compareTo(0)>0&&$linear['months']->compareTo($plan['months'])<=0&&$linear['used']->compareTo(0)>=0&&$linear['used']->compareTo($linear['months'])<=0
                    &&is_string($switched)&&preg_match('/^[0-9]{8}-[0-9]{8}$/D',$switched)&&$plan['degressive']['switch_to_linear']
                    &&$linear['basis']-BalancePeriodDayAllocation::rounded(BigRational::of($linear['basis'])->multipliedBy($linear['used'])->dividedBy($linear['months']))===$opening);
            }else self::need($switched===null);
        }else{
            self::need($previous===null,'Ein Neuzugang darf keinen fremden früheren Anlagenwert übernehmen.');$opening=$date<=$period['end']?$basis:0;
        }
        self::need($active->compareTo(0)>=0);$charge=0;
        if($opening>0&&$active->compareTo(0)>0){
            $degressive=BalancePeriodDayAllocation::rounded(BigRational::of($opening)->multipliedBy($plan['degressive']['rate_basis_points'])->multipliedBy($active)->dividedBy(120000));
            $remaining=BigRational::of($plan['months'])->minus($elapsed);
            $linearAmount=$remaining->compareTo(0)>0?BalancePeriodDayAllocation::rounded(BigRational::of($opening)->multipliedBy($active->compareTo($remaining)>0?$remaining:$active)->dividedBy($remaining)):$opening;
            if($linear===null&&$plan['degressive']['switch_to_linear']&&$linearAmount>=$degressive){
                $linear=['basis'=>$opening,'months'=>$remaining->compareTo(0)>0?$remaining:BigRational::of(1),'used'=>BigRational::of(0)];$switched=BalancePeriod::key($period);
            }
            if($linear!==null){
                $used=$linear['used'];$next=$used->plus($active);$linear['used']=$next->compareTo($linear['months'])>0?$linear['months']:$next;
                $at=static fn(BigRational$value):int=>BalancePeriodDayAllocation::rounded(BigRational::of($linear['basis'])->multipliedBy($value)->dividedBy($linear['months']));$charge=$at($linear['used'])-$at($used);
            }else $charge=$degressive;
            $charge=min($opening,$charge);
        }
        $costOpening=$before?$basis:0;$costClosing=$date<=$period['end']?$basis:0;
        return ['period'=>$period,'cost_opening_cents'=>$costOpening,'additions_cents'=>$costClosing-$costOpening,'cost_closing_cents'=>$costClosing,
            'accumulated_opening_cents'=>$openingDep,'depreciation_cents'=>$charge,'accumulated_closing_cents'=>$openingDep+$charge,
            'book_opening_cents'=>$costOpening-$openingDep,'book_closing_cents'=>$costClosing-$openingDep-$charge,
            'degressive_state'=>['schema'=>2,'policy'=>BalancePeriodDayAllocation::POLICY,'elapsed'=>self::record($endAge),
                'linear'=>$linear===null?null:['basis'=>$linear['basis'],'months'=>self::record($linear['months']),'used'=>self::record($linear['used'])],'switched_period'=>$switched]];
    }
}
