<?php declare(strict_types=1);
namespace Kulturhus\Verein;
require_once __DIR__.'/BalanceSourceRead.php';
use DomainException;
require_once __DIR__.'/BalanceClubIncomeReconciliation.php';
require_once __DIR__.'/BalanceClubTaxReport.php';
require_once __DIR__.'/BalanceTaxValues.php';
require_once __DIR__.'/BalanceWholeClubReport.php';
require_once __DIR__.'/BalanceCombinedClubValues.php';

/** Exact legacy aggregate evidence. No calendar journal/hash is rewritten. */
final class BalanceCalendarClubSource
{
    public static function report(array $income,array $scope,int $depth=0):array
    {
        return BalanceSourceRead::run(static fn()=>self::reportFromSources($income,$scope,$depth));
    }
    private static function reportFromSources(array $income,array $scope,int $depth=0):array
    {
        if($depth>100||isset($income['period'])||BalanceClubIncomeReconciliation::rebuild($income['scope']??[])!==$income)
            throw new DomainException('Ursprünglichen Kalender-Bereichsabgleich nachweisen.');
        $r=BalanceClubTaxReport::rebuild($scope);$i=$income['scope'];
        if(BalanceWholeClubReport::fromReport($scope['source'])['whole_club_scope']['source']!==$i['source']
            ||BalanceSegmentCalculation::rebuild($scope['source'],$scope['allocation'])!==$i['allocation']
            ||$scope['review']!==$i['review']['allocation'])throw new DomainException('Kalender-Teilbilanz gehört nicht zur bestätigten Originalaufteilung.');
        if($i['clearing']['cases'])throw new DomainException('Kalender-Vorschüsse über die geprüfte Nebenrechnung übernehmen.');
        if($i['previous']!==null)self::report(BalanceClubIncomeReconciliation::rebuild($i['previous']),$scope['previous'],$depth+1);
        elseif($scope['previous']!==null)throw new DomainException('Unerwarteten Kalender-Vorabschluss prüfen.');
        return $r;
    }
    public static function record(array $income,array $report,array $values,array $review):array
    {
        if(self::report($income,$report['club_scope']??[])!==$report||BalanceTaxValues::build($report,$review)!==$values)
            throw new DomainException('Unveränderte bestätigte Kalender-Steuerwerte übernehmen.');
        $source=BalanceWholeClubReport::fromReport($income['scope']['source']);
        $whole=BalanceTaxValues::books($source);
        return ['calendar_club'=>['income'=>$income,'report'=>$report,'review'=>$review],'taxable'=>$values,'whole'=>$whole];
    }
    public static function verify(array $record):array
    {
        return BalanceSourceRead::run(static fn()=>self::verifyFromSources($record));
    }
    private static function verifyFromSources(array $record):array
    {
        $c=$record['calendar_club']??null;
        if(!is_array($c)||array_keys($c)!==['income','report','review'])throw new DomainException('Kalendernachweis fehlt.');
        $expected=self::record($c['income'],$c['report'],$record['taxable']??[],$c['review']);
        if(isset($record['combined']))$expected['combined']=BalanceCombinedClubValues::fromReport($c['report'],['year'=>$c['report']['year'],'journal_sha256'=>$c['income']['scope']['source']['closed_sha256'],'complete'=>true,'taxable_review'=>$c['review']]);
        if($expected!==$record)
            throw new DomainException('Vollständigen unveränderten Kalendernachweis übernehmen.');
        return $record;
    }
    public static function packet(array $record,string $section):array
    {
        self::verify($record);
        if(!in_array($section,['club','club_books'],true))throw new DomainException('Kalender-Berichtsbestandteil auswählen.');
        $c=$record['calendar_club'];$books=$section==='club_books';$source=$books?$c['income']['scope']['source']:$c['report'];$values=$record[$books?'whole':'taxable'];
        return ['calendar_source'=>$record,'section'=>$section,'values'=>$values,'asset_schedule'=>BalancePeriodAssetAllocation::calendar($source,null),
            'asset_tax'=>BalancePeriodTaxAssetValues::calendarWhole($source,$values,$books?[]:($c['review']['asset_tax_plans']??[]))];
    }
    public static function scope(?array $values):?array
    {
        for($depth=0;$values!==null&&$depth<=100;$depth++){
            if(isset($values['calendar_club']))return self::verify($values)['calendar_club']['report']['club_scope'];
            $values=$values['period_club']['previous']??null;
        }
        if($depth>100)throw new DomainException('Kalender-Vortragskette prüfen.');
        return null;
    }
}
