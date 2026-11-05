<?php declare(strict_types=1);
/** Two synthetic assets, two distinct 68950 accounts; no database or external services. */
function ebilanzMixedDisposalFixture(string $case,bool $includeSoftware=false):array
{
    [$report,$review]=ebilanzFixture('unchanged');$base=$report;$report['result_account']='capital';$review['asset_tax_plans']=[];
    $net=[];foreach($base['opening'] as $line)$net[$line['account']]=$line['debit_cents']-$line['credit_cents'];
    $schedule=null;
    foreach($includeSoftware?['sold','scrapped','software']:['sold','scrapped'] as $kind){
        $sale=$kind==='sold';
        $variant=match($case){'zero'=>'book-zero','faster'=>'faster','cents'=>'cents','loss'=>$sale?'loss':'same','gain'=>$sale?'gain':'same',default=>$sale?'cross':'same'};
        [$part,$partReview]=ebilanzFixture('asset-tax-'.($sale?'sale':($kind==='software'?'software-end':'scrap')).'-'.$variant);
        $rename=[];
        foreach($part['accounts'] as $key=>$a){
            $to=isset($base['accounts'][$key])||$key==='equipment-depreciation'?$key:$kind.'-'.$key;
            $rename[$key]=$to;$report['accounts'][$to]=$a;
        }
        // Each part includes the same ordinary fixture; add that base only once.
        foreach($part['opening'] as $line){$key=$rename[$line['account']];$net[$key]=($net[$key]??0)+$line['debit_cents']-$line['credit_cents'];}
        foreach($base['opening'] as $line)$net[$line['account']]-=$line['debit_cents']-$line['credit_cents'];
        foreach(array_slice($part['entries'],count($base['entries'])) as $entry){
            $entry['id']=$kind.'-'.$entry['id'];foreach($entry['lines'] as &$line)$line['account']=$rename[$line['account']];unset($line);
            $report['entries'][]=$entry;
        }
        $item=$part['asset_schedule']['items']['asset-0'];$item['item_id']=$kind;$item['invoice_id']=$kind.'-purchase';$item['receipt_number']='SYN-'.$kind;
        $item['account']=$rename[$item['account']];$item['disposal_accounts']=[];
        foreach(\Kulturhus\Verein\BalanceDisposalAccounts::codes($item['disposal']) as $code=>$group){
            $peers=array_keys(array_filter($part['accounts'],static fn($a)=>$a['skr_code']===(string)$code&&$a['group']===$group));
            if(count($peers)!==1)throw new RuntimeException('Synthetic part must have unique accounts');
            $item['disposal_accounts'][$code]=$rename[$peers[0]];
        }
        if($schedule===null){$schedule=$part['asset_schedule'];$schedule['items']=[];$schedule['totals']=array_fill_keys(array_keys($schedule['totals']),0);}
        $schedule['items'][$kind]=$item;foreach($schedule['totals'] as $key=>$_)$schedule['totals'][$key]+=$item[$key];
        $review['asset_tax_plans'][$kind]=$partReview['asset_tax_plans']['asset-0'];
        $review['asset_tax_plans'][$kind]['depreciation_account']=$rename[$review['asset_tax_plans'][$kind]['depreciation_account']];
    }
    ksort($schedule['items'],SORT_STRING);$report['asset_schedule']=$schedule;$report['opening']=[];
    foreach($net as $key=>$amount)if($amount)$report['opening'][]=['account'=>$key,'debit_cents'=>max(0,$amount),'credit_cents'=>max(0,-$amount),'sphere'=>''];
    $report['calculation']=\Kulturhus\Verein\BalanceCalculation::build(2025,$report['accounts'],$report['opening'],$report['entries']);
    if($case==='after'){
        $prior=$report;$priorReview=$review;$report['year']=2026;$review['year']=2026;
        $report['previous_sha256']=$prior['closed_sha256'];$report['closed_sha256']=str_repeat('d',64);$review['journal_sha256']=$report['closed_sha256'];
        $report['opening']=\Kulturhus\Verein\BalanceCalculation::carryForward($prior['calculation'],'capital');$report['entries']=[];
        $report['calculation']=\Kulturhus\Verein\BalanceCalculation::build(2026,$report['accounts'],$report['opening'],[]);
        $report['asset_schedule']['year']=2026;
        foreach($report['asset_schedule']['totals'] as $key=>$_)$report['asset_schedule']['totals'][$key]=0;
        foreach($report['asset_schedule']['items'] as &$item){
            foreach($report['asset_schedule']['totals'] as $key=>$_)$item[$key]=0;
            $item['expected_depreciation_cents']=0;$item['depreciation_hash']=null;
        }unset($item);
        $carry=\Kulturhus\Verein\BalanceTaxValues::carryForward($prior,$priorReview,$report);
        $review['opening_adjustments']=$carry['entry']?[$carry['entry']]:[];
    }
    return [$report,$review];
}
