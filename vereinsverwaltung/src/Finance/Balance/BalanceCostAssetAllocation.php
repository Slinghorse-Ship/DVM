<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
require_once __DIR__.'/BalanceCostAssetSources.php';
require_once __DIR__.'/BalanceAssetAllocationDisposal.php';

/** Analytical BOOK use shares. Original acquisition and tax valuation remain whole. */
final class BalanceCostAssetAllocation
{
    public static function split(array $value,array $weights):array
    {
        $s=$value['source']??null;
        if(!is_array($s)||AssetValuation::calculate($s['plan'],$s['period'],$s['events'],$s['previous'])!==$value)
            throw new DomainException('Unveränderte Originalbewertung vor der Nutzungsaufteilung nachweisen.');
        return self::replay($value,$weights,0);
    }
    private static function replay(array $value,array $weights,int $depth):array
    {
        if($depth>100)throw new DomainException('Nutzungsvortrag prüfen.');
        $zero=BalanceAssetAllocation::cents(0,$weights);$old=$value['source']['previous'];
        $previous=$old===null?null:self::replay($old,$weights,$depth+1);
        $fields=[...BalanceAssetAllocation::FIELDS,...BalanceAssetAllocationDisposal::FIELDS,'impairment_cents','writeup_cents'];
        $parts=[];$cost=[];$accum=[];$added=BalanceAssetAllocation::cents($value['book']['additions_cents'],$weights);
        foreach($zero as $u=>$_){
            $parts[$u]=array_fill_keys($fields,0);$p=$previous['parts'][$u]??[];
            foreach(['cost','accumulated','book'] as $f)$parts[$u][$f.'_opening_cents']=$p[$f.'_closing_cents']??0;
            $parts[$u]['additions_cents']=$added[$u];$cost[$u]=$parts[$u]['cost_opening_cents']+$added[$u];$accum[$u]=$parts[$u]['accumulated_opening_cents'];
        }
        $events=[];
        foreach($value['movements'] as $i=>$movement){
            $book=[];foreach($cost as $u=>$n)$book[$u]=$n-$accum[$u];$m=$movement['book'];$split=[];
            if($movement['kind']==='disposal'){
                $released=$value['source']['events'][$i]['cost_cents'];
                $lostBook=BalanceAssetAllocationDisposal::capped($m['book_disposals_cents'],$weights,$book);
                $lostAccum=BalanceAssetAllocationDisposal::capped($released-$m['book_disposals_cents'],$weights,$accum);
                foreach($zero as $u=>$_){$parts[$u]['book_disposals_cents']+=$lostBook[$u];$parts[$u]['accumulated_disposals_cents']+=$lostAccum[$u];
                    $parts[$u]['disposals_cents']+=$lostBook[$u]+$lostAccum[$u];$cost[$u]-=$lostBook[$u]+$lostAccum[$u];$accum[$u]-=$lostAccum[$u];
                    $split[$u]=['impairment_cents'=>0,'writeup_cents'=>0,'book_disposals_cents'=>$lostBook[$u]];}
            }else{
                $down=BalanceAssetAllocationDisposal::capped($m['impairment_cents'],$weights,$book);
                $up=BalanceAssetAllocationDisposal::capped($m['writeup_cents'],$weights,$accum);
                foreach($zero as $u=>$_){$parts[$u]['impairment_cents']+=$down[$u];$parts[$u]['writeup_cents']+=$up[$u];$accum[$u]+=$down[$u]-$up[$u];
                    $split[$u]=['impairment_cents'=>$down[$u],'writeup_cents'=>$up[$u],'book_disposals_cents'=>0];}
            }
            $events[$movement['event_id']]=$split;
        }
        foreach($zero as $u=>$_){$parts[$u]['cost_closing_cents']=$cost[$u];$parts[$u]['accumulated_closing_cents']=$accum[$u];$parts[$u]['book_closing_cents']=$cost[$u]-$accum[$u];
            if(min($parts[$u])<0)throw new DomainException('Negativer Kostenanteil.');AssetValuation::reconcile(array_intersect_key($parts[$u],array_flip(AssetValuation::FIELDS)));}
        foreach(AssetValuation::FIELDS as $f)if(array_sum(array_column($parts,$f))!==$value['book'][$f])throw new DomainException('Originale Centbeträge verändert.');
        return ['parts'=>$parts,'events'=>$events];
    }
    public static function movements(array $item,array $chart,array $weights):array
    {
        $value=$item['valuation'];$split=self::split($value,$weights);$out=[];$codes=[];$retained=[];
        foreach($value['source']['events'] as $event){
            $type=BalanceCostAssetSources::codes($value['source']['plan']['kind'],$event['kind'],$event['permanent']??true,$value['source']['plan']['financial_profile']??null,$event['counter_code']??null,$value['source']['plan']['account']);$code=$type['code'];$group=$type['group'];
            $key=$item['valuation_accounts'][$code]??null;
            if(!is_string($key)||$key!==FinancialInvestment::key($code,$value['source']['plan']['financial_profile']??null)||($chart[$key]['skr_code']??null)!==$code||($chart[$key]['group']??null)!==$group)
                throw new DomainException('Originales Bewertungsgegenkonto der Nutzungsaufteilung prüfen.');
            $retained[$code]=$key;$codes[$code]=true;
            foreach($split['events'][$event['id']] as $u=>$v){$n=$group==='revenue'?$v['writeup_cents']:$v['impairment_cents']+$v['book_disposals_cents'];$out[$u][$key]=($out[$u][$key]??0)+$n;}
        }
        if($retained!==($item['valuation_accounts']??[]))throw new DomainException('Unveränderten Gegenkontennachweis vollständig übernehmen.');
        return ['amounts'=>$out,'codes'=>$codes];
    }
}
