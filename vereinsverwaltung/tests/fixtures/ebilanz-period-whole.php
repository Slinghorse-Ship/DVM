<?php declare(strict_types=1);
require __DIR__.'/../../src/bootstrap.php';
require __DIR__.'/balance-period-tax.php';
require_once __DIR__.'/../../src/Finance/Balance/BalancePeriodFilingValues.php';
use Kulturhus\Verein\{BalancePeriodFilingValues as F,BalancePeriodTaxValues as T,BalanceCalculation as C};
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});

/** Complete synthetic non-tax-privileged corporation, not a real association. */
function wholePeriodFixture(string $variant):array {
    if(!in_array($variant,['book','tax','book-successor','tax-successor'],true))throw new InvalidArgumentException('Unknown synthetic variant');
    $income=null;$prior=null;$priorValues=null;$previous=null;
    for($index=0;$index<2;$index++){
        $income=periodTaxIncomeFixture($income);$source=$income['scope']['source'];$period=$source['period'];
        $source['accounts']['othercapital']=['name'=>'Synthetic retained funds','group'=>'equity','skr_code'=>'29700'];
        $source['accounts']=C::chart($source['accounts']);
        $review=periodTaxReview($income);unset($review['income_sha256'],$review['off_balance_adjustments']);$review['capital_movements']=[];
        foreach(['linear'=>'1','software'=>'2','partial'=>'3'] as $id=>$sphere){
            $source['asset_schedule']['items'][$id]['plan']['sphere']=$sphere;
            if($id==='partial')$source['asset_schedule']['items'][$id]['disposal']['plan_sha256']=\Kulturhus\Verein\AssetPartialDisposal::hash($source['asset_schedule']['items'][$id]['plan']);
            $entryIds=[md5('depreciation-'.$id.$period['end']),md5('book-disposal-'.$id.'2026-01-15')];
            foreach($source['entries'] as &$entry)if(in_array($entry['id'],$entryIds,true))foreach($entry['lines'] as &$l)if($l['sphere']!=='')$l['sphere']=$sphere;
            unset($entry,$l);
        }
        $line=fn($a,$d,$c,$s='')=>['account'=>$a,'debit_cents'=>$d,'credit_cents'=>$c,'sphere'=>$s];
        if(!$prior){
            foreach(['contribution'=>[$line('cash',1000,0),$line('equity',0,1000)],
                     'withdrawal'=>[$line('equity',400,0),$line('cash',0,400)],
                     'reclassification'=>[$line('equity',200,0),$line('othercapital',0,200)]] as $kind=>$lines){
                $id=md5('synthetic-'.$kind);$source['entries'][]=['id'=>$id,'date'=>$period['start'],'description'=>'Synthetic '.$kind,'lines'=>$lines];
                foreach($lines as $ordinal=>$l)if($source['accounts'][$l['account']]['group']==='equity')$review['capital_movements']['journal:'.$id.':'.$ordinal]=['kind'=>$kind,'reason'=>'Synthetic capital evidence'];
            }
            if(str_starts_with($variant,'tax')){
                $review['current_adjustments']=[['id'=>'synthetic-tax-capital','reason'=>'Synthetic reviewed capital difference','lines'=>[$line('cash',300,0),$line('equity',0,300)]]];
                $review['capital_movements']['tax:synthetic-tax-capital:1']=['kind'=>'contribution','reason'=>'Synthetic tax capital evidence'];
            }
        }else{$source['opening']=C::carryForward($prior['calculation'],'equity');$source['previous_sha256']=$prior['closed_sha256'];}
        $source['closed_sha256']=hash('sha256',json_encode([$period,$source['entries'],$source['opening'],$source['asset_schedule']]));
        $source['calculation']=C::build(2026,$source['accounts'],$source['opening'],$source['entries'],$period);
        $review['journal_sha256']=$source['closed_sha256'];
        if(str_starts_with($variant,'tax'))foreach($source['asset_schedule']['items'] as $id=>$item)if($item['plan']['sphere']==='4')
            $review['asset_tax_plans'][$id]=['months'=>120,'depreciation_account'=>'dep','reason'=>'Synthetic reviewed taxable asset life','confirmed'=>true];
        if($prior){$carry=T::carryForward($prior,$priorValues,$source);$review['opening_adjustments']=$carry['entry']?[$carry['entry']]:[];}
        $value=F::whole($source,$review,$previous);
        if(!str_ends_with($variant,'successor'))return $value;
        $prior=$source;$priorValues=T::build($source,$review,$priorValues);$previous=$value;
    }
    return $value;
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__)echo json_encode(wholePeriodFixture($argv[1]??'book'),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
