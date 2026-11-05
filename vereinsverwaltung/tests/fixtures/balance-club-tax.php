<?php declare(strict_types=1);
require_once __DIR__.'/balance-segments.php';
use Kulturhus\Verein\{BalanceSegmentCalculation as ClubS,BalanceClubTaxReport as ClubR,BalanceCalculation as ClubC};
function clubTaxState(array $report,array $units,array $alloc,int $revision=2,array $capitalCarry=[],array $transfers=[],array $assetShares=[]):array
{
    $a=ClubS::build($report,$units,$alloc,$capitalCarry,$transfers,$assetShares);
    $p=['schema'=>1,'club_id'=>'synthetic','year'=>$report['year'],'revision'=>$revision,'previous_sha256'=>hash('sha256','synthetic draft'),'journal_sha256'=>$report['closed_sha256'],'state'=>'reviewed','actor'=>'synthetic-board','at'=>'2026-09-20T00:00:00+00:00','note'=>'Synthetic complete review','carry_forward'=>null,'allocation'=>$a];
    return ['year'=>$report['year'],'version'=>$revision,'state'=>'reviewed','allocation'=>$a,'journal'=>$report,'history'=>[$p+['sha256'=>ClubS::hash($p)]],'current_approval'=>true];
}
function clubTaxFixture(bool $assets=false):array
{
    [$r,$units,$alloc]=segmentFixture();
    $schedule=['year'=>2025,'items'=>[],'totals'=>array_fill_keys(ClubR::FIELDS,0),'issues'=>[],'ready_for_xbrl'=>false];
    if($assets){
        $r['accounts']['fixed_assets']['skr_code']='06300';
        $r['accounts']['depreciation']=['name'=>'Abschreibung','group'=>'expense','skr_code'=>'62200'];$r['accounts']=ClubC::chart($r['accounts']);
        foreach(['food'=>['4',12000],'culture'=>['3',6000]] as $unit=>[$sphere,$cost]){
            $item=substr(hash('sha256','asset-'.$unit),0,32);$purchase=substr(hash('sha256','purchase-'.$unit),0,32);$dep=substr(hash('sha256','depreciation-'.$unit),0,32);$depreciation=intdiv($cost,5);
            foreach([[$purchase,'fixed_assets','cash',$cost],[$dep,'depreciation','fixed_assets',$depreciation]] as [$id,$debit,$credit,$cents]){
                $r['entries'][]=['id'=>$id,'date'=>'2025-01-01','description'=>'Synthetic asset '.$unit,'lines'=>[['account'=>$debit,'debit_cents'=>$cents,'credit_cents'=>0,'sphere'=>$debit==='depreciation'?$sphere:''],['account'=>$credit,'debit_cents'=>0,'credit_cents'=>$cents,'sphere'=>'']]];
                for($i=0;$i<2;$i++)$alloc[$id.':'.$i]=['parts'=>[$unit=>$cents],'reason'=>'Synthetic item-specific evidence'];
            }
            $a=['item_id'=>$item,'invoice_id'=>$purchase,'receipt_number'=>'TEST-'.$unit,'account'=>'fixed_assets','skr_code'=>'06300','sphere'=>$sphere,'acquired_on'=>'2025-01-01','months'=>60,'residual_cents'=>0,
                'cost_opening_cents'=>0,'additions_cents'=>$cost,'cost_closing_cents'=>$cost,'accumulated_opening_cents'=>0,'depreciation_cents'=>$depreciation,'accumulated_closing_cents'=>$depreciation,'book_opening_cents'=>0,'book_closing_cents'=>$cost-$depreciation,
                'expected_depreciation_cents'=>$depreciation,'acquisition_hash'=>hash('sha256',$purchase),'depreciation_hash'=>hash('sha256',$dep)];
            $schedule['items'][$item]=$a;foreach(ClubR::FIELDS as $f)$schedule['totals'][$f]+=$a[$f];
        }
    }
    $r['asset_schedule']=$schedule;$r['calculation']=ClubC::build(2025,$r['accounts'],$r['opening'],$r['entries']);
    return clubTaxState($r,$units,$alloc);
}
