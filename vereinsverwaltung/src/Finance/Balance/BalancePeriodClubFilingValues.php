<?php declare(strict_types=1);
namespace Kulturhus\Verein;
require_once __DIR__.'/BalanceSourceRead.php';
use DomainException;
require_once __DIR__.'/BalancePeriodClubReport.php';
require_once __DIR__.'/BalancePeriodFilingValues.php';

/** Dated SGEB values with full original books and allocation evidence retained. */
final class BalancePeriodClubFilingValues
{
    public static function raw(array $values):array
    {
        unset($values['period_club'],$values['sha256'],$values['transmission']);
        return $values+['sha256'=>BalanceSegmentCalculation::hash($values),'transmission'=>'unavailable'];
    }
    public static function build(array $income,array $review,?array $previous=null,int $depth=0):array
    {
        return BalanceSourceRead::run(static fn()=>self::buildFromSources($income,$review,$previous,$depth));
    }
    private static function buildFromSources(array $income,array $review,?array $previous=null,int $depth=0):array
    {
        if($depth>100)throw new DomainException('Betriebliche Vorzeitraumkette prüfen.');
        $report=BalancePeriodClubReport::build($income,0,BalanceCalendarClubSource::scope($previous));$prior=null;$wholePrior=null;
        if($previous!==null){
            if(isset($previous['calendar_club'])){
                BalanceCalendarClubSource::verify($previous);
                if($income['scope']['previous']!==$previous['calendar_club']['income']['scope'])throw new DomainException('Kalender-Originalaufteilung unverändert vortragen.');
                $prior=BalanceCalendarClubSource::packet($previous,'club');$wholePrior=BalanceCalendarClubSource::packet($previous,'club_books');
            }else{
            $p=$previous['period_club']??null;
            if(!is_array($p)||array_keys($p)!==['income','whole','previous']||self::build($p['income'],$previous['period_source']['review'],$p['previous'],$depth+1)!==$previous
                ||$income['scope']['previous']!==$p['income']['scope'])throw new DomainException('Vollständigen ursprünglichen Betriebs-Vorabschluss unverändert übernehmen.');
            $prior=self::raw($previous);
            $wholePrior=$p['whole'];
            }
        }elseif($income['scope']['previous']!==null)throw new DomainException('Bestätigte Steuerwerte des unmittelbaren Vorzeitraums fehlen.');
        $values=BalancePeriodFilingValues::build(BalancePeriodClubReport::source($report),$review,$prior);
        $whole=BalancePeriodFilingValues::books(BalancePeriodClubReport::source($income['scope']['source']),$wholePrior);
        unset($values['sha256'],$values['transmission']);$values['period_club']=['income'=>$income,'whole'=>$whole,'previous'=>$previous];
        return $values+['sha256'=>BalanceSegmentCalculation::hash($values),'transmission'=>'unavailable'];
    }
}
