<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
require_once dirname(__DIR__, 2).'/Finance/Assets/AssetDisposal.php';
require_once __DIR__.'/BalanceDisposalAccounts.php';

/** Original whole-asset evidence. Analytical shares never create new disposal events. */
final class BalanceAssetAllocationDisposal
{
    public const FIELDS=['disposals_cents','accumulated_disposals_cents','book_disposals_cents'];
    public static function fields(array $item):array
    {
        $present=array_intersect(self::FIELDS,array_keys($item));
        if($present&&count($present)!==3)throw new DomainException('Abgangswerte vollständig nachweisen.');
        $valuation=array_intersect(['impairment_cents','writeup_cents'],array_keys($item));
        if($valuation&&(count($valuation)!==2||count($present)!==3))throw new DomainException('Bewertungsbewegungen vollständig nachweisen.');
        return array_merge(BalanceAssetAllocation::FIELDS,$present?self::FIELDS:[],$valuation?['impairment_cents','writeup_cents']:[]);
    }
    public static function proof(array $item,array $plan):?array
    {
        if(!array_key_exists('disposal',$item)){
            if(array_intersect(['basis_cents','disposal_hash','disposal_accounts','partial_plan'],array_keys($item)))throw new DomainException('Unvollständiger Anlagenabgang.');
            foreach(self::FIELDS as $f)if(array_key_exists($f,$item)&&$item[$f]!==0)throw new DomainException('Abgang ohne Belegnachweis.');
            return null;
        }
        $p=$item['disposal'];
        if(!is_array($p)||!in_array($p['kind']??null,['scrap','sale','software_end','partial_scrap'],true)
            ||!is_string($item['disposal_hash']??null)||!preg_match('/^[a-f0-9]{64}$/D',$item['disposal_hash'])
            ||count(self::fields($item))!==11)throw new DomainException('Vollständigen belegten Anlagenabgang prüfen.');
        if($p['kind']==='partial_scrap'){
            $original=$item['partial_plan']??null;if(!is_array($original))throw new DomainException('Ursprünglichen Plan des Teilabgangs nachweisen.');
            foreach(['basis_cents','residual_cents','acquired_on','months','method'] as $f)if(($original[$f]??null)!==($plan[$f]??null))throw new DomainException('Teilabgang und ursprünglicher Abschreibungsplan stimmen nicht überein.');
            if(($original['account']??null)!==($item['skr_code']??null)||($original['sphere']??null)!==($item['sphere']??null))throw new DomainException('Teilabgang und Vereinsbereich stimmen nicht überein.');
            AssetDisposal::check($original,$p);
        }else{
            if(array_key_exists('partial_plan',$item))throw new DomainException('Teilabgangsnachweis ohne Teilabgang.');
            AssetDisposal::check($plan,$p);
        }
        return $p;
    }
    public static function annual(array $item,array $plan,int $year):array
    {
        $proof=self::proof($item,$plan);$start=TaxCatalog::documentYear($plan['acquired_on']);
        if($start>$year||$proof&&TaxCatalog::documentYear($proof['date'])>$year)throw new DomainException('Anschaffung und Abgang müssen im nachgewiesenen Zeitraum liegen.');
        if(($proof['kind']??null)==='partial_scrap')return array_intersect_key(AssetPartialDisposal::preview($item['partial_plan'],$proof,$year)['book'],array_flip(self::fields($item)));
        $s=AssetDisposal::schedule($plan,$year,$proof);$basis=$plan['basis_cents'];$before=$start<$year;
        $v=['cost_opening_cents'=>$before?$basis:0,'additions_cents'=>$before?0:$basis,'cost_closing_cents'=>$basis,
            'accumulated_opening_cents'=>$before?$basis-$s['opening_cents']:0,'depreciation_cents'=>$s['depreciation_cents'],
            'accumulated_closing_cents'=>($before?$basis-$s['opening_cents']:0)+$s['depreciation_cents'],
            'book_opening_cents'=>$before?$s['opening_cents']:0,'book_closing_cents'=>$s['closing_cents']];
        if(count(self::fields($item))===11)$v+=array_fill_keys(self::FIELDS,0);
        if($proof){
            if(TaxCatalog::documentYear($proof['date'])<$year)return array_fill_keys(self::fields($item),0);
            $v['disposals_cents']=$basis;$v['accumulated_disposals_cents']=$v['accumulated_closing_cents'];
            $v['book_disposals_cents']=$basis-$v['accumulated_closing_cents'];
            $v['cost_closing_cents']=$v['accumulated_closing_cents']=$v['book_closing_cents']=0;
        }
        return $v;
    }
    /** Capped monotone highest-average allocation; O(units²), not O(cents). */
    public static function capped(int $total,array $weights,array $caps):array
    {
        $out=BalanceAssetAllocation::cents(0,$weights);$active=$weights;ksort($active,SORT_STRING);
        if(array_diff_key($out,$caps)||array_diff_key($caps,$out)||$total<0||$total>BalanceCalculation::LIMIT)throw new DomainException('Begrenzte Nutzungsanteile prüfen.');
        foreach($caps as $cap)if(!is_int($cap)||$cap<0||$cap>BalanceCalculation::LIMIT)throw new DomainException('Begrenzte Nutzungsanteile prüfen.');
        if($total>array_sum($caps))throw new DomainException('Anteil übersteigt den verbleibenden Anlagenwert.');
        while($active){
            $sum=array_sum($active);$clamped=false;
            foreach($active as $u=>$w)if($caps[$u]*$sum<=$total*$w){$out[$u]=$caps[$u];$total-=$caps[$u];unset($active[$u]);$clamped=true;break;}
            if(!$clamped)break;
        }
        if(!$active)return $out;
        $sum=array_sum($active);$remaining=$total;
        foreach($active as $u=>$w){$out[$u]=intdiv($total*$w,$sum);$remaining-=$out[$u];}
        while($remaining-->0){$winner=null;foreach($active as $u=>$w)if($out[$u]<$caps[$u]&&($winner===null||$w*($out[$winner]+1)>$active[$winner]*($out[$u]+1)))$winner=$u;if($winner===null)throw new DomainException('Restcent nicht zuordenbar.');++$out[$winner];}
        return $out;
    }
    /** Split the event's written-off depreciation and book value within original ownership shares.
     * Remaining depreciation then advances monotonically from that fixed event, preserving every prior year. */
    public static function partial(array $item,array $weights,int $year,?array $period=null):array
    {
        if($period===null)BalanceAssetAllocation::checkYear($item,$year);
        else{
            $period=BalancePeriod::check($period,$year);$expected=AssetPartialDisposal::period($item['partial_plan'],$item['disposal'],$period);
            foreach(self::fields($item) as $f)if(($item[$f]??null)!==$expected[$f])throw new DomainException('Teilabgang und tatsächlicher Zeitraum stimmen nicht überein.');
        }
        $p=$item['partial_plan'];$proof=$item['disposal'];$eventYear=TaxCatalog::documentYear($proof['date']);
        $event=AssetPartialDisposal::preview($p,$proof,$eventYear)['book'];
        $atEvent=AssetBooks::schedule($p,$eventYear,(int)substr($proof['date'],5,2));
        $cost=BalanceAssetAllocation::cents($p['basis_cents'],$weights);$dep=BalanceAssetAllocation::cents($p['basis_cents']-$atEvent['closing_cents'],$weights);
        $book=[];foreach($cost as $u=>$n)$book[$u]=$n-$dep[$u];
        $lostDep=self::capped($event['accumulated_disposals_cents'],$weights,$dep);$lostBook=self::capped($event['book_disposals_cents'],$weights,$book);
        $remainingCost=[];$remainingDep=[];$capacity=[];
        foreach($cost as $u=>$n){$remainingCost[$u]=$n-$lostDep[$u]-$lostBook[$u];$remainingDep[$u]=$dep[$u]-$lostDep[$u];$capacity[$u]=$remainingCost[$u]-$remainingDep[$u];}
        $after=self::capped($item['accumulated_closing_cents']-array_sum($remainingDep),$weights,$capacity);
        $old=$period!==null?$proof['date']<$period['start']:$eventYear<$year;$same=!$old;
        $acquired=$period!==null?$p['acquired_on']>=$period['start']:TaxCatalog::documentYear($p['acquired_on'])===$year;
        $before=$old?self::capped($item['accumulated_opening_cents']-array_sum($remainingDep),$weights,$capacity):BalanceAssetAllocation::cents($item['accumulated_opening_cents'],$weights);
        $out=[];
        foreach($cost as $u=>$n){
            $co=$acquired?0:($old?$remainingCost[$u]:$n);$ao=$old?$remainingDep[$u]+$before[$u]:$before[$u];$ac=$remainingDep[$u]+$after[$u];$dc=$same?$lostDep[$u]+$lostBook[$u]:0;$dd=$same?$lostDep[$u]:0;
            $out[$u]=['cost_opening_cents'=>$co,'additions_cents'=>$acquired?$n:0,'cost_closing_cents'=>$remainingCost[$u],
                'accumulated_opening_cents'=>$ao,'depreciation_cents'=>$ac-$ao+$dd,'accumulated_closing_cents'=>$ac,
                'book_opening_cents'=>$co-$ao,'book_closing_cents'=>$remainingCost[$u]-$ac,'disposals_cents'=>$dc,'accumulated_disposals_cents'=>$dd,'book_disposals_cents'=>$dc-$dd];
            if(min($out[$u])<0)throw new DomainException('Negativer Restwert nach Nutzungsaufteilung.');
        }
        foreach(self::fields($item) as $f)if(array_sum(array_column($out,$f))!==$item[$f])throw new DomainException('Teilabgang und ursprüngliche Centbeträge sind nicht abgestimmt.');
        return $out;
    }
    /** Per-unit natural account balances; gain/loss branch remains that of the original item. */
    public static function movements(array $item,array $chart,array $weights,array $parts,int $year):array
    {
        if(!isset($item['disposal']))return [];
        $out=[];
        foreach(BalanceDisposalAccounts::codes($item['disposal']) as $code=>$group){
            $code=(string)$code;$key=BalanceDisposalAccounts::choose($chart,$item,$code,$group);
            $amount=AssetSale::movement($item,$code,$year)??0;
            $split=in_array($code,['48550','68950','68960'],true)?array_column($parts,'book_disposals_cents'):null;
            if($split!==null){$split=array_combine(array_keys($parts),$split);if($amount===0)$split=array_fill_keys(array_keys($weights),0);}
            else $split=BalanceAssetAllocation::cents(abs($amount),$weights);
            foreach($split as $unit=>$n)$out[$unit][$key]=($amount<0?-1:1)*$n;
        }
        return $out;
    }
}
