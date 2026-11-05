<?php declare(strict_types=1);
use Kulturhus\Verein\{BalancePeriodAreas as AssetAreas,BalancePeriodExport as AssetAreaExport};

/** Real stored journals and source bindings, no invented area schedules. */
function verifyAssetAreaChain(PDO $db,string $actor,object $ledger,array $periods,string $sphere):array
{
    $service=new AssetAreas($db,$actor);$units=['club'=>['name'=>'Synthetic actual activity','sphere'=>$sphere]];$previous=null;
    foreach($periods as $id){
        $report=$ledger->report($id);
        $service->prepare($id,$service->view($id)['version'],$report['closed_sha256'],$units,'Synthetic original activity');
        $state=$service->view($id);
        $missing=[];foreach(\Kulturhus\Verein\BalanceSegmentCalculation::sources($state['source']) as $key=>$row)if(!isset($state['input']['allocations'][$key]))$missing[$row['voucher']]=true;
        foreach($missing as $voucher=>$_){$service->edit($id,$state['version'],$report['closed_sha256'],'voucher',['voucher'=>$voucher,'unit'=>'club'],'Synthetic single activity original');$state=$service->view($id);}
        check($state['proof']['complete'],'actual asset activity reconciles '.$id.': '.implode(' ',$state['proof']['issues']));
        foreach($report['asset_schedule']['items'] as $item=>$source){
            $parts=$state['proof']['assets']['schedules']['club']['items'][$item];
            foreach(\Kulturhus\Verein\BalancePeriodAssetAllocation::FIELDS as $f)check($parts[$f]===($source['schedule'][$f]??0),'actual asset amount '.$item.' '.$f);
            if($previous)foreach(['cost','accumulated','book'] as $f)check($parts[$f.'_opening_cents']===$previous['proof']['assets']['schedules']['club']['items'][$item][$f.'_closing_cents'],'disposed item cannot reappear '.$item.' '.$f);
        }
        $service->confirm($id,$state['version'],$report['closed_sha256'],$state['sha256'],true,'Synthetic activity confirmation');
        $state=$service->view($id);$income=$service->incomeReconciliation($id);
        check($service->originalIncome($id,$state['version'],$state['sha256'])===$income,'actual historical asset income exactly equals its independently reconstructed live source '.$id);
        check($income['result']['whole_cents']===$report['calculation']['annual_result_cents'],'disposal and income reconcile once '.$id);
        check(!$income['tax_export_ready'],'activity proof does not bypass official tax release');
        $export=(new AssetAreaExport($db,$actor))->build($id);
        check(isset($export['files']['anlagennutzung.csv'],$export['files']['anlagennutzung.json']),'asset use retained in working papers '.$id);
        $previous=$state;
    }
    return $previous;
}
