<?php declare(strict_types=1);
namespace Kulturhus\Verein;
require_once __DIR__.'/BalanceSourceRead.php';
use DomainException;
require_once __DIR__.'/BalancePeriodTaxValues.php';
require_once __DIR__.'/BalanceWholeClubReport.php';
require_once __DIR__.'/BalanceCapitalComparison.php';
require_once __DIR__.'/BalanceCalendarFilingSource.php';

/** Generator boundary only. No persistence, approval, route or sending permission. */
final class BalancePeriodFilingValues
{
    public static function build(array $source,array $review,?array $previous=null,int $depth=0):array
    {
        return BalanceSourceRead::run(static fn()=>self::buildFromSources($source,$review,$previous,$depth));
    }
    private static function buildFromSources(array $source,array $review,?array $previous=null,int $depth=0):array
    {return self::reconstruct($source,$review,$previous,$depth,'single');}
    /** Whole non-tax-privileged corporation, never a promoted partial journal. */
    public static function whole(array $source,array $review,?array $previous=null,int $depth=0):array
    {return self::reconstruct($source,$review,$previous,$depth,'whole');}
    /** Original books for a checked composite report, NOT a taxable whole-club filing. */
    public static function books(array $source,?array $previous=null,int $depth=0):array
    {
        $review=['period'=>$source['period'],'year'=>$source['year'],'journal_sha256'=>$source['closed_sha256'],'complete'=>true,
            'opening_adjustments'=>[],'current_adjustments'=>[],'asset_tax_plans'=>[]];
        return self::reconstruct($source,$review,$previous,$depth,'books');
    }
    private static function reconstruct(array $source,array $review,?array $previous,int $depth,string $kind):array
    {
        if($depth>100)throw new DomainException('Vorzeitraumkette prüfen.');
        $whole=$kind!=='single';$books=$kind==='books';
        $scope=null;
        if($whole){
            $scope=BalanceWholeClubReport::fromReport($source)['whole_club_scope'];$source=$scope['source'];
            if($scope['schema']!==2||(!$books&&!is_array($review['capital_movements']??null)))throw new DomainException('Datierte Gesamtbilanz und Kapitalbewegungen vollständig bestätigen.');
        }elseif(array_key_exists('capital_movements',$review))throw new DomainException('Kapitalvergleich gehört zur vollständigen Vereinsbilanz.');
        if(!$whole){require_once __DIR__.'/BalancePeriodBusinessFiling.php';BalancePeriodBusinessFiling::assertSource($source);}
        if($books&&($review['opening_adjustments']!==[]||$review['current_adjustments']!==[]||$review['asset_tax_plans']!==[]||isset($review['capital_movements'])))throw new DomainException('Originalbuchwerte unverändert ausgeben.');
        $priorValues=null;
        if($previous!==null){
            if(array_key_exists('calendar_source',$previous)){
                if(in_array($previous['section']??null,['club','club_books'],true)){
                    require_once __DIR__.'/BalanceCalendarClubSource.php';
                    $section=$books?'club_books':'club';
                    if(($whole&&!$books)||BalanceCalendarClubSource::packet($previous['calendar_source'],$section)!==$previous)throw new DomainException('Kalender-Betriebsprofil und Originalnachweis prüfen.');
                    $priorValues=$previous['values'];$c=$previous['calendar_source']['calendar_club'];$priorSource=$books?$c['income']['scope']['source']:$c['report'];
                }else{
                    $priorValues=BalanceCalendarFilingSource::verify($previous,$whole?($books?'books':'whole'):'business');
                    $priorSource=$whole?($books?$previous['calendar_source']['income']['scope']['source']:$previous['calendar_source']['whole_club_scope']['source']):$previous['calendar_source']['source'];
                }
                $carry=BalancePeriodTaxValues::carryForward($priorSource,$priorValues,$source);
                if($review['opening_adjustments']!==($carry['entry']?[$carry['entry']]:[]))throw new DomainException('Kalender-Vortrag unverändert übernehmen.');
                // Preserve the original valuation/hash; this view is only the
                // independently reconstructed asset seed for period arithmetic.
                $priorValues['asset_tax']=$previous['asset_tax'];
            }else{
            $p=$previous['period_source']??null;
            if(!is_array($p)||array_keys($p)!==['source','review','previous']
                ||self::reconstruct($p['source'],$p['review'],$p['previous'],$depth+1,$kind)!==$previous)throw new DomainException('Unveränderten geprüften Vorzeitraum übernehmen.');
            $priorValues=$previous;unset($priorValues['period_source'],$priorValues['sha256'],$priorValues['transmission'],$priorValues['whole_club_scope'],$priorValues['capital_comparison'],$priorValues['period_section']);
            $priorValues+=['sha256'=>BalanceSegmentCalculation::hash($priorValues),'transmission'=>'unavailable'];
            $carry=BalancePeriodTaxValues::carryForward($p['source'],$priorValues,$source);
            if($review['opening_adjustments']!==($carry['entry']?[$carry['entry']]:[]))throw new DomainException('Steuerlichen Vortrag unverändert übernehmen.');
            }
        }elseif(($source['previous_sha256']??null)!==null)throw new DomainException('Geprüfter Vorzeitraum fehlt.');
        $values=BalancePeriodTaxValues::build($source,$review,$priorValues,$kind==='whole',$books?'books':'');
        if(!$whole&&array_diff($values['source_spheres'],['4']))throw new DomainException('Datierter Generator: Vereinsaufteilung benötigt ihre vollständige Exportprüfung.');
        unset($values['sha256'],$values['transmission']);
        if($whole){
            $values['whole_club_scope']=$scope;
            if($books){
                if($values['book']!==$values['tax'])throw new DomainException('Originalbuchwerte unverändert ausgeben.');
                $values['period_section']='books';
            }else{
                $values['capital_comparison']=BalanceCapitalComparison::build($source,array_replace($review,['current_adjustments'=>$values['current_adjustments']]),$values['tax']);
                if(!$values['capital_comparison']['complete'])throw new DomainException('Kapitalbewegungen vor der Gesamtvereinsausgabe vollständig prüfen.');
            }
        }
        $values['period_source']=['source'=>$source,'review'=>$review,'previous'=>$previous];
        return $values+['sha256'=>BalanceSegmentCalculation::hash($values),'transmission'=>'unavailable'];
    }
}
