<?php declare(strict_types=1);
require __DIR__.'/../../src/bootstrap.php';
require_once __DIR__.'/../../src/Finance/Balance/BalanceTaxValues.php';
use Kulturhus\Verein\{BalanceCalculation as C,BalanceTaxValues as T,BalanceAssetSchedule,AssetBooks,AssetDisposal,AssetSale};
function ebilanzSale2026Fixture(string $variant='adjusted',?string $assetCode=null,?int $bookMonths=null,?int $taxMonths=null,string $vatKind='domestic'):array {
    if(str_starts_with($variant,'asset-partial-')){
        require_once __DIR__.'/ebilanz-partial-assets.php';
        return ebilanzPartialFixture(substr($variant,14),$assetCode);
    }
    if(str_starts_with($variant,'asset-tax-mixed-software-')){
        require_once __DIR__.'/ebilanz-mixed-disposals.php';
        return ebilanzMixedDisposalFixture(substr($variant,strlen('asset-tax-mixed-software-')),true);
    }
    if(str_starts_with($variant,'asset-tax-mixed-')){
        require_once __DIR__.'/ebilanz-mixed-disposals.php';
        return ebilanzMixedDisposalFixture(substr($variant,16));
    }
    $softwareEnd=str_contains($variant,'software-end');
    if($softwareEnd)$variant=str_replace('software-end','scrap',$variant);
    $chart=['bank'=>['name'=>'Test-Geschäftskonto','group'=>'cash','skr_code'=>'18000'],
        'capital'=>['name'=>'Geprüfter Ergebnisvortrag','group'=>'equity','skr_code'=>''],
        'receivables'=>['name'=>'Kundenforderungen','group'=>'receivables','skr_code'=>'12000'],
        'liabilities'=>['name'=>'Lieferantenverbindlichkeiten','group'=>'liabilities','skr_code'=>'33000'],
        'sales'=>['name'=>'Test-Umsätze Kleinunternehmer','group'=>'revenue','skr_code'=>''],
        'expense'=>['name'=>'Test-Verwaltungskosten','group'=>'expense','skr_code'=>'']];
    $line=fn($a,$d,$c,$s='')=>['account'=>$a,'debit_cents'=>$d,'credit_cents'=>$c,'sphere'=>$s];
    $opening=[$line('bank',50000,0),$line('capital',0,50000)];
    $entries=[['id'=>'test-invoice','date'=>'2026-02-01','lines'=>[$line('receivables',100000,0),$line('sales',0,100000,'4')]],
        ['id'=>'test-cost','date'=>'2026-03-01','lines'=>[$line('expense',20000,0,'4'),$line('liabilities',0,20000)]],
        ['id'=>'test-payment','date'=>'2026-04-01','lines'=>[$line('bank',40000,0),$line('receivables',0,40000)]]];
    $report=['year'=>2026,'state'=>'closed','closed_sha256'=>str_repeat('a',64),'accounts'=>$chart,'opening'=>$opening,'entries'=>$entries,
        'calculation'=>C::build(2026,$chart,$opening,$entries),'issues'=>[]];
    $review=['year'=>2026,'journal_sha256'=>$report['closed_sha256'],'complete'=>true,'opening_adjustments'=>[],
        'current_adjustments'=>[['id'=>'synthetic-value-difference','reason'=>'Rein synthetischer Bewertungsunterschied, keine steuerliche Handlungsempfehlung.',
            'lines'=>[$line('receivables',5000,0),$line('expense',0,5000,'4')]]]];
    if($variant==='unchanged')$review['current_adjustments']=[];
    if($variant==='loss'||$variant==='zero'){
        $report['entries'][1]['lines']=[$line('expense',$variant==='loss'?140000:100000,0,'4'),$line('liabilities',0,$variant==='loss'?140000:100000)];
        $review['current_adjustments']=[];
    }
    if($variant==='cents'){
        $report['entries'][1]['lines']=[$line('expense',20001,0,'4'),$line('liabilities',0,20001)];
    }
    if($variant==='2027'){
        $report['year']=2027;$review['year']=2027;
        foreach($report['entries'] as &$entry)$entry['date']='2027'.substr($entry['date'],4);unset($entry);
    }
    if($variant==='tax-carry'){
        $report['result_account']='capital';$prior=$report;
        $report['year']=2027;$report['previous_sha256']=$prior['closed_sha256'];$report['closed_sha256']=str_repeat('d',64);
        $report['opening']=C::carryForward($prior['calculation'],'capital');$report['entries']=[];
        $report['calculation']=C::build(2027,$chart,$report['opening'],[]);
        $carry=T::carryForward($prior,$review,$report);
        $review=['year'=>2027,'journal_sha256'=>$report['closed_sha256'],'complete'=>true,'opening_adjustments'=>[$carry['entry']],'current_adjustments'=>[]];
    }
    if(str_starts_with($variant,'asset-')||$variant==='mapping-coverage'){
        $review['current_adjustments']=[];$acquisitions=[];$annual=[];
        $kinds=in_array($variant,['asset-mixed','mapping-coverage'],true)?['equipment','software']:(in_array($variant,['asset-software','asset-tax-software'],true)?['software']:['equipment']);
        if($softwareEnd)$kinds=['software'];
        if($variant==='asset-multiple')$kinds=['equipment','equipment'];
        foreach($kinds as $index=>$kind){
            $taxScrap=str_starts_with($variant,'asset-tax-scrap');
            $taxSale=str_starts_with($variant,'asset-tax-sale');
            $sale=str_starts_with($variant,'asset-sale')||$taxSale;$scrap=str_starts_with($variant,'asset-scrap')||$taxScrap;$gwg=str_starts_with($variant,'asset-gwg')||in_array($variant,['asset-scrap-gwg','asset-sale-gwg'],true);
            $id='asset-'.$index;$key=$kind.'-'.$index;$depKey=$kind.'-depreciation';$skr=$gwg?'06700':($kind==='software'?'01350':($variant==='asset-office'?'06500':'06300'));
            if($assetCode!==null&&$kind==='equipment'&&!$gwg)$skr=$assetCode;
            $report['accounts'][$key]=['name'=>'Synthetischer Gegenstand '.$index,'group'=>'fixed_assets','skr_code'=>$skr];
            $report['accounts'][$depKey]=['name'=>'Synthetische Jahresabschreibung','group'=>'expense','skr_code'=>$gwg?'62600':(\Kulturhus\Verein\AssetTypes::depreciationCode($skr)??'62200')];
            $date=match($variant){'asset-prior','asset-tax-prior','asset-scrap-prior','asset-scrap-carry'=>'2025-12-02','asset-fully','asset-tax-fully','asset-scrap-fully'=>'2024-01-01','asset-scrap'=>'2026-02-02',default=>'2026-12-02'};
            if($sale)$date=$variant==='asset-sale-fully'?'2024-01-01':'2026-02-02';
            if($taxSale)$date=match($variant){'asset-tax-sale-faster','asset-tax-sale-after'=>'2025-12-02','asset-tax-sale-book-zero','asset-tax-sale-tax-zero'=>'2024-01-01',default=>'2026-02-02'};
            if($taxScrap)$date=match($variant){'asset-tax-scrap-prior','asset-tax-scrap-after','asset-tax-scrap-faster'=>'2025-12-02','asset-tax-scrap-book-zero','asset-tax-scrap-tax-zero'=>'2024-01-01',default=>'2026-02-02'};
            $plan=['basis_cents'=>$variant==='asset-cents'?12001:12000,'residual_cents'=>$variant==='asset-residual'?100:0,'months'=>12,
                'acquired_on'=>$date,'sphere'=>'4','account'=>$skr,'method'=>'linear_monthly','reason'=>'Synthetischer Testplan'];
            if($variant==='asset-tax-faster')$plan['months']=48;
            if($variant==='asset-tax-cents'){$plan['basis_cents']=12001;$plan['residual_cents']=100;}
            if(in_array($variant,['asset-tax-scrap-faster','asset-tax-scrap-tax-zero'],true))$plan['months']=48;
            if($variant==='asset-tax-scrap-cents'){$plan['basis_cents']=12001;$plan['residual_cents']=100;}
            if(in_array($variant,['asset-tax-sale-faster','asset-tax-sale-tax-zero'],true))$plan['months']=48;
            if($variant==='asset-tax-sale-cents'){$plan['basis_cents']=12001;$plan['residual_cents']=100;}
            if(str_starts_with($variant,'asset-degressive')||in_array($variant,['asset-scrap-degressive','asset-sale-degressive'],true)){
                $date=$variant==='asset-degressive-december'?'2026-12-02':'2026-07-01';
                $plan=array_replace($plan,['basis_cents'=>100001,'months'=>$variant==='asset-degressive-switch'?12:60,'acquired_on'=>$date,
                    'method'=>'degressive','degressive'=>['rate_basis_points'=>3000,'movable'=>true,'same_book_tax'=>true,'switch_to_linear'=>$variant!=='asset-degressive-no-switch']]);
            }
            if($gwg){
                $net=$variant==='asset-gwg-limit'?80000:12000;$vat=intdiv($net*19,100);$deductible=$variant==='asset-gwg-net'?$vat:0;
                $plan=array_replace($plan,['basis_cents'=>$net+$vat-$deductible,'months'=>1,'method'=>'gwg_immediate',
                    'gwg'=>['net_cents'=>$net,'vat_cents'=>$vat,'deductible_cents'=>$deductible,'independent_movable'=>true,'no_pool'=>true]]);
            }
            if($bookMonths!==null)$plan['months']=$bookMonths;
            $disposal=$scrap?['kind'=>'scrap','date'=>max($date,'2026-09-15'),'reason'=>'Synthetic documented full scrapping',
                'document_id'=>str_repeat('e',32),'revision'=>1,'sha256'=>str_repeat('f',64),'no_proceeds'=>true,'complete'=>true]:null;
            if($softwareEnd)$disposal=array_replace($disposal,['kind'=>'software_end','rights_ended'=>true,'reason'=>'Synthetic evidenced end of all purchased license rights']);
            $saleValues=null;
            if($sale){
                $rate=str_ends_with($variant,'-zero')?0:19;$gross=str_contains($variant,'loss')?($rate===0?3000:3570):($rate===0?5000:5950);
                if($taxSale){
                    $rate=$variant==='asset-tax-sale-vat-zero'?0:19;
                    $net=match($variant){'asset-tax-sale-gain'=>10000,'asset-tax-sale-loss'=>3000,'asset-tax-sale-faster','asset-tax-sale-even'=>8000,default=>5000};
                    $gross=$rate===0?$net:intdiv($net*119,100);
                }
                if($variant==='asset-sale-even')$gross=4760;
                if($vatKind!=='domestic'){$rate=0;$gross=$net??(str_contains($variant,'loss')?3000:5000);}
                $saleValues=AssetSale::amounts($plan,'2026-09-15',$gross,$rate,$vatKind);
                $disposal=['kind'=>'sale','date'=>'2026-09-15','reason'=>'Synthetic full domestic sale','document_id'=>str_repeat('e',32),'revision'=>1,'sha256'=>str_repeat('f',64),'complete'=>true,
                    'sale_entry'=>str_repeat('d',32),'sale_hash'=>str_repeat('a',64),'gross_cents'=>$gross,'tax_cents'=>$saleValues['tax_cents'],'rate'=>$rate,'vat_reason'=>$rate===0?'Synthetic evidenced tax exemption':'']+($vatKind==='domestic'?[]:['vat_kind'=>$vatKind]);
                $report['accounts']['sale-proceeds']=['name'=>'Synthetic asset proceeds','group'=>$saleValues['group'],'skr_code'=>$saleValues['sale_code']];
                $saleLines=[$line('receivables',$gross,0),$line('sale-proceeds',0,$saleValues['net_cents'],'4')];
                if($saleValues['tax_cents']){
                    $report['accounts']['sale-vat']=['name'=>'Synthetic explicitly settled VAT liability','group'=>'liabilities','skr_code'=>'38990'];
                    $saleLines[]=$line('sale-vat',0,$saleValues['tax_cents']);
                }
                $report['entries'][]=['id'=>'sale-'.$id,'date'=>$disposal['date'],'lines'=>$saleLines];
            }
            $schedule=AssetDisposal::schedule($plan,2026,$disposal);
            $acquisitions[]=['source_id'=>'invoice-'.$id,'source_hash'=>str_repeat('b',64),'snapshot'=>['entry'=>['display_number'=>'SYN-'.$id],
                'asset_accounts'=>[$id=>$key],'assets'=>[['item_id'=>$id,'payload'=>json_encode($plan)]]]];
            if(substr($date,0,4)==='2026')$report['entries'][]=['id'=>'purchase-'.$id,'date'=>$date,'lines'=>[$line($key,$plan['basis_cents'],0),$line('liabilities',0,$plan['basis_cents'])]];
            elseif($schedule['opening_cents'])$report['opening']=array_merge($report['opening'],[$line($key,$schedule['opening_cents'],0),$line('capital',0,$schedule['opening_cents'])]);
            if($schedule['depreciation_cents']||$disposal){
                if($schedule['depreciation_cents'])$report['entries'][]=['id'=>'afa-'.$id,'date'=>$disposal['date']??'2026-12-31','lines'=>[$line($depKey,$schedule['depreciation_cents'],0,'4'),$line($key,0,$schedule['depreciation_cents'])]];
                $annual[$id]=['source_hash'=>str_repeat('c',64),'snapshot'=>['annual'=>['item_id'=>$id,'year'=>2026,'opening_cents'=>$schedule['opening_cents'],
                    'depreciation_cents'=>$schedule['depreciation_cents'],'closing_cents'=>$schedule['closing_cents'],'snapshot'=>json_encode($disposal?['plan'=>$plan,'disposal'=>$disposal]:$plan)]]];
                if(($schedule['disposal_cents']??0)>0){
                    $report['accounts']['scrapping']=['name'=>'Synthetic disposal carrying amount','group'=>$saleValues['group']??'expense','skr_code'=>$saleValues['book_code']??($softwareEnd?'68960':'68950')];
                    $report['entries'][]=['id'=>'scrap-'.$id,'date'=>$disposal['date'],'lines'=>[$line('scrapping',$schedule['disposal_cents'],0,'4'),$line($key,0,$schedule['disposal_cents'])]];
                }
                if(($taxScrap||$softwareEnd)&&!isset($report['accounts']['scrapping']))$report['accounts']['scrapping']=['name'=>'Synthetic disposal carrying amount','group'=>'expense','skr_code'=>$softwareEnd?'68960':'68950'];
                if($softwareEnd)$annual[$id]['snapshot']['disposal_accounts']=['68960'=>'scrapping'];
                if($taxSale)foreach([[AssetSale::saleCodes($rate,$vatKind)[0],'revenue'],[AssetSale::saleCodes($rate,$vatKind)[1],'expense'],['48550','revenue'],['68950','expense']] as [$code,$group]){
                    if(!array_filter($report['accounts'],static fn($a)=>$a['skr_code']===$code))$report['accounts']['reserved-'.$code]=['name'=>'Synthetic reserved sale account '.$code,'group'=>$group,'skr_code'=>$code];
                }
            }
        }
        // Consolidate repeated opening accounts, as the real opening editor does.
        $net=[];foreach($report['opening'] as $row)$net[$row['account']]=($net[$row['account']]??0)+$row['debit_cents']-$row['credit_cents'];
        $report['opening']=[];foreach($net as $key=>$amount)$report['opening'][]=$line($key,max(0,$amount),max(0,-$amount));
        $report['calculation']=C::build(2026,$report['accounts'],$report['opening'],$report['entries']);
        $report['asset_schedule']=BalanceAssetSchedule::calculate(2026,$report['calculation'],$acquisitions,$annual);
    }
    if($variant==='multiple-bank'){
        $report['accounts']['bank2']=['name'=>'Weiteres Testkonto','group'=>'cash','skr_code'=>'18100'];
        $report['entries'][]=['id'=>'bank-transfer','date'=>'2026-11-01','lines'=>[$line('bank2',1000,0),$line('bank',0,1000)]];
    }
    if(str_starts_with($variant,'code-')||str_starts_with($variant,'detail-')){
        $detail=str_starts_with($variant,'detail-');$code=substr($variant,$detail?7:5,5);$rule=null;
        $registry=json_decode(file_get_contents(__DIR__.'/../../resources/ebilanz/skr42-6.9.json'),true,64,JSON_THROW_ON_ERROR);
        foreach($registry['rules'] as $candidate)if(in_array($code,$candidate['codes'],true))$rule=$candidate;
        if(!$rule||($detail?($rule['condition']??'')!=='detail_choice':isset($rule['condition']))||$rule['group']==='fixed_assets')throw new DomainException('Use the correct source fixture for conditional accounts.');
        $group=$rule['group'];$key='code-'.$code;$report['accounts'][$key]=['name'=>'Synthetischer Kontentest '.$code,'group'=>$group,'skr_code'=>$code];
        $debit=in_array($group,['cash','inventory','other_assets','receivables','prepaid','expense'],true);
        $report['entries'][]=['id'=>$key,'date'=>'2026-12-31','lines'=>$debit?[$line($key,1234,0,$group==='expense'?'4':''),$line('liabilities',0,1234)]:[$line('bank',1234,0),$line($key,0,1234,$group==='revenue'?'4':'')]];
        if(str_ends_with($variant,'-2027')){
            $report['year']=2027;$review['year']=2027;
            foreach($report['entries'] as &$entry)$entry['date']='2027'.substr($entry['date'],4);unset($entry);
        }
    }
    if(str_starts_with($variant,'operating-coverage')){
        $codes=json_decode(file_get_contents(__DIR__.'/ebilanz-operating-accounts.json'),true,64,JSON_THROW_ON_ERROR)['automatic'];
        $credit=str_contains($variant,'credit');
        foreach($codes as $groupCodes)foreach($groupCodes as $code){
            $key='code-'.$code;$report['accounts'][$key]=['name'=>'Synthetischer Kontentest '.$code,'group'=>'expense','skr_code'=>$code];
            $report['entries'][]=['id'=>$key,'date'=>'2026-12-31','lines'=>$credit?[$line('bank',1234,0),$line($key,0,1234,'4')]:[$line($key,1234,0,'4'),$line('liabilities',0,1234)]];
        }
        if(str_ends_with($variant,'-2027')){
            $report['year']=2027;$review['year']=2027;
            foreach($report['entries'] as &$entry)$entry['date']='2027'.substr($entry['date'],4);unset($entry);
        }
    }
    if(str_starts_with($variant,'vat-')){
        $review['current_adjustments']=[];
        foreach(['sales7'=>['revenue','43000'],'sales19'=>['revenue','44000'],
            'input-vat'=>['other_assets','14060'],'output-vat'=>['liabilities','38060'],
            'vat-due'=>['liabilities','38990'],'vat-refund'=>['other_assets','14610']] as $key=>[$group,$code])
            $report['accounts'][$key]=['name'=>'Synthetischer Umsatzsteuerfall '.$key,'group'=>$group,'skr_code'=>$code];
        $report['entries']=[
            ['id'=>'sale-19','date'=>'2026-02-01','lines'=>[$line('receivables',11900,0),$line('sales19',0,10000,'4'),$line('output-vat',0,1900)]],
            ['id'=>'sale-7','date'=>'2026-03-01','lines'=>[$line('receivables',10700,0),$line('sales7',0,10000,'4'),$line('output-vat',0,700)]],
            ['id'=>'purchase-19','date'=>'2026-04-01','lines'=>[$line('expense',4000,0,'4'),$line('input-vat',760,0),$line('liabilities',0,4760)]]];
        // Exact accounting evidence supplied by the fixture, not an automatic tax-closing algorithm.
        if($variant!=='vat-unsettled')$report['entries'][]=['id'=>'vat-closing','date'=>'2026-12-31',
            'lines'=>[$line('output-vat',2600,0),$line('input-vat',0,760),$line('vat-due',0,1840)]];
        if($variant==='vat-refund'){
            $report['entries'][2]['lines']=[$line('expense',40000,0,'4'),$line('input-vat',7600,0),$line('liabilities',0,47600)];
            $report['entries'][3]['lines']=[$line('output-vat',2600,0),$line('vat-refund',5000,0),$line('input-vat',0,7600)];
        }
        if($variant==='vat-return'){
            $report['entries'][]=['id'=>'return-19','date'=>'2026-10-01','lines'=>[$line('sales19',1000,0,'4'),$line('output-vat',190,0),$line('receivables',0,1190)]];
            $report['entries'][3]['lines']=[$line('output-vat',2410,0),$line('input-vat',0,760),$line('vat-due',0,1650)];
        }
        if($variant==='vat-2027'){
            $report['year']=2027;$review['year']=2027;
            foreach($report['entries'] as &$entry)$entry['date']='2027'.substr($entry['date'],4);unset($entry);
        }
    }
    if($variant==='mapping-coverage'){
        foreach(['16000','16100','16200','18100','18200','64000','68000','68050','68100','68150','68300'] as $code){
            $key='extra-'.$code;$group=$code[0]==='1'?'cash':'expense';
            $report['accounts'][$key]=['name'=>'Synthetischer Kontentest '.$code,'group'=>$group,'skr_code'=>$code];
            $report['entries'][]=['id'=>'extra-'.$code,'date'=>'2026-12-31','lines'=>[$line($key,100,0,$group==='expense'?'4':''),$line('liabilities',0,100)]];
        }
    }
    if(in_array($variant,['accruals','accruals-2027','accruals-release','accruals-tax-change'],true)){
        $review['current_adjustments']=[];
        foreach(['18300'=>'cash','18400'=>'cash','18500'=>'cash','19000'=>'prepaid','30700'=>'provisions','35000'=>'liabilities','39000'=>'deferred','68250'=>'expense'] as $code=>$group){
            $code=(string)$code;$key='extra-'.$code;
            $report['accounts'][$key]=['name'=>'Synthetischer Kontentest '.$code,'group'=>$group,'skr_code'=>$code];
            $debit=in_array($group,['cash','prepaid','expense'],true);
            $lines=$debit?[$line($key,1234,0,$group==='expense'?'4':''),$line('liabilities',0,1234)]:[$line('expense',1234,0,'4'),$line($key,0,1234)];
            $report['entries'][]=['id'=>$key,'date'=>'2026-12-31','lines'=>$lines];
        }
        if($variant==='accruals-release'){
            $report['opening']=array_merge($report['opening'],[$line('extra-19000',500,0),$line('extra-39000',0,500)]);
            $report['entries'][]=['id'=>'prepaid-release','date'=>'2026-12-31','lines'=>[$line('expense',500,0,'4'),$line('extra-19000',0,500)]];
            $report['entries'][]=['id'=>'deferred-release','date'=>'2026-12-31','lines'=>[$line('extra-39000',500,0),$line('sales',0,500,'4')]];
        }
        if($variant==='accruals-tax-change')$review['current_adjustments']=[['id'=>'tax-provision','reason'=>'Synthetische steuerliche Minderung einer Rückstellung.',
            'lines'=>[$line('extra-30700',234,0),$line('expense',0,234,'4')]]];
        if($variant==='accruals-2027'){
            $report['year']=2027;$review['year']=2027;
            foreach($report['entries'] as &$entry)$entry['date']='2027'.substr($entry['date'],4);unset($entry);
        }
    }
    if($variant==='asset-gwg-prior'){
        $report['year']=2027;$review['year']=2027;
        $report['opening']=C::carryForward($report['calculation'],'capital');$report['entries']=[];
        $report['calculation']=C::build(2027,$report['accounts'],$report['opening'],[]);
        $report['asset_schedule']=BalanceAssetSchedule::calculate(2027,$report['calculation'],$acquisitions,[]);
    }
    if(str_starts_with($variant,'asset-tax-')){
        $report['result_account']='capital';
        $review['asset_tax_plans']=['asset-0'=>['months'=>in_array($variant,['asset-tax-fully','asset-tax-scrap-book-zero','asset-tax-sale-book-zero'],true)?48:24,'depreciation_account'=>$kinds[0].'-depreciation','reason'=>'Fachlich bestätigter synthetischer Testnachweis.','confirmed'=>true]];
        if(in_array($variant,['asset-tax-scrap-tax-zero','asset-tax-sale-tax-zero'],true))$review['asset_tax_plans']['asset-0']['months']=12;
    }
    if($taxMonths!==null&&isset($review['asset_tax_plans']['asset-0']))$review['asset_tax_plans']['asset-0']['months']=$taxMonths;
    if(in_array($variant,['asset-scrap-carry','asset-sale-carry','asset-tax-scrap-after','asset-tax-sale-after'],true)){
        $taxAfter=in_array($variant,['asset-tax-scrap-after','asset-tax-sale-after'],true);
        if($taxAfter){$priorReport=$report;$priorReview=$review;$report['previous_sha256']=$report['closed_sha256'];$report['closed_sha256']=str_repeat('d',64);$review['journal_sha256']=$report['closed_sha256'];}
        $report['year']=2027;$review['year']=2027;$report['opening']=C::carryForward($report['calculation'],'capital');$report['entries']=[];
        $report['calculation']=C::build(2027,$report['accounts'],$report['opening'],[]);
        $report['asset_schedule']=BalanceAssetSchedule::calculate(2027,$report['calculation'],$acquisitions,[],$annual);
        if($taxAfter){$carry=T::carryForward($priorReport,$priorReview,$report);$review['opening_adjustments']=$carry['entry']?[$carry['entry']]:[];}
    }
    if($variant==='asset-degressive-prior'||$variant==='asset-tax-carry'){
        if($variant==='asset-tax-carry'){$priorReport=$report;$priorReview=$review;$report['previous_sha256']=$report['closed_sha256'];$report['closed_sha256']=str_repeat('d',64);$review['journal_sha256']=$report['closed_sha256'];}
        $report['year']=2027;$review['year']=2027;$report['opening']=C::carryForward($report['calculation'],'capital');$report['entries']=[];$annual=[];
        foreach($acquisitions as $acquisition)foreach($acquisition['snapshot']['assets'] as $a){
            $plan=json_decode($a['payload'],true,64,JSON_THROW_ON_ERROR);$id=$a['item_id'];$key=$acquisition['snapshot']['asset_accounts'][$id];$s=AssetBooks::schedule($plan,2027);
            $report['entries'][]=['id'=>'afa-'.$id,'date'=>'2027-12-31','lines'=>[$line('equipment-depreciation',$s['depreciation_cents'],0,'4'),$line($key,0,$s['depreciation_cents'])]];
            $annual[$id]=['source_hash'=>str_repeat('c',64),'snapshot'=>['annual'=>['item_id'=>$id,'year'=>2027,'opening_cents'=>$s['opening_cents'],
                'depreciation_cents'=>$s['depreciation_cents'],'closing_cents'=>$s['closing_cents'],'snapshot'=>$a['payload']]]];
        }
        $report['calculation']=C::build(2027,$report['accounts'],$report['opening'],$report['entries']);
        $report['asset_schedule']=BalanceAssetSchedule::calculate(2027,$report['calculation'],$acquisitions,$annual);
        if($variant==='asset-tax-carry'){$carry=T::carryForward($priorReport,$priorReview,$report);$review['opening_adjustments']=$carry['entry']?[$carry['entry']]:[];}
    }
    $report['calculation']=C::build($report['year'],$report['accounts'],$report['opening'],$report['entries']);
    return [$report,$review];
}
