<?php declare(strict_types=1);
use Kulturhus\Verein\{BalanceAssetAllocation as ShareA,BalanceSegmentCalculation as ShareS,BalanceCalculation as ShareC,AssetBooks as ShareBooks};
function sharedAssetYear(array $item,int $year):array
{
    if(isset($item['disposal'])){
        $plan=array_replace($item,['account'=>$item['skr_code'],'method'=>$item['method']??'linear_monthly']);
        $v=\Kulturhus\Verein\BalanceAssetAllocationDisposal::annual($item,$plan,$year);
        return array_replace($item,$v,['expected_depreciation_cents'=>$v['depreciation_cents'],'depreciation_hash'=>$v['depreciation_cents']?hash('sha256','synthetic shared-disposal '.$year):null]);
    }
    $cost=$item['cost_closing_cents'];$start=(int)substr($item['acquired_on'],0,4);
    $plan=array_replace($item,['basis_cents'=>$cost,'account'=>$item['skr_code']]);$s=ShareBooks::schedule($plan,$year);
    return array_replace($item,['cost_opening_cents'=>$start<$year?$cost:0,'additions_cents'=>$start===$year?$cost:0,
        'accumulated_opening_cents'=>$start<$year?$cost-$s['opening_cents']:0,'depreciation_cents'=>$s['depreciation_cents'],
        'accumulated_closing_cents'=>$cost-$s['closing_cents'],'book_opening_cents'=>$start<$year?$s['opening_cents']:0,
        'book_closing_cents'=>$s['closing_cents'],'expected_depreciation_cents'=>$s['depreciation_cents'],
        'depreciation_hash'=>$s['depreciation_cents']?hash('sha256','shared-'.$item['item_id'].'-'.$year):null]);
}
function sharedAssetFixture(bool $funding=false,string $method='linear_monthly'):array
{
    $state=clubTaxFixture(true);$r=$state['journal'];$a=$state['allocation'];$shares=[];
    $r['asset_schedule']['totals']=array_fill_keys(ShareA::FIELDS,0);
    foreach($r['asset_schedule']['items'] as $id=>&$item){
        $weights=$item['sphere']==='4'?['food'=>1,'shop'=>2]:['culture'=>1];
        if($item['sphere']==='4'){
            $item['cost_closing_cents']++;
            if($method==='gwg_immediate'){
                $item['method']=$method;$item['account']='fixed_assets_gwg';$item['skr_code']='06700';$item['months']=1;
                $item['gwg']=['net_cents'=>12001,'vat_cents'=>0,'deductible_cents'=>0,'independent_movable'=>true,'no_pool'=>true];
                $r['accounts']['fixed_assets_gwg']=['name'=>'Synthetic GWG','group'=>'fixed_assets','skr_code'=>'06700'];
                $r['accounts']['depreciation_gwg']=['name'=>'Synthetic immediate depreciation','group'=>'expense','skr_code'=>'62600'];
            }elseif($method==='degressive'){
                $item['method']=$method;$item['acquired_on']='2025-07-01';
                $item['degressive']=['rate_basis_points'=>3000,'movable'=>true,'same_book_tax'=>true,'switch_to_linear'=>true];
            }elseif($method!=='linear_monthly')throw new RuntimeException('Unknown synthetic method');
        }
        $item=sharedAssetYear($item,2025);foreach(ShareA::FIELDS as $f)$r['asset_schedule']['totals'][$f]+=$item[$f];
        $parts=ShareA::item($item,$weights);
        $unit=$item['sphere']==='4'?'food':'culture';
        foreach(['purchase-'.$unit=>'additions_cents','depreciation-'.$unit=>'depreciation_cents'] as $name=>$field){
            $voucher=substr(hash('sha256',$name),0,32);$amount=$item[$field];
            foreach($r['entries'] as &$entry)if($entry['id']===$voucher){$entry['date']=$field==='additions_cents'?$item['acquired_on']:'2025-12-31';foreach($entry['lines'] as $ordinal=>&$line){
                $line['debit_cents']=$line['debit_cents']?$amount:0;$line['credit_cents']=$line['credit_cents']?$amount:0;
                if($line['account']==='fixed_assets')$line['account']=$item['account'];
                if($line['account']==='depreciation'&&$item['skr_code']==='06700')$line['account']='depreciation_gwg';
                $split=[];foreach($parts as $u=>$values)if($values[$field])$split[$u]=$values[$field];
                $a['allocations'][$voucher.':'.$ordinal]=['parts'=>$split,'reason'=>'Synthetic original item-specific allocation'];
            }}unset($entry,$line);
        }
        $shares[$id]=['item_sha256'=>ShareS::hash($item),'weights'=>$weights,'measure'=>'hours','reason'=>'Synthetic usage record <not-html>'];
    }unset($item);
    $r['accounts']=ShareC::chart($r['accounts']);$r['calculation']=ShareC::build(2025,$r['accounts'],$r['opening'],$r['entries']);$r['closed_sha256']=hash('sha256','synthetic shared-assets original '.$method);
    $transfers=[];
    if($funding){
        $voucher=str_repeat('a',32);
        $a['allocations'][$voucher.':1']=['parts'=>['club'=>1003],'reason'=>'Synthetic funding of shared operating costs'];
        $transfers[$voucher]=['account'=>'equity','parts'=>ShareS::transferParts($r,$a['units'],$a['allocations'],$voucher),'reason'=>'Synthetic final internal funds allocation'];
    }
    return clubTaxState($r,$a['units'],$a['allocations'],2,[],$transfers,$shares);
}
function sharedAssetNext(array $state):array
{
    $r=$state['journal'];$next=$r;$next['year']=2026;$next['previous_sha256']=$r['closed_sha256'];$next['closed_sha256']=hash('sha256','synthetic shared-assets next');
    $next['opening']=ShareC::carryForward($r['calculation'],'equity');$next['entries']=[];$next['asset_schedule']['year']=2026;$fields=array_keys($r['asset_schedule']['totals']);$next['asset_schedule']['totals']=array_fill_keys($fields,0);$running=[];
    foreach($next['asset_schedule']['items'] as $id=>&$item){
        $item=sharedAssetYear($item,2026);
        foreach($fields as $f)$next['asset_schedule']['totals'][$f]+=$item[$f];
        $voucher=substr(hash('sha256','next-'.$id),0,32);$parts=ShareA::item($item,$state['allocation']['asset_shares'][$id]['weights'],2026);$amount=$item['depreciation_cents'];
        if(!$amount)continue;
        $dep=$item['skr_code']==='06700'?'depreciation_gwg':'depreciation';
        $next['entries'][]=['id'=>$voucher,'date'=>'2026-12-31','description'=>'Synthetic shared annual depreciation','lines'=>[['account'=>$dep,'debit_cents'=>$amount,'credit_cents'=>0,'sphere'=>$item['sphere']],['account'=>$item['account'],'debit_cents'=>0,'credit_cents'=>$amount,'sphere'=>'']]];
        $weights=[];foreach($parts as $u=>$v)if($v['depreciation_cents'])$weights[$u]=$v['depreciation_cents'];
        foreach([0,1] as $ordinal)$running[$voucher.':'.$ordinal]=['parts'=>$weights,'reason'=>'Synthetic retained annual shares'];
    }unset($item);
    $next['calculation']=ShareC::build(2026,$next['accounts'],$next['opening'],$next['entries']);
    $carry=ShareS::carry($r,$state['allocation'],$next);
    return clubTaxState($next,$carry['units'],$carry['allocations']+$running,2,$carry['capital_carry']??[],[],$carry['asset_shares']);
}
