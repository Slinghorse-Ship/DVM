<?php declare(strict_types=1);
require_once __DIR__.'/../../src/Finance/Balance/BalancePeriodTaxValues.php';
require_once __DIR__.'/../../src/Finance/Balance/BalanceClubTaxReconciliation.php';
require_once __DIR__.'/balance-club-tax.php';
require_once __DIR__.'/balance-shared-disposals.php';
use Kulturhus\Verein\{BalanceCalculation as PTCalc,BalancePeriodDisposal as PTDisposal,BalancePeriodAssetAllocation as PTAssets,BalancePeriodSale as PTSale,
    BalanceSegmentCalculation as PTSeg,BalanceInternalClearing as PTClearing,BalanceClearingStatement as PTStatement,BalanceClubIncomeReconciliation as PTIncome};

function periodTaxIncomeFixture(?array $previous=null):array
{
    $period=$previous?['start'=>'2026-07-01','end'=>'2026-12-31']:['start'=>'2025-07-01','end'=>'2026-06-30'];
    $chart=['cash'=>['name'=>'Synthetic bank','group'=>'cash','skr_code'=>'18000'],'equity'=>['name'=>'Synthetic capital','group'=>'equity','skr_code'=>'25000'],
        'fixed'=>['name'=>'Synthetic equipment','group'=>'fixed_assets','skr_code'=>'06300'],'software'=>['name'=>'Synthetic software','group'=>'fixed_assets','skr_code'=>'01350'],
        'dep'=>['name'=>'Synthetic depreciation','group'=>'expense','skr_code'=>'62200'],'softwaredep'=>['name'=>'Synthetic software amortisation','group'=>'expense','skr_code'=>'62000'],
        'expense'=>['name'=>'Synthetic general cost','group'=>'expense','skr_code'=>'63000'],'receivables'=>['name'=>'Synthetic open claims','group'=>'receivables','skr_code'=>'12000']];
    foreach(['48490'=>'revenue','48550'=>'revenue','68890'=>'expense','68950'=>'expense','68960'=>'expense'] as $code=>$group)$chart['d'.$code]=['name'=>'Synthetic disposal '.$code,'group'=>$group,'skr_code'=>(string)$code];
    $chart=PTCalc::chart($chart);$entries=[];$items=[];$old=$previous['scope']['source']??null;
    $opening=$old?PTCalc::carryForward($old['calculation'],'equity'):[['account'=>'cash','debit_cents'=>2000000,'credit_cents'=>0,'sphere'=>''],['account'=>'equity','debit_cents'=>0,'credit_cents'=>2000000,'sphere'=>'']];
    $post=static function(string $id,string $date,string $debit,string $credit,int $amount)use(&$entries,$chart):void{
        if(!$amount)return;$entryId=hash('md5',$id.$date);$lines=[];
        foreach([[$debit,$amount,0],[$credit,0,$amount]] as [$account,$d,$c])$lines[]=['account'=>$account,'debit_cents'=>$d,'credit_cents'=>$c,'sphere'=>in_array($chart[$account]['group'],['revenue','expense'],true)?'4':''];
        $entries[]=['id'=>$entryId,'date'=>$date,'description'=>'Synthetic '.$id,'lines'=>$lines];
    };
    foreach(['linear','scrap','software','partial','gain','loss','switch'] as $id){
        $p=['basis_cents'=>120001,'residual_cents'=>0,'months'=>60,'acquired_on'=>'2025-09-18','method'=>'linear_monthly','account'=>$id==='software'?'01350':'06300','sphere'=>'4','reason'=>'Synthetic original'];
        $fixed=$id==='software'?'software':'fixed';$dep=$id==='software'?'softwaredep':'dep';$proof=null;
        if($id!=='linear'){
            $proof=['kind'=>$id==='partial'?'partial_scrap':($id==='software'?'software_end':(in_array($id,['gain','loss','switch'],true)?'sale':'scrap')),'date'=>'2026-01-15','reason'=>'Synthetic original disposal','document_id'=>str_repeat('d',32),'revision'=>1,'sha256'=>hash('sha256','Synthetic document'),'complete'=>true];
            if($proof['kind']==='sale'){
                $gross=match($id){'gain'=>140000,'loss'=>100000,default=>112000};
                $proof+=['sale_entry'=>hash('md5','sale-'.$id),'sale_hash'=>hash('sha256','Synthetic sale '.$id),'gross_cents'=>$gross,'tax_cents'=>0,'rate'=>0,'vat_reason'=>'Synthetic reviewed exemption'];
            }else{
                $proof['no_proceeds']=true;if($id==='software')$proof['rights_ended']=true;
                if($id==='partial'){
                    $proof['schema']=1;$proof['complete']=false;$proof['plan_sha256']=\Kulturhus\Verein\AssetPartialDisposal::hash($p);
                    $proof['allocation']=['basis'=>'documented_component_cost','cost_cents'=>30000,'residual_cents'=>0,'document_id'=>str_repeat('a',32),'revision'=>1,'sha256'=>hash('sha256','Synthetic component'),
                        'reason'=>'Synthetic component cost','same_use_and_life'=>true,'confirmed'=>true];
                }
            }
        }
        $schedule=PTDisposal::calculate($p,$period,$old['asset_schedule']['items'][$id]['schedule']??null,$proof);
        $item=['item_id'=>$id,'name'=>'Synthetic '.$id,'fixed_account'=>$fixed,'plan'=>$p,'schedule'=>$schedule,'posted'=>true];
        if($proof){$item['disposal']=$proof;$item['disposal_accounts']=[];foreach(\Kulturhus\Verein\BalanceDisposalAccounts::codes($proof) as $code=>$_)$item['disposal_accounts'][$code]='d'.$code;}
        if(($proof['kind']??null)==='sale')$item['sale_values']=PTSale::values($proof,110001);
        if(!$old){
            $post('acquisition-'.$id,$p['acquired_on'],$fixed,'cash',$p['basis_cents']);
            if($proof){
                $book=$schedule['book_disposals_cents'];$code=$proof['kind']==='sale'?$item['sale_values']['book_code']:($id==='software'?'68960':'68950');
                $post('book-disposal-'.$id,$proof['date'],'d'.$code,$fixed,$book);
                if($proof['kind']==='sale'){
                    $post('sale-'.$id,$proof['date'],'cash','d'.$item['sale_values']['sale_code'],$proof['gross_cents']);
                    $item['sale_posting']=['entry_id'=>hash('md5','sale-'.$id.$proof['date']),'source_id'=>$proof['sale_entry'],'source_sha256'=>hash('sha256','Synthetic retained sale '.$id),'account'=>'d'.$item['sale_values']['sale_code']];
                }
            }
        }
        $post('depreciation-'.$id,$period['end'],$dep,$fixed,$schedule['depreciation_cents']);$items[$id]=$item;
    }
    $report=['year'=>2026,'state'=>'closed','closed_sha256'=>hash('sha256',json_encode([$period,$entries])),'previous_sha256'=>$old['closed_sha256']??null,'result_account'=>'equity','issues'=>[],
        'accounts'=>$chart,'opening'=>$opening,'entries'=>$entries,'calculation'=>PTCalc::build(2026,$chart,$opening,$entries,$period),
        'asset_schedule'=>['period'=>$period,'items'=>$items,'issues'=>[]],'period'=>$period];
    $units=['activity'=>['name'=>'Synthetic taxable activity','sphere'=>'4']];$alloc=[];
    foreach(PTSeg::sources($report) as $key=>$row)$alloc[$key]=['parts'=>['activity'=>$row['cents']],'reason'=>'Synthetic original source'];
    $allocation=PTSeg::build($report,$units,$alloc);$clearing=PTClearing::build('synthetic-tax',$report,$units,$alloc,['advances'=>[],'repayments'=>[]],$previous['scope']['clearing']??null);
    $statement=PTStatement::build($report,$allocation,$clearing);$shares=[];
    foreach($items as $id=>$item)$shares[$id]=['item_sha256'=>PTSeg::hash($item),'weights'=>['activity'=>1],'measure'=>'uses','reason'=>'Synthetic original use'];
    $report['assets']=PTAssets::build($report,$units,$statement['units'],$shares,$old['assets']??null);
    $scope=['schema'=>3,'kind'=>'club_income_reconciliation','club_id'=>'synthetic-tax','source'=>$report,'allocation'=>$allocation,'clearing'=>$clearing,
        'review'=>['allocation'=>['revision'=>2,'sha256'=>hash('sha256','Synthetic area review')],'clearing'=>['revision'=>2,'sha256'=>hash('sha256','Synthetic area review')]],'previous'=>$previous['scope']??null];
    return PTIncome::rebuild($scope);
}
function periodTaxReview(array $income):array
{
    return ['year'=>$income['year'],'journal_sha256'=>$income['scope']['source']['closed_sha256'],'income_sha256'=>$income['sha256'],'complete'=>true,
        'opening_adjustments'=>[],'current_adjustments'=>[],'asset_tax_plans'=>[],'off_balance_adjustments'=>[],'period'=>$income['period']];
}
