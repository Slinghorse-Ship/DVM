<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
require_once __DIR__.'/BalancePeriodClubFilingValues.php';
require_once __DIR__.'/BalanceCombinedClubValues.php';

/** Original dated whole-club books plus ALL taxable businesses, one source. */
final class BalancePeriodCombinedValues
{
    public static function fromPartial(array $taxable):array
    {
        $p=$taxable['period_club']??null;
        if(!is_array($p)||BalancePeriodClubFilingValues::build($p['income'],$taxable['period_source']['review'],$p['previous'])!==$taxable)
            throw new DomainException('Gesamtbilanz und steuerliche Teilbilanz unverändert aus derselben geprüften Aufteilung übernehmen.');
        $whole=$p['whole'];
        $payload=['schema'=>2,'kind'=>'tax_privileged_club_with_sgeb','year'=>$whole['year'],'period'=>$whole['period'],
            'journal_sha256'=>$whole['journal_sha256'],'whole'=>$whole,'taxable'=>$taxable,
            'reconciliation'=>BalanceCombinedClubValues::reconciliation($whole,$taxable)];
        return $payload+['sha256'=>BalanceSegmentCalculation::hash($payload),'transmission'=>'unavailable'];
    }
    public static function build(array $income,array $review,?array $previous=null):array
    {
        if($previous!==null&&isset($previous['calendar_club'])){
            if(!isset($previous['combined']))throw new DomainException('Bestätigten gemeinsamen Kalenderabschluss auswählen.');
            BalanceCalendarClubSource::verify($previous);$prior=$previous;
        }else{
            if($previous!==null&&(!is_array($previous['taxable']??null)||self::fromPartial($previous['taxable'])!==$previous))
                throw new DomainException('Unveränderten gemeinsamen Vorabschluss des Gesamtvereins auswählen.');
            $prior=$previous['taxable']??null;
        }
        return self::fromPartial(BalancePeriodClubFilingValues::build($income,$review,$prior));
    }
}
