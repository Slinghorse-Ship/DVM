<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;

/** Dated analytical use changes within one tax sphere. Never a new acquisition or tax election. */
final class BalancePeriodAssetUse
{
    public const TRANSFERS=['cost_transfer_cents','accumulated_transfer_cents','book_transfer_cents'];

    public static function closingWeights(array $share):array
    {
        $changes=$share['changes']??[];return $changes?end($changes)['weights']:$share['weights'];
    }

    public static function changes(array $changes,array $item,array $period,array $weights,array $units):array
    {
        if(!$changes||!array_is_list($changes)||count($changes)>12)throw new DomainException('Zwischen einem und zwölf belegten Nutzungswechseln angeben.');
        $last='';$out=[];
        foreach($changes as $change){
            if(!is_array($change)||array_keys($change)!==['on','weights','reason']||!is_string($change['on'])
                ||!BalancePeriod::contains($period,$change['on'])||substr($change['on'],8)!=='01'||$change['on']<=$last
                ||$change['on']<=$item['plan']['acquired_on']||!is_array($change['weights'])||array_diff_key($change['weights'],$units)
                ||!is_string($change['reason'])||trim($change['reason'])===''||mb_strlen($change['reason'])>500||preg_match('/[\x00-\x1f]/',$change['reason']))
                throw new DomainException('Nutzungswechsel mit Monatsbeginn nach Anschaffung, neuen Anteilen und Nachweis angeben.');
            if(isset($item['disposal'])&&$item['disposal']['kind']!=='partial_scrap'&&$change['on']>$item['disposal']['date'])throw new DomainException('Nach vollständigem Abgang kann die Nutzung nicht mehr geändert werden.');
            $next=$change['weights'];ksort($next,SORT_STRING);BalanceAssetAllocation::cents(0,$next);
            foreach($next as $unit=>$_)if($units[$unit]['sphere']!==$item['plan']['sphere'])throw new DomainException('Ein steuerlicher Bereichswechsel benötigt einen gesonderten Bewertungs- und Steuernachweis.');
            if($next===$weights)throw new DomainException('Die neuen Nutzungsanteile sind unverändert.');
            $out[]=['on'=>$change['on'],'weights'=>$next,'reason'=>trim($change['reason'])];$weights=$next;$last=$change['on'];
        }
        return $out;
    }

    /** Prefixes always use the ORIGINAL financial-year opening, including degressive depreciation.
     * Treating each use phase as a new financial year would restart depreciation incorrectly. */
    public static function split(array $item,array $period,array $weights,array $changes,?array $previous):array
    {
        $fields=BalancePeriodAssetAllocation::FIELDS;$allWeights=$weights;
        foreach($changes as $change)$allWeights+=$change['weights'];ksort($allWeights,SORT_STRING);
        $out=[];foreach($allWeights as $u=>$_)$out[$u]=array_fill_keys([...$fields,...self::TRANSFERS],0);
        $prefix=static function(?string $end,array $w)use($item,$period,$previous,$fields):array{
            if($end===null){
                $parts=BalanceAssetAllocation::item(BalancePeriodAssetAllocation::flat($item,$period),$w,(int)substr($period['end'],0,4),$period);
                foreach($parts as &$v){foreach($fields as $f)if(!str_ends_with($f,'_opening_cents'))$v[$f]=0;
                    foreach(['cost','accumulated','book'] as $f)$v[$f.'_closing_cents']=$v[$f.'_opening_cents'];}unset($v);return $parts;
            }
            if($end<$item['plan']['acquired_on']){ $zero=[];foreach($w as $u=>$_)$zero[$u]=array_fill_keys($fields,0);return $zero; }
            $p=['start'=>$period['start'],'end'=>$end];$copy=$item;
            if(isset($copy['disposal'])&&$copy['disposal']['date']>$end)unset($copy['disposal']);
            $copy['schedule']=BalancePeriodDisposal::calculate($copy['plan'],$p,$previous,$copy['disposal']??null,$copy['day_allocation_policy']??null);
            return BalanceAssetAllocation::item(BalancePeriodAssetAllocation::flat($copy,$p),$w,(int)substr($end,0,4),$p);
        };
        $initial=$prefix(null,$weights);$last=$initial;$movements=[];$start=$period['start'];$changeIndex=0;
        foreach($initial as $u=>$v)foreach(['cost','accumulated','book'] as $f)$out[$u][$f.'_opening_cents']=$v[$f.'_opening_cents'];
        // A change on the first day still preserves the predecessor's opening balances.
        while(true){
            $change=$changes[$changeIndex]??null;
            if($change!==null&&$change['on']===$start){
                $boundary=$start===$period['start']?null:(new \DateTimeImmutable($start))->modify('-1 day')->format('Y-m-d');
                $next=$prefix($boundary,$change['weights']);$parts=[];
                foreach($allWeights as $u=>$_){$v=[];foreach(['cost','accumulated','book'] as $f){$key=$f.'_transfer_cents';$v[$key]=($next[$u][$f.'_closing_cents']??0)-($last[$u][$f.'_closing_cents']??0);$out[$u][$key]+=$v[$key];}$parts[$u]=$v;}
                $movements[]=['on'=>$start,'parts'=>$parts];$weights=$change['weights'];$last=$next;++$changeIndex;continue;
            }
            $end=$change===null?$period['end']:(new \DateTimeImmutable($change['on']))->modify('-1 day')->format('Y-m-d');
            $before=$start===$period['start']?null:(new \DateTimeImmutable($start))->modify('-1 day')->format('Y-m-d');
            $a=$prefix($before,$weights);$b=$prefix($end,$weights);
            foreach($weights as $u=>$_){
                foreach(['additions_cents','depreciation_cents','disposals_cents','accumulated_disposals_cents','book_disposals_cents'] as $f)$out[$u][$f]+=($b[$u][$f]??0)-($a[$u][$f]??0);
            }
            $last=$b;if($change===null)break;$start=$change['on'];
        }
        foreach($out as $u=>&$v){
            foreach(['cost','accumulated','book'] as $f)$v[$f.'_closing_cents']=$last[$u][$f.'_closing_cents']??0;
            if(min(array_intersect_key($v,array_flip($fields)))<0
                ||$v['cost_opening_cents']+$v['additions_cents']-$v['disposals_cents']+$v['cost_transfer_cents']!==$v['cost_closing_cents']
                ||$v['accumulated_opening_cents']+$v['depreciation_cents']-$v['accumulated_disposals_cents']+$v['accumulated_transfer_cents']!==$v['accumulated_closing_cents']
                ||$v['cost_transfer_cents']-$v['accumulated_transfer_cents']!==$v['book_transfer_cents'])throw new DomainException('Nutzungswechsel und ursprüngliche Anlagenbewegungen stimmen nicht überein.');
        }unset($v);
        foreach($fields as $f)if(array_sum(array_column($out,$f))!==($item['schedule'][$f]??0))throw new DomainException('Nutzungswechsel verändert den ursprünglichen Gesamtanlagenwert.');
        foreach(self::TRANSFERS as $f)if(array_sum(array_column($out,$f))!==0)throw new DomainException('Interne Anlagenumbuchung muss sich im Verein aufheben.');
        return ['parts'=>$out,'movements'=>$movements];
    }
}
