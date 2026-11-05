<?php declare(strict_types=1);
use Kulturhus\Verein\{AssetPartialDisposal,AssetDisposal,BalanceAssetSchedule,BalanceCalculation,BalanceTaxValues};
function ebilanzPartialFixture(string $case,?string $assetCode=null):array{
    [$r,$review]=ebilanzFixture('unchanged');
    $carry=str_ends_with($case,'carry');$year=$carry?2026:2025;
    $p=['basis_cents'=>$case==='cents'?12001:12000,'residual_cents'=>$case==='residual'?1200:0,
        'acquired_on'=>$case==='zero'||$case==='tax-zero'?'2023-01-15':($case==='prior'?'2024-01-15':'2025-01-15'),
        'months'=>$case==='tax-fast'?48:24,'sphere'=>'4','account'=>$assetCode??'06300','reason'=>'Synthetic original component plan','method'=>'linear_monthly'];
    $d=['schema'=>1,'kind'=>'partial_scrap','date'=>'2025-06-15','reason'=>'Synthetic component scrapping',
        'document_id'=>str_repeat('e',32),'revision'=>1,'sha256'=>str_repeat('f',64),'plan_sha256'=>AssetPartialDisposal::hash($p),
        'allocation'=>['basis'=>'documented_component_cost','cost_cents'=>4000,'residual_cents'=>$case==='residual'?400:0,
            'document_id'=>str_repeat('d',32),'revision'=>1,'sha256'=>str_repeat('b',64),'reason'=>'Synthetic separately evidenced component cost','same_use_and_life'=>true,'confirmed'=>true],
        'no_proceeds'=>true,'complete'=>false];
    $r['year']=$year;$review['year']=$year;$r['result_account']='capital';
    foreach($r['entries'] as &$entry)$entry['date']=$year.substr($entry['date'],4);unset($entry);
    $r['accounts']['equipment-0']=['name'=>'Synthetic partial equipment','group'=>'fixed_assets','skr_code'=>$p['account']];
    $r['accounts']['equipment-depreciation']=['name'=>'Synthetic annual depreciation','group'=>'expense','skr_code'=>'62200'];
    $r['accounts']['partial-loss']=['name'=>'Synthetic component residual loss','group'=>'expense','skr_code'=>'68950'];
    $line=fn($a,$d,$c,$s='')=>['account'=>$a,'debit_cents'=>$d,'credit_cents'=>$c,'sphere'=>$s];
    $book=AssetPartialDisposal::preview($p,$d,$year)['book'];
    if($book['additions_cents'])$r['entries'][]=['id'=>'purchase','date'=>$p['acquired_on'],'lines'=>[$line('equipment-0',$p['basis_cents'],0),$line('liabilities',0,$p['basis_cents'])]];
    if($book['book_opening_cents']){
        $r['opening'][]=$line('equipment-0',$book['book_opening_cents'],0);
        foreach($r['opening'] as &$opening)if($opening['account']==='capital')$opening['credit_cents']+=$book['book_opening_cents'];unset($opening);
    }
    foreach(['depreciation_cents'=>'equipment-depreciation','book_disposals_cents'=>'partial-loss'] as $field=>$key)if($book[$field])$r['entries'][]=['id'=>$key,'date'=>$year.'-12-31','lines'=>[$line($key,$book[$field],0,'4'),$line('equipment-0',0,$book[$field])]];
    $acq=['source_id'=>'synthetic-purchase','source_hash'=>str_repeat('a',64),'snapshot'=>['entry'=>['display_number'=>'SYN-PART'],
        'asset_accounts'=>['asset-0'=>'equipment-0'],'assets'=>[['item_id'=>'asset-0','payload'=>json_encode($p)]]]];
    $annual=function(int $y)use($p,$d){$s=AssetDisposal::schedule($p,$y,$d);return ['source_hash'=>str_repeat($y===2025?'c':'d',64),
        'snapshot'=>['annual'=>['item_id'=>'asset-0','year'=>$y,'opening_cents'=>$s['opening_cents'],'depreciation_cents'=>$s['depreciation_cents'],
            'closing_cents'=>$s['closing_cents'],'snapshot'=>json_encode(['plan'=>$p,'disposal'=>$d])],'disposal_accounts'=>['68950'=>'partial-loss']]];};
    $r['calculation']=BalanceCalculation::build($year,$r['accounts'],$r['opening'],$r['entries']);
    $r['asset_schedule']=BalanceAssetSchedule::calculate($year,$r['calculation'],[$acq],['asset-0'=>$annual($year)],['asset-0'=>$annual(2025)]);
    if($r['asset_schedule']['issues'])throw new RuntimeException(implode(' ',$r['asset_schedule']['issues']));
    if(str_starts_with($case,'tax-'))$review['asset_tax_plans']=['asset-0'=>['months'=>$case==='tax-fast'?24:48,'depreciation_account'=>'equipment-depreciation','reason'=>'Synthetic separate tax life','confirmed'=>true]];
    if($case==='tax-carry'){
        [$prior,$old]=ebilanzPartialFixture('tax-slow');$r['previous_sha256']=$prior['closed_sha256'];$r['closed_sha256']=str_repeat('e',64);$review['journal_sha256']=$r['closed_sha256'];
        $r['opening']=BalanceCalculation::carryForward($prior['calculation'],'capital');
        $r['entries']=array_values(array_filter($r['entries'],fn($v)=>in_array($v['id'],['equipment-depreciation','partial-loss'],true)));
        $r['calculation']=BalanceCalculation::build($year,$r['accounts'],$r['opening'],$r['entries']);
        $r['asset_schedule']=BalanceAssetSchedule::calculate($year,$r['calculation'],[$acq],['asset-0'=>$annual($year)],['asset-0'=>$annual(2025)]);
        $c=BalanceTaxValues::carryForward($prior,$old,$r);$review['opening_adjustments']=$c['entry']?[$c['entry']]:[];
    }
    return [$r,$review];
}
