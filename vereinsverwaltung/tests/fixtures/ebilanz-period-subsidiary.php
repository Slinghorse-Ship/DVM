<?php declare(strict_types=1);
require __DIR__.'/../../src/bootstrap.php';
require __DIR__.'/balance-period-tax.php';
require_once __DIR__.'/../../src/Finance/Balance/BalanceSubsidiaryClubValues.php';
use Kulturhus\Verein\{BalanceSubsidiaryClubValues as V,BalanceClubTaxReconciliation as T,BalanceClubIncomeReconciliation as I,
    BalanceCalculation as C,BalanceSegmentCalculation as S,BalanceInternalClearing as W,BalanceClearingStatement as CS,BalancePeriodAssetAllocation as A};
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});

function subsidiaryPeriodFixture(string $variant):array {
    if(!in_array($variant,['book','tax','off-balance','book-successor','tax-successor','off-balance-successor'],true))throw new InvalidArgumentException('Unknown synthetic variant');
    $base=null;$previousIncome=null;$previous=null;
    for($index=0;$index<2;$index++){
        $base=periodTaxIncomeFixture($base);$r=$base['scope']['source'];unset($r['assets']);$period=$r['period'];$units=[];$targets=[];
        foreach(['ideal'=>'1','property'=>'2','purpose'=>'3','activity'=>'4','second'=>'4'] as $unit=>$sphere){
            $units[$unit]=['name'=>'Synthetic '.$unit,'sphere'=>$sphere];
            $id=md5('expense-'.$unit.$period['end']);$targets[$id]=$unit;
            $r['entries'][]=['id'=>$id,'date'=>$period['end'],'description'=>'Synthetic cost '.$unit,'lines'=>[
                ['account'=>'expense','debit_cents'=>301,'credit_cents'=>0,'sphere'=>$sphere],['account'=>'cash','debit_cents'=>0,'credit_cents'=>301,'sphere'=>'']]];
        }
        $units=S::units($units);
        if($previousIncome){$old=$previousIncome['scope']['source'];$r['opening']=C::carryForward($old['calculation'],'equity');$r['previous_sha256']=$old['closed_sha256'];}
        $r['closed_sha256']=hash('sha256',json_encode([$period,$r['entries'],$r['opening']]));
        $r['calculation']=C::build(2026,$r['accounts'],$r['opening'],$r['entries'],$period);$alloc=[];$capital=[];
        if($previousIncome){
            $carry=CS::carry($old,$previousIncome['scope']['allocation'],$previousIncome['scope']['clearing'],$r);
            $alloc=$carry['allocations'];$capital=$carry['capital_carry']??[];
        }
        foreach(S::sources($r) as $key=>$row){
            if(isset($alloc[$key]))continue;
            $parts=$row['voucher']==='opening'?['ideal'=>200000,'property'=>200000,'purpose'=>200000,'activity'=>1200000,'second'=>200000]
                :[($targets[$row['voucher']]??'activity')=>$row['cents']];
            $alloc[$key]=['parts'=>$parts,'reason'=>'Synthetic recorded use'];
        }
        $allocation=S::build($r,$units,$alloc,$capital);
        $clearing=W::build('synthetic-tax',$r,$units,$alloc,['advances'=>[],'repayments'=>[]],$previousIncome['scope']['clearing']??null,$capital);
        $statement=CS::build($r,$allocation,$clearing);$shares=[];
        foreach($r['asset_schedule']['items'] as $id=>$item)$shares[$id]=['item_sha256'=>S::hash($item),'weights'=>['activity'=>1],'measure'=>'uses','reason'=>'Synthetic original use'];
        $r['assets']=A::build($r,$units,$statement['units'],$shares,$old['assets']??null);
        $ref=['revision'=>2,'sha256'=>hash('sha256','Synthetic area review')];
        $income=I::rebuild(['schema'=>3,'kind'=>'club_income_reconciliation','club_id'=>'synthetic-tax','source'=>$r,'allocation'=>$allocation,'clearing'=>$clearing,
            'review'=>['allocation'=>$ref,'clearing'=>$ref],'previous'=>$previousIncome['scope']??null]);
        $review=periodTaxReview($income);
        if(!str_starts_with($variant,'book')){
            foreach($r['asset_schedule']['items'] as $id=>$item)$review['asset_tax_plans'][$id]=['months'=>120,'depreciation_account'=>$id==='software'?'softwaredep':'dep','reason'=>'Synthetic reviewed tax life','confirmed'=>true];
            if(!$previous)$review['current_adjustments'][]=['id'=>'receivable-value','reason'=>'Synthetic evidenced valuation','lines'=>[
                ['account'=>'receivables','debit_cents'=>501,'credit_cents'=>0,'sphere'=>''],['account'=>'expense','debit_cents'=>0,'credit_cents'=>501,'sphere'=>'4']]];
        }
        if($previous){$carry=T::carryForward($previous,$income);$review['opening_adjustments']=$carry['entry']?[$carry['entry']]:[];}
        if(str_starts_with($variant,'off-balance')){
            $temporary=T::build($income,$review,$previous);
            foreach($temporary['off_balance']['sources'] as $key=>$source){
                if($source['cents']<=0||!in_array($source['group'],['expense','revenue'],true))continue;
                $review['off_balance_adjustments'][]=['id'=>'source-'.count($review['off_balance_adjustments']),'kind'=>$source['group']==='expense'?'non_deductible_expense':'tax_exempt_income',
                    'sources'=>[$key=>min(57,$source['cents'])],'reason'=>'Synthetic <text> & proof','legal_basis'=>'Synthetic reviewed legal basis','confirmed'=>true];
            }
        }
        $value=T::build($income,$review,$previous);
        if(!str_ends_with($variant,'successor')||$index===1)return V::build($value);
        $previousIncome=$income;$previous=$value;
    }
    throw new RuntimeException('Synthetic case did not finish');
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__)echo json_encode(subsidiaryPeriodFixture($argv[1]??'book'),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
