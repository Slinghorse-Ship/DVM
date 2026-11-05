<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
require_once __DIR__.'/BalanceClubTaxReconciliation.php';
require_once __DIR__.'/BalanceWholeClubReport.php';

/** Immutable calendar evidence at a dated boundary, never a new dated closing. */
final class BalanceCalendarFilingSource
{
    public static function packet(array $subsidiary,string $section):array
    {
        if(!in_array($section,['books','subsidiary'],true)||isset($subsidiary['period']))throw new DomainException('Ursprünglichen Kalenderabschluss nachweisen.');
        BalanceClubTaxReconciliation::verify($subsidiary);
        $source=$subsidiary['income']['scope']['source'];
        $values=$section==='books'?BalanceTaxValues::books(BalanceWholeClubReport::fromReport($source)):$subsidiary['valuation'];
        $schedule=BalancePeriodAssetAllocation::calendar($source,$subsidiary['income']['scope']['allocation']);
        $assets=BalancePeriodTaxAssetValues::calendar($subsidiary['income'],$values,$section==='books'?[]:$subsidiary['review']['asset_tax_plans']);
        return ['calendar_source'=>$subsidiary,'section'=>$section,'values'=>$values,'asset_schedule'=>$schedule,'asset_tax'=>$assets];
    }
    public static function verify(array $packet,string $section):array
    {
        if($section==='business'){
            $c=$packet['calendar_source']??null;
            if(!is_array($c)||array_keys($c)!==['source','review']||!is_array($c['review']))throw new DomainException('Geprüften Kalenderabschluss des Geschäftsbetriebs nachweisen.');
            $values=BalanceTaxValues::build($c['source'],$c['review']);
            if(self::businessPacket($c['source'],$values,$c['review'])!==$packet)throw new DomainException('Unverändertes Betriebsjournal und Steuerwerte nachweisen.');
            return $values;
        }
        if($section==='whole'){
            $c=$packet['calendar_source']??null;
            if(!is_array($c)||array_keys($c)!==['whole_club_scope','review']||!is_array($c['review']))throw new DomainException('Geprüfte Kalender-Gesamtbilanz nachweisen.');
            $values=BalanceTaxValues::build(BalanceWholeClubReport::rebuild($c['whole_club_scope']),$c['review']);
            if(self::wholePacket($values,$c['review'])!==$packet)throw new DomainException('Unveränderte Kalender-Gesamtbilanz und Steuerwerte nachweisen.');
            return $values;
        }
        if(!is_array($packet['calendar_source']??null)||self::packet($packet['calendar_source'],$section)!==$packet)throw new DomainException('Unveränderten Kalender-Vorabschluss und Anlagenvortrag nachweisen.');
        return $packet['values'];
    }
    public static function wholePacket(array $values,array $review):array
    {
        if(isset($values['period'])||($values['whole_club_scope']['schema']??null)!==1)throw new DomainException('Vollständigen Kalender-Vorabschluss auswählen.');
        $source=BalanceWholeClubReport::rebuild($values['whole_club_scope']);
        if(BalanceTaxValues::build($source,$review)!==$values||!($values['capital_comparison']['complete']??false))throw new DomainException('Bestätigte Kalender-Steuerwerte und Kapitalbewegungen unverändert übernehmen.');
        return ['calendar_source'=>['whole_club_scope'=>$values['whole_club_scope'],'review'=>$review],'section'=>'whole','values'=>$values,
            'asset_schedule'=>BalancePeriodAssetAllocation::calendar($source,null),
            'asset_tax'=>BalancePeriodTaxAssetValues::calendarWhole($source,$values,$review['asset_tax_plans']??[])];
    }
    public static function businessPacket(array $source,array $values,array $review):array
    {
        require_once __DIR__.'/BalancePeriodBusinessFiling.php';
        if(isset($source['period'])||isset($values['period'])||isset($values['whole_club_scope'])||isset($values['club_scope'])||isset($review['capital_movements']))throw new DomainException('Gesonderten Kalender-Geschäftsbetrieb auswählen.');
        $source=BalanceWholeClubReport::fromReport($source)['whole_club_scope']['source'];
        BalancePeriodBusinessFiling::assertSource($source);
        if(BalanceTaxValues::build($source,$review)!==$values)throw new DomainException('Bestätigte Kalender-Steuerwerte unverändert übernehmen.');
        return ['calendar_source'=>['source'=>$source,'review'=>$review],'section'=>'business','values'=>$values,
            'asset_schedule'=>BalancePeriodAssetAllocation::calendar($source,null),
            'asset_tax'=>BalancePeriodTaxAssetValues::calendarWhole($source,$values,$review['asset_tax_plans']??[])];
    }
}
