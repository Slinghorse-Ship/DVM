<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
require_once dirname(__DIR__, 2).'/Finance/Assets/DegressiveDepreciation.php';

/** Replay one real financial year from the verified preceding asset schedule. */
final class BalancePeriodDegressive
{
    public static function calculate(array $plan,array $period,?array $previous):array
    {
        DegressiveDepreciation::check($plan['degressive']??null,$plan);
        $period=BalancePeriod::check($period);BalancePeriod::fullMonths($period);
        $date=$plan['acquired_on'];$basis=$plan['basis_cents'];$before=$date<$period['start'];
        $distance=static fn(string $end):int=>((int)substr($end,0,4)-(int)substr($date,0,4))*12+(int)substr($end,5,2)-(int)substr($date,5,2);
        $elapsed=$before?$distance($period['start']):0;$linear=null;$switched=null;$openingDep=0;
        if($before){
            if($previous===null||!isset($previous['period'])||!BalancePeriod::follows($previous['period'],$period))throw new DomainException('Degressive Abschreibung benötigt den bestätigten unmittelbar vorherigen Anlagenstand.');
            BalancePeriod::fullMonths($previous['period']);$state=$previous['degressive_state']??null;
            $openingDep=$previous['accumulated_closing_cents']??null;$opening=$previous['book_closing_cents']??null;
            if(!is_array($state)||($state['elapsed_months']??null)!==$elapsed||($previous['cost_closing_cents']??null)!==$basis
                ||!is_int($openingDep)||!is_int($opening)||$openingDep<0||$opening<0||$opening+$openingDep!==$basis
                ||!array_key_exists('linear',$state)||!array_key_exists('switched_period',$state))throw new DomainException('Degressiven Anlagenvortrag und ursprüngliche Anschaffungskosten abstimmen.');
            $linear=$state['linear'];$switched=$state['switched_period'];
            if($linear!==null){
                if(!is_array($linear)||!is_int($linear['basis']??null)||!is_int($linear['months']??null)||!is_int($linear['used']??null)
                    ||$linear['basis']<=0||$linear['basis']>$basis||$linear['months']<1||$linear['months']>$plan['months']
                    ||$linear['used']<0||$linear['used']>$linear['months']||!is_string($switched)||!preg_match('/^[0-9]{8}-[0-9]{8}$/D',$switched)
                    ||!$plan['degressive']['switch_to_linear']
                    ||$linear['basis']-self::rounded($linear['basis'],$linear['used'],$linear['months'])!==$opening)throw new DomainException('Bestätigten Übergang zur linearen Abschreibung prüfen.');
            }elseif($switched!==null)throw new DomainException('Nachweis zum Methodenwechsel fehlt.');
        }else{
            if($previous!==null)throw new DomainException('Für einen Neuzugang darf kein früherer Anlagenwert eingesetzt werden.');
            $opening=$date<=$period['end']?$basis:0;
        }
        $active=max(0,$distance($period['end'])+1-$elapsed);
        $active=min(BalancePeriod::fullMonths($period),$active);
        $charge=0;
        if($opening>0){
            $degressive=intdiv($opening*$plan['degressive']['rate_basis_points']*$active+60000,120000);
            $remaining=max(0,$plan['months']-$elapsed);
            $linearAmount=$remaining>0?self::rounded($opening,min($remaining,$active),$remaining):$opening;
            if($linear===null&&$plan['degressive']['switch_to_linear']&&$linearAmount>=$degressive){
                $linear=['basis'=>$opening,'months'=>max(1,$remaining),'used'=>0];$switched=BalancePeriod::key($period);
            }
            if($linear!==null){$used=$linear['used'];$linear['used']=min($linear['months'],$used+$active);$charge=self::rounded($linear['basis'],$linear['used'],$linear['months'])-self::rounded($linear['basis'],$used,$linear['months']);}
            else $charge=$degressive;
            $charge=min($opening,$charge);
        }
        $costOpening=$before?$basis:0;$costClosing=$date<=$period['end']?$basis:0;
        return ['period'=>$period,'cost_opening_cents'=>$costOpening,'additions_cents'=>$costClosing-$costOpening,'cost_closing_cents'=>$costClosing,
            'accumulated_opening_cents'=>$openingDep,'depreciation_cents'=>$charge,'accumulated_closing_cents'=>$openingDep+$charge,
            'book_opening_cents'=>$costOpening-$openingDep,'book_closing_cents'=>$costClosing-$openingDep-$charge,
            'degressive_state'=>['elapsed_months'=>$elapsed+$active,'linear'=>$linear,'switched_period'=>$switched]];
    }
    private static function rounded(int $basis,int $used,int $months):int{return intdiv($basis*$used+intdiv($months,2),$months);}
}
