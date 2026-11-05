<?php declare(strict_types=1);
require __DIR__.'/../../src/bootstrap.php';require_once __DIR__.'/../../src/Finance/Balance/BalanceClubTaxReport.php';require_once __DIR__.'/../../src/Finance/Balance/BalanceTaxValues.php';require __DIR__.'/balance-club-tax.php';
use Kulturhus\Verein\{BalanceClubTaxReport as R,BalanceTaxValues,BalanceCalculation as C,BalanceSegmentCalculation as S};
$variant=$argv[1]??'';$state=clubTaxFixture(in_array($variant,['assets','tax-assets','tax-assets-all'],true));
if(str_starts_with($variant,'shared-assets')){require __DIR__.'/balance-shared-assets.php';$method=str_contains($variant,'gwg')?'gwg_immediate':(str_contains($variant,'degressive')?'degressive':'linear_monthly');$state=sharedAssetFixture(str_contains($variant,'funding'),$method);}
if(str_starts_with($variant,'shared-disposal-')){require __DIR__.'/balance-shared-disposals.php';$state=sharedDisposalFixture(substr($variant,16));}
$carryCases=['carry-offset-zero'=>[10000,10000,false],'carry-offset-gain'=>[20000,10000,false],'carry-offset-loss'=>[10000,20000,false],'carry-offset-cross'=>[10000,20000,true]];
if(str_starts_with($variant,'transfer-')){require __DIR__.'/balance-internal-transfer.php';$state=internalTransferFixture(str_contains($variant,'taxable'),str_contains($variant,'reverse'));}
if(isset($carryCases[$variant])){require __DIR__.'/balance-capital-carry.php';$state=capitalCarryFixture(...$carryCases[$variant]);}
if(in_array($variant,['club-capital','club-capital-carry'],true)){
    require __DIR__.'/balance-club-capital.php';[$state,$capitalDecisions]=clubCapitalFixture($state);
}
if($variant==='fully'){
    $source=$state['journal'];$source['accounts']['fixed_assets']['skr_code']='06300';$item=str_repeat('b',32);
    $source['asset_schedule']['items'][$item]=['item_id'=>$item,'invoice_id'=>str_repeat('c',32),'receipt_number'=>'Synthetic old asset','account'=>'fixed_assets','skr_code'=>'06300','sphere'=>'4','acquired_on'=>'2019-01-01','months'=>60,'residual_cents'=>0,
        'cost_opening_cents'=>5000,'additions_cents'=>0,'cost_closing_cents'=>5000,'accumulated_opening_cents'=>5000,'depreciation_cents'=>0,'accumulated_closing_cents'=>5000,'book_opening_cents'=>0,'book_closing_cents'=>0,
        'expected_depreciation_cents'=>0,'acquisition_hash'=>hash('sha256','synthetic historical asset'),'depreciation_hash'=>null];
    foreach(R::FIELDS as $f)$source['asset_schedule']['totals'][$f]=$source['asset_schedule']['items'][$item][$f];
    $source['calculation']=C::build(2025,$source['accounts'],$source['opening'],$source['entries']);$state=clubTaxState($source,$state['allocation']['units'],$state['allocation']['allocations']);
}
if(in_array($variant,['reversal','loss'],true)){
    $source=$state['journal'];$alloc=$state['allocation']['allocations'];$key=str_repeat('d',32);
    $entry=$source['entries'][5];$entry['id']=$key;
    if($variant==='reversal'){foreach($entry['lines'] as &$line)[$line['debit_cents'],$line['credit_cents']]=[$line['credit_cents'],$line['debit_cents']];unset($line);}
    else{foreach($entry['lines'] as &$line){$line['debit_cents']=$line['debit_cents']?10000:0;$line['credit_cents']=$line['credit_cents']?10000:0;}unset($line);}
    $source['entries'][]=$entry;
    foreach($entry['lines'] as $i=>$line)$alloc[$key.':'.$i]=['parts'=>$variant==='reversal'?['food'=>334,'shop'=>669]:['food'=>$line['debit_cents']+$line['credit_cents']],'reason'=>'Synthetic reversal or loss'];
    $source['calculation']=C::build(2025,$source['accounts'],$source['opening'],$source['entries']);$state=clubTaxState($source,$state['allocation']['units'],$alloc);
}
$whole=($argv[2]??'')==='whole';$combined=($argv[2]??'')==='combined';
if($whole||$combined)$state['journal']['previous_sha256']??=null;
if($combined)require_once __DIR__.'/../../src/Finance/Balance/BalanceCombinedClubValues.php';
if($whole&&$variant==='capital'){
    $source=$state['journal'];$source['accounts']['equity_other']=['name'=>'Synthetic capital reserve','group'=>'equity','skr_code'=>''];$decisions=[];
    foreach([['cash','equity',1000,'contribution'],['equity','cash',400,'withdrawal'],['equity','equity_other',100,'reclassification']] as $i=>[$debit,$credit,$cents,$kind]){
        $id=hash('md5','synthetic capital '.$i);$lines=[['account'=>$debit,'debit_cents'=>$cents,'credit_cents'=>0,'sphere'=>''],['account'=>$credit,'debit_cents'=>0,'credit_cents'=>$cents,'sphere'=>'']];
        $source['entries'][]=['id'=>$id,'date'=>'2025-05-01','description'=>'Synthetic reviewed capital '.$kind,'lines'=>$lines];
        foreach($lines as $j=>$line)if($source['accounts'][$line['account']]['group']==='equity')$decisions['journal:'.$id.':'.$j]=['kind'=>$kind,'reason'=>'Synthetic capital evidence'];
    }
    $source['accounts']=C::chart($source['accounts']);$source['calculation']=C::build(2025,$source['accounts'],$source['opening'],$source['entries']);$state['journal']=$source;
}
$r=$whole?\Kulturhus\Verein\BalanceWholeClubReport::fromReport($state['journal']):R::fromState($state);
if($variant==='carry'){
    if($combined){$pr=['year'=>$r['year'],'journal_sha256'=>$r['closed_sha256'],'complete'=>true,'opening_adjustments'=>[],'current_adjustments'=>[]];$priorCombined=\Kulturhus\Verein\BalanceCombinedClubValues::build($state,['year'=>$r['year'],'journal_sha256'=>$state['journal']['closed_sha256'],'complete'=>true,'taxable_review'=>$pr]);}
    $next=$state['journal'];$next['year']=2026;$next['previous_sha256']=$next['closed_sha256'];$next['closed_sha256']=hash('sha256','synthetic next club year');$next['opening']=C::carryForward($next['calculation'],'equity');$next['entries']=[];$next['asset_schedule']['year']=2026;$next['calculation']=C::build(2026,$next['accounts'],$next['opening'],[]);
    if($whole)$r=\Kulturhus\Verein\BalanceWholeClubReport::fromReport($next);
    else{$carry=S::carry($state['journal'],$state['allocation'],$next);$state=clubTaxState($next,$carry['units'],$carry['allocations']);$r=R::fromState($state,$r);}
}
$review=['year'=>$r['year'],'journal_sha256'=>$r['closed_sha256'],'complete'=>true,'opening_adjustments'=>[],'current_adjustments'=>[]];
if(isset($decisions))$review['capital_movements']=$decisions;
if($whole&&isset($capitalDecisions))$review['capital_movements']=$capitalDecisions;
if(in_array($variant,['tax-assets','shared-assets-tax'],true)){
    $review['asset_tax_plans']=[array_key_first($r['asset_schedule']['items'])=>['months'=>120,'depreciation_account'=>'depreciation','reason'=>'Synthetic reviewed tax useful life','confirmed'=>true]];
}
if(str_starts_with($variant,'shared-disposal-')&&str_contains($variant,'-tax'))foreach($r['asset_schedule']['items'] as $key=>$item)if($item['sphere']==='4')$review['asset_tax_plans'][$key]=['months'=>120,'depreciation_account'=>$item['skr_code']==='01350'?'depreciation_software':'depreciation','reason'=>'Synthetic separate shared-disposal tax life','confirmed'=>true];
if($variant==='tax-assets-all')foreach($r['asset_schedule']['items'] as $id=>$item)$review['asset_tax_plans'][$id]=['months'=>120,'depreciation_account'=>'depreciation','reason'=>'Synthetic reviewed tax useful life in every internal sphere','confirmed'=>true];
$v=$combined?\Kulturhus\Verein\BalanceCombinedClubValues::build($state,['year'=>$r['year'],'journal_sha256'=>$state['journal']['closed_sha256'],'complete'=>true,'taxable_review'=>$review],$priorCombined??null):BalanceTaxValues::build($r,$review);
if((str_starts_with($variant,'shared-assets')||str_starts_with($variant,'shared-disposal-'))&&str_ends_with($variant,'-carry')){
    $state=sharedAssetNext($state);$nr=$whole?\Kulturhus\Verein\BalanceWholeClubReport::fromReport($state['journal']):R::fromState($state,$r);
    $inheritedTax=isset($review['asset_tax_plans'])?BalanceTaxValues::carryForward($r,$review,$nr):null;
    $review=['year'=>2026,'journal_sha256'=>$nr['closed_sha256'],'complete'=>true,'opening_adjustments'=>[],'current_adjustments'=>[]];
    if($inheritedTax){$review['asset_tax_plans']=$inheritedTax['asset_tax_plans'];if($inheritedTax['entry'])$review['opening_adjustments']=[$inheritedTax['entry']];}
    $v=$combined?\Kulturhus\Verein\BalanceCombinedClubValues::build($state,['year'=>2026,'journal_sha256'=>$state['journal']['closed_sha256'],'complete'=>true,'taxable_review'=>$review],$v):BalanceTaxValues::build($nr,$review);
}
if(isset($carryCases[$variant])){
    $state=capitalCarryNext($state);
    $nr=$whole?\Kulturhus\Verein\BalanceWholeClubReport::fromReport($state['journal']):R::fromState($state,$r);
    $review=['year'=>$nr['year'],'journal_sha256'=>$nr['closed_sha256'],'complete'=>true,'opening_adjustments'=>[],'current_adjustments'=>[]];
    $v=$combined?\Kulturhus\Verein\BalanceCombinedClubValues::build($state,['year'=>$nr['year'],'journal_sha256'=>$state['journal']['closed_sha256'],'complete'=>true,'taxable_review'=>$review],$v):BalanceTaxValues::build($nr,$review);
}
if($variant==='club-capital-carry'){
    $next=$state['journal'];$next['year']=2026;$next['previous_sha256']=$next['closed_sha256'];$next['closed_sha256']=hash('sha256','synthetic capital next year');$next['opening']=C::carryForward($next['calculation'],'carry_gain');$next['entries']=[];$next['asset_schedule']['year']=2026;$next['calculation']=C::build(2026,$next['accounts'],$next['opening'],[]);
    if($whole)$nr=\Kulturhus\Verein\BalanceWholeClubReport::fromReport($next);
    else{$carry=S::carry($state['journal'],$state['allocation'],$next);$state=clubTaxState($next,$carry['units'],$carry['allocations']);$nr=R::fromState($state,$r);}
    $review=['year'=>2026,'journal_sha256'=>$nr['closed_sha256'],'complete'=>true,'opening_adjustments'=>[],'current_adjustments'=>[]];
    $v=$combined?\Kulturhus\Verein\BalanceCombinedClubValues::build($state,['year'=>2026,'journal_sha256'=>$next['closed_sha256'],'complete'=>true,'taxable_review'=>$review],$v):BalanceTaxValues::build($nr,$review);
}
echo json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
