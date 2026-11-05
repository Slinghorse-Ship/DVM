<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
require_once __DIR__.'/BalanceCostAssetSources.php';

/** Derive tax differences only from the already evidenced cost valuation, never a new useful life. */
final class BalanceCostAssetTax
{
    public static function periodChanges(array $values):bool
    {
        foreach($values['asset_tax']['items']??[] as $item){
            $cost=$item['cost']??null;if($cost===null)continue;
            if($cost['opening_difference_cents']||$cost['closing_difference_cents'])return true;
            foreach($cost['events'] as $event)if($event['asset_difference_cents'])return true;
        }
        return false;
    }
    private static function pair(string $fixed,string $other,int $net,string $sphere):array
    {return [['account'=>$fixed,'debit_cents'=>max(0,$net),'credit_cents'=>max(0,-$net),'sphere'=>''],['account'=>$other,'debit_cents'=>max(0,-$net),'credit_cents'=>max(0,$net),'sphere'=>$sphere]];}
    public static function item(array $item,array $chart,array $period):array
    {
        $plan=$item['cost_plan'];$valuation=$item['valuation'];$source=$valuation['source']??null;
        if(!is_array($source)||AssetValuation::fromBookPlan($plan)!==$source['plan']||$source['period']!==BalancePeriod::check($period)
            ||AssetValuation::calculate($source['plan'],$period,$source['events'],$source['previous'])!==$valuation)
            throw new DomainException('Unveränderten ursprünglichen Buch-/Steuerbewertungsnachweis verwenden.');
        foreach(AssetValuation::FIELDS as $field)if(($item[$field]??null)!==$valuation['book'][$field])throw new DomainException('Buch-Anlagenwerte stimmen nicht mit ihrem Bewertungsnachweis überein.');
        $fixed=$item['account'];$type=AssetTypes::accounts()[$plan['account']]??null;
        if(($type['kind']??null)!==$plan['valuation_kind']||($chart[$fixed]['group']??null)!=='fixed_assets'||$chart[$fixed]['skr_code']!==$plan['account'])throw new DomainException('Geprüftes Kostenanlagenkonto und Originalplan stimmen nicht überein.');
        $current=[];$managed=[$fixed=>true];$difference=[];
        foreach($valuation['movements'] as $index=>$movement){
            $event=$source['events'][$index];if($event['id']!==$movement['event_id'])throw new DomainException('Bewertungsereignis wurde verändert.');
            $accountType=BalanceCostAssetSources::codes($plan['valuation_kind'],$event['kind'],$event['permanent']??true,$plan['financial_profile']??null,$event['counter_code']??null,$plan['account']);$key=$item['valuation_accounts'][$accountType['code']]??null;
            if(!is_string($key)||($chart[$key]['group']??null)!==$accountType['group']||$chart[$key]['skr_code']!==$accountType['code'])throw new DomainException('Originales steuerliches Bewertungsgegenkonto fehlt.');
            $managed[$key]=true;$book=$movement['book'];$tax=$movement['tax'];
            $net=$tax['writeup_cents']-$book['writeup_cents']-($tax['impairment_cents']-$book['impairment_cents'])-($tax['book_disposals_cents']-$book['book_disposals_cents']);
            $difference[$event['id']]=['account'=>$key,'asset_difference_cents'=>$net];
            if($net)$current[]=['id'=>'cost-tax-'.substr(hash('sha256',$item['item_id'].$event['id']),0,24),
                'reason'=>'Steuerliche Anlagenbewertung: '.mb_substr($event['reason'],0,1300),'lines'=>self::pair($fixed,$key,$net,$plan['sphere'])];
        }
        $opening=$valuation['tax']['book_opening_cents']-$valuation['book']['book_opening_cents'];
        $closing=$valuation['tax']['book_closing_cents']-$valuation['book']['book_closing_cents'];
        if($opening+array_sum(array_column($difference,'asset_difference_cents'))!==$closing)throw new DomainException('Steuerlicher Kostenbewertungsvortrag ist nicht abgestimmt.');
        $taxItem=array_replace($item,$valuation['tax'],['valuation_side'=>'tax']);
        return ['tax_item'=>$taxItem,'generated_current'=>$current,'managed_accounts'=>array_keys($managed),'opening_difference_cents'=>$opening,
            'proof'=>['kind'=>'cost','book_item_sha256'=>AssetValuation::hash($item),'valuation_sha256'=>$valuation['sha256'],
                'opening_difference_cents'=>$opening,'closing_difference_cents'=>$closing,'events'=>$difference]];
    }
    public static function annual(array $report):array
    {
        $schedule=$report['asset_schedule'];$tax=$schedule;$proof=[];$current=[];$opening=[];$desired=[];$managed=[];$equity=$report['result_account']??null;
        foreach($schedule['items'] as $id=>$item){
            if(($item['method']??null)!=='cost')continue;$derived=self::item($item,$report['accounts'],BalancePeriod::calendar($report['year']));
            $tax['items'][$id]=$derived['tax_item'];$proof[$id]=$derived['proof'];array_push($current,...$derived['generated_current']);
            foreach($derived['managed_accounts'] as $key)$managed[$key]=true;
            $amount=$derived['opening_difference_cents'];$desired[$item['account']]=($desired[$item['account']]??0)+$amount;
            if($amount){
                if(!is_string($equity)||($report['accounts'][$equity]['group']??null)!=='equity')throw new DomainException('Ergebnisvortrag für den ursprünglichen Kostenbewertungsunterschied fehlt.');
                $opening[]=['id'=>'cost-open-'.substr(hash('sha256',$id),0,24),'reason'=>'Steuerlicher Bewertungsvortrag: '.mb_substr($item['cost_plan']['reason'],0,1300),'lines'=>self::pair($item['account'],$equity,$amount,'')];
            }
            foreach($tax['totals'] as $field=>$_)$tax['totals'][$field]+=($derived['tax_item'][$field]??0)-($item[$field]??0);
        }
        return ['tax_schedule'=>$tax,'proof'=>$proof,'current'=>$current,'opening'=>$opening,'managed'=>$managed,'desired_opening'=>$desired];
    }
}
