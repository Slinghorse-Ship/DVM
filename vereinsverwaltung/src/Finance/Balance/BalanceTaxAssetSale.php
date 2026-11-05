<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
require_once __DIR__.'/BalanceDisposalAccounts.php';

/** Reclassify one evidenced full sale only in the tax ledger, never the issued invoice. */
final class BalanceTaxAssetSale
{
    public static function prepare(array $chart,array $item,array $plan,int $taxMonths,int $year,string $reason):array
    {
        $proof=$item['disposal'];
        if((int)substr($proof['date'],0,4)<$year)return ['proof'=>null,'entry'=>null,'managed'=>[]];
        $book=AssetSale::amounts($plan,$proof['date'],$proof['gross_cents'],$proof['rate'],$proof['vat_kind']??'domestic');
        $tax=AssetSale::amounts(array_replace($plan,['months'=>$taxMonths]),$proof['date'],$proof['gross_cents'],$proof['rate'],$proof['vat_kind']??'domestic');
        $accounts=[];
        foreach([$book,$tax] as $values)foreach(['sale_code','book_code'] as $kind){
            $code=$values[$kind];if(isset($accounts[$code]))continue;
            $accounts[$code]=BalanceDisposalAccounts::choose($chart,$item,$code,$values['group']);
        }
        // Signed debits; reverse old carrying value/proceeds and enter the tax classification.
        $net=[$item['account']=>$book['book_cents']-$tax['book_cents']];
        foreach([[$book['book_code'],-$book['book_cents']],[$tax['book_code'],$tax['book_cents']],
            [$book['sale_code'],$book['net_cents']],[$tax['sale_code'],-$tax['net_cents']]] as [$code,$amount]){
            $key=$accounts[$code];$net[$key]=($net[$key]??0)+$amount;
        }
        $lines=[];foreach($net as $key=>$amount)if($amount)$lines[]=['account'=>$key,'debit_cents'=>max(0,$amount),'credit_cents'=>max(0,-$amount),'sphere'=>$key===$item['account']?'':$item['sphere']];
        $entry=$lines?['id'=>'asset-sale-'.substr(hash('sha256',$item['item_id']),0,24),'reason'=>'Steuerlicher Anlagenverkauf: '.$reason,'lines'=>BalanceCalculation::lines($lines,$chart)]:null;
        return ['proof'=>['book'=>$book,'tax'=>$tax,'accounts'=>$accounts],'entry'=>$entry,'managed'=>array_values($accounts)];
    }
}
