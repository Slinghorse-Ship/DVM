<?php declare(strict_types=1);
use Kulturhus\Verein\{BalanceAssetAllocation as DA,BalanceAssetAllocationDisposal as DD,BalanceDisposalAccounts as DC,AssetSale as DS,AssetBooks as DB,BalanceCalculation as DCalc,BalanceSegmentCalculation as DSeg};
require_once __DIR__.'/balance-shared-assets.php';

function sharedDisposalFixture(string $variant='scrap'):array
{
    $method=str_contains($variant,'gwg')?'gwg_immediate':(str_contains($variant,'degressive')?'degressive':'linear_monthly');
    $s=sharedAssetFixture(false,$method);$r=$s['journal'];$a=$s['allocation'];$shares=$a['asset_shares'];
    $id=array_key_first(array_filter($r['asset_schedule']['items'],fn($i)=>$i['sphere']==='4'));$item=$r['asset_schedule']['items'][$id];
    $kind=str_contains($variant,'partial')?'partial_scrap':(str_contains($variant,'sale')?'sale':(str_contains($variant,'software')?'software_end':'scrap'));
    $date='2025-09-17';$basis=$item['cost_closing_cents'];
    if($kind==='software_end'){
        $item['account']='fixed_software';$item['skr_code']='01350';
        $r['accounts']['fixed_software']=['name'=>'Synthetic shared software','group'=>'fixed_assets','skr_code'=>'01350'];
        $r['accounts']['depreciation_software']=['name'=>'Synthetic software depreciation','group'=>'expense','skr_code'=>'62000'];
    }
    $p=['kind'=>$kind,'date'=>$date,'reason'=>'Synthetic complete disposal <evidence>','document_id'=>str_repeat('d',32),'revision'=>1,'sha256'=>hash('sha256','synthetic-disposal-proof'),'complete'=>true];
    $plan=array_replace($item,['basis_cents'=>$basis,'account'=>$item['skr_code'],'method'=>$method]);
    if($kind==='sale'){
        $gross=str_contains($variant,'loss')?3571:14281;$rate=str_contains($variant,'exempt')?0:19;
        $v=DS::amounts($plan,$date,$gross,$rate);
        $p+=['sale_entry'=>hash('md5','synthetic-sale'),'sale_hash'=>hash('sha256','synthetic-sale-snapshot'),'gross_cents'=>$gross,'tax_cents'=>$v['tax_cents'],'rate'=>$rate,'vat_reason'=>$rate===0?'Synthetic reviewed exemption':''];
        $r['accounts']['sale_vat']=['name'=>'Synthetic VAT','group'=>'liabilities','skr_code'=>'38060'];
        $r['accounts']['vat_closing']=['name'=>'Synthetic annual VAT payable','group'=>'liabilities','skr_code'=>'38430'];
    }elseif($kind==='partial_scrap'){
        $original=['basis_cents'=>$basis,'residual_cents'=>$item['residual_cents'],'acquired_on'=>$item['acquired_on'],'months'=>$item['months'],'sphere'=>'4','account'=>$item['skr_code'],'reason'=>'Synthetic original component plan','method'=>'linear_monthly'];
        $p['schema']=1;$p['complete']=false;$p['no_proceeds']=true;$p['plan_sha256']=\Kulturhus\Verein\AssetPartialDisposal::hash($original);
        $p['allocation']=['basis'=>'documented_component_cost','cost_cents'=>4001,'residual_cents'=>0,'document_id'=>str_repeat('a',32),'revision'=>1,'sha256'=>hash('sha256','synthetic component costs'),'reason'=>'Synthetic evidenced component','same_use_and_life'=>true,'confirmed'=>true];
        $item['partial_plan']=$original;
    }else{$p['no_proceeds']=true;if($kind==='software_end')$p['rights_ended']=true;}
    $map=[];foreach(DC::codes($p) as $code=>$group){$key='disposal_'.$code;$r['accounts'][$key]=['name'=>'Synthetic disposal '.$code,'group'=>$group,'skr_code'=>(string)$code];$map[$code]=$key;}
    $item+=['basis_cents'=>$basis,'disposal'=>$p,'disposal_hash'=>hash('sha256','synthetic-disposal-binding'),'disposal_accounts'=>$map]+array_fill_keys(DD::FIELDS,0);
    $item=sharedAssetYear($item,2025);$parts=DA::item($item,$shares[$id]['weights'],2025);
    foreach(['purchase-food'=>'additions_cents','depreciation-food'=>'depreciation_cents'] as $name=>$field){
        $entry=substr(hash('sha256',$name),0,32);
        foreach($r['entries'] as &$e)if($e['id']===$entry){
            if($field==='depreciation_cents')$e['date']=$kind==='partial_scrap'?'2025-12-31':$date;
            foreach($e['lines'] as $ordinal=>&$l){
                $l['debit_cents']=$l['debit_cents']?$item[$field]:0;$l['credit_cents']=$l['credit_cents']?$item[$field]:0;
                if($kind==='software_end'){if($l['account']==='fixed_assets')$l['account']='fixed_software';if($l['account']==='depreciation')$l['account']='depreciation_software';}
                $split=[];foreach($parts as $u=>$row)if($row[$field])$split[$u]=$row[$field];
                $a['allocations'][$entry.':'.$ordinal]=['parts'=>$split,'reason'=>'Synthetic original disposal allocation'];
            }unset($l);
        }unset($e);
    }
    $r['accounts']=DCalc::chart($r['accounts']);$book=$item['book_disposals_cents'];
    $code=$kind==='sale'?$v['book_code']:($kind==='software_end'?'68960':'68950');
    $entry=hash('md5','synthetic-rest-value');
    if($book){
        $r['entries'][]=['id'=>$entry,'date'=>$date,'description'=>'Synthetic original complete book disposal','lines'=>[['account'=>$map[$code],'debit_cents'=>$book,'credit_cents'=>0,'sphere'=>'4'],['account'=>$item['account'],'debit_cents'=>0,'credit_cents'=>$book,'sphere'=>'']]];
        $split=[];foreach($parts as $u=>$row)if($row['book_disposals_cents'])$split[$u]=$row['book_disposals_cents'];
        foreach([0,1] as $o)$a['allocations'][$entry.':'.$o]=['parts'=>$split,'reason'=>'Synthetic original remaining book value'];
    }
    if($kind==='sale'){
        $entry=hash('md5','synthetic-sale');$net=DA::cents($v['net_cents'],$shares[$id]['weights']);$vat=DA::cents($v['tax_cents'],$shares[$id]['weights']);$gross=[];foreach($net as $u=>$n)$gross[$u]=$n+$vat[$u];
        $lines=[['account'=>'cash','debit_cents'=>$p['gross_cents'],'credit_cents'=>0,'sphere'=>''],['account'=>$map[$v['sale_code']],'debit_cents'=>0,'credit_cents'=>$v['net_cents'],'sphere'=>'4']];$splits=[$gross,$net];
        if($v['tax_cents']){$lines[]=['account'=>'sale_vat','debit_cents'=>0,'credit_cents'=>$v['tax_cents'],'sphere'=>''];$splits[]=$vat;}
        $r['entries'][]=['id'=>$entry,'date'=>$date,'description'=>'Synthetic original sales proceeds','lines'=>$lines];
        foreach($splits as $o=>$split)$a['allocations'][$entry.':'.$o]=['parts'=>array_filter($split),'reason'=>'Synthetic original sales price'];
        if($v['tax_cents']){
            $entry=hash('md5','synthetic-vat-closing');$r['entries'][]=['id'=>$entry,'date'=>'2025-12-31','description'=>'Synthetic annual VAT reconciliation','lines'=>[['account'=>'sale_vat','debit_cents'=>$v['tax_cents'],'credit_cents'=>0,'sphere'=>''],['account'=>'vat_closing','debit_cents'=>0,'credit_cents'=>$v['tax_cents'],'sphere'=>'']]];
            foreach([0,1] as $o)$a['allocations'][$entry.':'.$o]=['parts'=>array_filter($vat),'reason'=>'Synthetic retained VAT closing'];
        }
    }
    $r['asset_schedule']['items'][$id]=$item;$r['asset_schedule']['totals']=array_fill_keys(array_merge(DA::FIELDS,DD::FIELDS),0);
    foreach($r['asset_schedule']['items'] as $key=>&$row){$row+=array_fill_keys(DD::FIELDS,0);$shares[$key]['item_sha256']=DSeg::hash($row);foreach($r['asset_schedule']['totals'] as $f=>$_)$r['asset_schedule']['totals'][$f]+=$row[$f];}unset($row);
    $r['calculation']=DCalc::build(2025,$r['accounts'],$r['opening'],$r['entries']);$r['closed_sha256']=hash('sha256','synthetic shared complete disposal '.$variant);
    return clubTaxState($r,$a['units'],$a['allocations'],2,[],[],$shares);
}
