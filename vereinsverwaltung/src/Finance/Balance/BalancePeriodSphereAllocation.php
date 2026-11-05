<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
require_once __DIR__.'/BalancePeriodSphereChange.php';

/** Analytical whole-asset transfers. Book cost/capital and tax valuations stay distinct. */
final class BalancePeriodSphereAllocation
{
    public static function proof(array $item,array $period,?array $old):?array
    {
        if(!isset($item['sphere_change'])){
            if(isset($old['sphere_change']))throw new DomainException('Bestätigten Bereichswechsel im Folgezeitraum nicht entfernen.');
            return null;
        }
        $proof=$item['sphere_change'];
        if(!is_array($proof)||!is_array($proof['changes']??null)||isset($item['disposal'])
            ||BalancePeriodSphereChange::build($item['plan'],$period,$proof['changes'],$old['sphere_change']??null,$item['day_allocation_policy']??null)!==$proof
            ||$proof['book']['schedule']!==$item['schedule'])throw new DomainException('Originalanlage, Bereichswechsel und unmittelbaren Vortrag unverändert nachweisen.');
        return $proof;
    }

    public static function sphereAt(array $proof,string $on):string
    {
        $sphere=$proof['book']['opening_sphere'];
        foreach($proof['book']['movements'] as $m){if($m['on']>$on)break;$sphere=$m['to_sphere'];}
        return $sphere;
    }

    /** Missing area selection is an editable draft; a conflicting tax sphere is not. */
    public static function changes(array $changes,array $item,array $period,array $weights,array $units,array $proof):array
    {
        if(!array_is_list($changes)||count($changes)>12)throw new DomainException('Höchstens zwölf belegte Nutzungswechsel im Zeitraum angeben.');
        $out=[];$last='';
        foreach($changes as $c){
            if(!is_array($c)||!is_string($c['on']??null)||$c['on']<=$last)throw new DomainException('Nutzungswechsel chronologisch und ohne doppelte Monate angeben.');
            $copy=$item;$copy['plan']['sphere']=self::sphereAt($proof,$c['on']);
            // Reuse all ordinary date, weight, evidence and immutable acquisition checks.
            $clean=BalancePeriodAssetUse::changes([$c],$copy,$period,$weights,$units)[0];
            $out[]=$clean;$weights=$clean['weights'];$last=$clean['on'];
        }
        $missing=[];$dates=array_column($out,'on');
        foreach($proof['book']['movements'] as $m)if(!in_array($m['on'],$dates,true))$missing[]=$m['on'];
        return ['changes'=>$out,'missing'=>$missing];
    }

    /** Only an unambiguous destination is suggested; never guess between several businesses. */
    public static function suggest(array $proof,array $units,array $existing):array
    {
        $dates=array_column($existing,'on');
        foreach($proof['book']['movements'] as $m){
            if(in_array($m['on'],$dates,true))continue;
            $eligible=array_keys(array_filter($units,static fn(array $u):bool=>$u['sphere']===$m['to_sphere']));
            if(count($eligible)===1)$existing[]=['on'=>$m['on'],'weights'=>[$eligible[0]=>1],'reason'=>'Übernahme des belegten Bereichswechsels vom '.$m['on'].'.'];
        }
        usort($existing,static fn(array $a,array $b):int=>strcmp($a['on'],$b['on']));return $existing;
    }

    /** Check every analytical movement against the independent whole-asset chronology. */
    public static function checkMovements(array $item,array $period,array $units,array $changes):void
    {
        $proof=BalancePeriodSphereChange::rebuild($item['sphere_change']);
        if($proof['period']!==$period||$proof['plan']!==$item['plan']||$proof['book']['schedule']!==$item['schedule'])throw new DomainException('Bereichsbewegung gehört nicht zur Originalanlage.');
        $required=array_column($proof['book']['movements'],null,'on');$seen=[];$last='';
        foreach($changes as $change){
            if(!is_string($change['on']??null)||$change['on']<=$last||!BalancePeriod::contains($period,$change['on'])||!is_array($change['parts']??null))throw new DomainException('Chronologische Bereichsbewegungen prüfen.');
            $last=$change['on'];$m=$required[$last]??null;$totals=[];
            foreach($change['parts'] as $u=>$v){
                if(!isset($units[$u])||!is_array($v)||array_keys($v)!==BalancePeriodAssetUse::TRANSFERS)throw new DomainException('Bereich der Anlagenbewegung fehlt.');
                foreach(BalancePeriodAssetUse::TRANSFERS as $f){
                    if(!is_int($v[$f])||abs($v[$f])>BalanceCalculation::LIMIT)throw new DomainException('Anlagenbewegung in ganzen Cent prüfen.');
                    $sphere=$units[$u]['sphere'];$totals[$sphere][$f]=($totals[$sphere][$f]??0)+$v[$f];
                }
                if($v['cost_transfer_cents']-$v['accumulated_transfer_cents']!==$v['book_transfer_cents'])throw new DomainException('Brutto- und Nettobewegung stimmen nicht überein.');
                $allowed=$m?[$m['from_sphere'],$m['to_sphere']]:[self::sphereAt($proof,$last)];
                if(!in_array($units[$u]['sphere'],$allowed,true)&&array_filter($v))throw new DomainException('Anlagenbewegung widerspricht der belegten Bereichsfolge.');
                if($m)foreach($v as $amount)if(($units[$u]['sphere']===$m['from_sphere']&&$amount>0)||($units[$u]['sphere']===$m['to_sphere']&&$amount<0))throw new DomainException('Der ganze Gegenstand muss den bisherigen Bereich verlassen.');
            }
            foreach(['1','2','3','4'] as $sphere)foreach(['cost','accumulated','book'] as $field){
                $expected=$m?($sphere===$m['to_sphere']?1:($sphere===$m['from_sphere']?-1:0))*$m[$field.'_cents']:0;
                if(($totals[$sphere][$field.'_transfer_cents']??0)!==$expected)throw new DomainException('Bereichsbewegung enthält nicht den vollständigen ursprünglichen Anlagenwert.');
            }
            $seen[$last]=true;
        }
        if(array_diff_key($required,$seen))throw new DomainException('Nicht alle belegten Bereichswechsel sind den Betrieben zugeordnet.');
    }
}
