<?php declare(strict_types=1);
require __DIR__.'/../../src/bootstrap.php';
require_once __DIR__.'/../../src/Finance/Balance/BalanceSubsidiaryClubValues.php';
require_once __DIR__.'/balance-club-tax-reconciliation.php';
use Kulturhus\Verein\{BalanceClubIncomeReconciliation as I,BalanceClubTaxReconciliation as T,BalanceSubsidiaryClubValues as V};
$variant=$argv[1]??'';$asset=in_array($variant,['assets','tax-assets'],true);$state=taxIncomeFixture($variant==='settled'?334:100,$asset);
if(str_starts_with($variant,'shared-disposal-')){
    require_once __DIR__.'/balance-shared-disposals.php';$extra=sharedDisposalFixture(substr($variant,16));
    [$journal,$units,$allocations,$instructions]=clearingFixture(100);
    $journal['accounts']=$extra['journal']['accounts'];$journal['asset_schedule']=$extra['journal']['asset_schedule'];
    foreach($extra['journal']['entries'] as $entry){
        if(in_array($entry['id'],array_column($journal['entries'],'id'),true))continue;
        $journal['entries'][]=$entry;foreach($entry['lines'] as $ordinal=>$_)$allocations[$entry['id'].':'.$ordinal]=$extra['allocation']['allocations'][$entry['id'].':'.$ordinal];
    }
    $journal['calculation']=\Kulturhus\Verein\BalanceCalculation::build(2025,$journal['accounts'],$journal['opening'],$journal['entries']);
    $allocation=\Kulturhus\Verein\BalanceSegmentCalculation::build($journal,$units,$allocations,[],[],$extra['allocation']['asset_shares']);
    $proof=\Kulturhus\Verein\BalanceInternalClearing::build('synthetic',$journal,$units,$allocations,$instructions);
    $state=incomeState($journal,$allocation,$proof);
}
$i=I::fromState($state);$r=taxIncomeReview($i);
if(in_array($variant,['valuation','carry'],true))$r['current_adjustments'][]=['id'=>'receivable-value','reason'=>'Synthetic evidenced valuation','lines'=>[
    ['account'=>'receivables','debit_cents'=>501,'credit_cents'=>0,'sphere'=>''],['account'=>'expense','debit_cents'=>0,'credit_cents'=>501,'sphere'=>'4']]];
if($variant==='tax-assets')foreach($i['scope']['source']['asset_schedule']['items'] as $key=>$item)if($item['sphere']==='4')$r['asset_tax_plans'][$key]=['months'=>120,'depreciation_account'=>'depreciation','reason'=>'Synthetic confirmed tax life','confirmed'=>true];
if(str_starts_with($variant,'shared-disposal-')&&str_ends_with($variant,'-tax'))foreach($i['scope']['source']['asset_schedule']['items'] as $key=>$item)if($item['sphere']==='4')$r['asset_tax_plans'][$key]=['months'=>120,'depreciation_account'=>$item['skr_code']==='01350'?'depreciation_software':'depreciation','reason'=>'Synthetic confirmed shared disposal tax life','confirmed'=>true];
if($variant==='off-balance'){
    $base=T::build($i,$r);
    foreach($base['off_balance']['sources'] as $key=>$source){
        if($source['cents']<=0)continue;
        $r['off_balance_adjustments'][]=['id'=>'source-'.count($r['off_balance_adjustments']),'kind'=>$source['group']==='expense'?'non_deductible_expense':'tax_exempt_income',
            'sources'=>[$key=>min(57,$source['cents'])],'reason'=>'Synthetic <text> & proof','legal_basis'=>'Synthetic verified legal basis','confirmed'=>true];
    }
}
$v=T::build($i,$r);
if($variant==='carry'){$ni=I::fromState(taxIncomeNext($state),$i);$nr=taxIncomeReview($ni);$nr['opening_adjustments']=[T::carryForward($v,$ni)['entry']];$v=T::build($ni,$nr,$v);}
echo json_encode(V::build($v),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
