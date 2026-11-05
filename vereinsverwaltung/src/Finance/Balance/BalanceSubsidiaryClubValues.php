<?php declare(strict_types=1);
namespace Kulturhus\Verein;
require_once __DIR__.'/BalanceClubTaxReconciliation.php';
require_once __DIR__.'/BalancePeriodFilingValues.php';

/** Original B/GuV and SGEB subsidiary result. No invented business balance sheet. */
final class BalanceSubsidiaryClubValues
{
    public const KIND='tax_privileged_club_with_subsidiary';
    public static function build(array $subsidiary,int $depth=0):array
    {
        if($depth>100)throw new \DomainException('Vorzeitraumkette prüfen.');
        BalanceClubTaxReconciliation::verify($subsidiary);
        $r=BalanceWholeClubReport::fromReport($subsidiary['income']['scope']['source']);
        $dated=isset($r['period']);
        $previous=null;
        if($dated&&$subsidiary['previous']!==null)$previous=isset($subsidiary['previous']['period'])
            ?self::build($subsidiary['previous'],$depth+1)['whole']:BalanceCalendarFilingSource::packet($subsidiary['previous'],'books');
        $whole=$dated?BalancePeriodFilingValues::books($r,$previous):BalanceTaxValues::books($r);
        $p=['schema'=>1,'kind'=>self::KIND,'year'=>$r['year'],'journal_sha256'=>$r['closed_sha256'],'whole'=>$whole,'subsidiary'=>$subsidiary];
        if($dated){$p['schema']=2;$p['period']=$r['period'];}
        return $p+['sha256'=>BalanceSegmentCalculation::hash($p),'transmission'=>'unavailable'];
    }
}
