<?php declare(strict_types=1);
namespace Kulturhus\Verein;
require_once __DIR__.'/BalanceSourceRead.php';
use DomainException;
require_once __DIR__.'/BalanceCalculation.php';

/** Complete ledger, never a sphere-filtered substitute for a taxable corporation. */
final class BalanceWholeClubReport
{
    public const FIELDS=['year','state','closed_sha256','previous_sha256','result_account','issues','accounts','opening','entries','calculation','asset_schedule'];
    public static function fromReport(array $report):array
    {
        $source=[];foreach(self::FIELDS as $key){if(!array_key_exists($key,$report))throw new DomainException('Vollständigen festgeschriebenen Vereinsabschluss nachweisen.');$source[$key]=$report[$key];}
        if(array_key_exists('period',$report))$source['period']=BalancePeriod::check($report['period'],$report['year']);
        return self::rebuild(['schema'=>isset($source['period'])?2:1,'kind'=>'whole_taxable_club','source'=>$source]);
    }
    public static function rebuild(array $scope):array
    {
        return BalanceSourceRead::run(static fn()=>self::rebuildFromSources($scope));
    }
    private static function rebuildFromSources(array $scope):array
    {
        if(array_keys($scope)!==['schema','kind','source']||!in_array($scope['schema'],[1,2],true)||$scope['kind']!=='whole_taxable_club'||!is_array($scope['source']))throw new DomainException('Geltungsbereich der Gesamtvereinsbilanz prüfen.');
        $r=$scope['source'];
        $fields=$scope['schema']===2?[...self::FIELDS,'period']:self::FIELDS;
        if(array_keys($r)!==$fields||$r['state']!=='closed'||$r['issues']!==[]||!is_string($r['closed_sha256'])||!preg_match('/^[a-f0-9]{64}$/D',$r['closed_sha256'])
            ||($r['previous_sha256']!==null&&(!is_string($r['previous_sha256'])||!preg_match('/^[a-f0-9]{64}$/D',$r['previous_sha256'])))
            ||!is_string($r['result_account'])||($r['accounts'][$r['result_account']]['group']??null)!=='equity')throw new DomainException('Vollständigen festgeschriebenen Vereinsabschluss prüfen.');
        $period=$scope['schema']===2?BalancePeriod::check($r['period'],$r['year']):null;
        if(BalanceCalculation::build($r['year'],$r['accounts'],$r['opening'],$r['entries'],$period)!==$r['calculation']||$r['calculation']['issues'])throw new DomainException('Gesamtbilanz und Originalbuchungen stimmen nicht überein.');
        // No fabricated empty schedule, including fully depreciated retained items.
        if(!is_array($r['asset_schedule'])||($period!==null?($r['asset_schedule']['period']??null)!==$period:($r['asset_schedule']['year']??null)!==$r['year'])||($r['asset_schedule']['issues']??null)!==[]||!is_array($r['asset_schedule']['items']??null))throw new DomainException('Festgeschriebenes Anlagenverzeichnis des gesamten Vereins erforderlich.');
        return $r+['whole_club_scope'=>$scope];
    }
}
