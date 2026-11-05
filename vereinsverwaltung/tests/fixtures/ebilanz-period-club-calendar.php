<?php declare(strict_types=1);
require __DIR__.'/../../src/bootstrap.php';
require_once __DIR__.'/balance-club-tax.php';
require_once __DIR__.'/../../src/Finance/Balance/BalancePeriodCombinedValues.php';
use Kulturhus\Verein\{BalanceCalculation as C,BalanceSegmentCalculation as S,BalanceWholeClubReport as Whole,BalanceClubTaxReport as CR,
    BalanceClubIncomeReconciliation as I,BalanceInternalClearing as W,BalanceClearingStatement as ST,BalancePeriodAssetAllocation as A,
    BalancePeriodDisposal as D,BalanceCalendarClubSource as CC,BalanceTaxValues as V,BalancePeriodClubReport as R,BalancePeriodTaxValues as T,
    BalancePeriodClubFilingValues as F,BalancePeriodCombinedValues as Combined,BalanceCombinedClubValues as OldCombined};
function clubCalendarFixture(string $variant):array
{
    $combined=str_starts_with($variant,'combined-');$tax=str_contains($variant,'tax');$successor=str_ends_with($variant,'successor');
    $state=clubTaxFixture(true);$r=$state['journal'];$r['previous_sha256']=null;$allocation=$state['allocation'];$shares=[];
    foreach($r['asset_schedule']['items'] as $id=>$item)$shares[$id]=['item_sha256'=>S::hash($item),'weights'=>[$item['sphere']==='4'?'food':'culture'=>1],'measure'=>'uses','reason'=>'Synthetic original use'];
    $state=clubTaxState($r,$allocation['units'],$allocation['allocations'],2,[],[],$shares);$a=$state['allocation'];$report=CR::fromState($state);
    $w=W::build('synthetic',$r,$a['units'],$a['allocations'],['advances'=>[],'repayments'=>[]]);
    $ref=['revision'=>2,'sha256'=>$state['history'][0]['sha256']];
    $income=I::rebuild(['schema'=>1,'kind'=>'club_income_reconciliation','club_id'=>'synthetic','source'=>Whole::fromReport($r)['whole_club_scope']['source'],
        'allocation'=>$a,'clearing'=>$w,'review'=>['allocation'=>$ref,'clearing'=>$ref],'previous'=>null]);
    $review=['year'=>2025,'journal_sha256'=>$report['closed_sha256'],'complete'=>true,'opening_adjustments'=>[],'current_adjustments'=>[],'asset_tax_plans'=>[]];
    if($tax)$review['asset_tax_plans'][array_key_first($report['asset_schedule']['items'])]=['months'=>120,'depreciation_account'=>'depreciation','reason'=>'Synthetic tax life','confirmed'=>true];
    $oldValues=V::build($report,$review);$record=CC::record($income,$report,$oldValues,$review);$calendarScope=$report['club_scope'];
    if($combined)$record['combined']=OldCombined::fromReport($report,['year'=>2025,'journal_sha256'=>$r['closed_sha256'],'complete'=>true,'taxable_review'=>$review]);
    $previous=$record;$original=$report;$priorValues=$oldValues;
    foreach([['start'=>'2026-01-01','end'=>'2026-06-30'],['start'=>'2026-07-01','end'=>'2026-12-31']] as $index=>$period){
        $old=$income['scope']['source'];$prior=$old['assets']??A::calendar($old,$income['scope']['allocation']);$items=[];$entries=[];$rows=[];$shares=$prior['shares'];
        foreach($prior['items'] as $id=>$item){
            $d=D::calculate($item['plan'],$period,$item['schedule'],$item['disposal']??null);$items[$id]=array_replace($item,['schedule'=>$d,'posted'=>true]);$amount=$d['depreciation_cents'];
            if($amount){$key=md5('Synthetic dep '.$id.' '.$period['end']);$entries[]=['id'=>$key,'date'=>$period['end'],'description'=>'Synthetic depreciation','lines'=>[
                ['account'=>'depreciation','debit_cents'=>$amount,'credit_cents'=>0,'sphere'=>$item['plan']['sphere']],['account'=>$item['fixed_account'],'debit_cents'=>0,'credit_cents'=>$amount,'sphere'=>'']]];
                foreach([0,1] as $side)$rows[$key.':'.$side]=['parts'=>[$item['plan']['sphere']==='4'?'food':'culture'=>$amount],'reason'=>'Synthetic original use'];}
            $shares[$id]['item_sha256']=S::hash($items[$id]);
        }
        $r=Whole::fromReport($old)['whole_club_scope']['source'];$r['year']=2026;$r['previous_sha256']=$old['closed_sha256'];$r['closed_sha256']=hash('sha256','Synthetic '.$period['end']);
        $r['opening']=C::carryForward($old['calculation'],'equity');$r['entries']=$entries;$r['period']=$period;$r['calculation']=C::build(2026,$r['accounts'],$r['opening'],$entries,$period);$r['asset_schedule']=['period'=>$period,'items'=>$items,'issues'=>[]];
        $carry=ST::carry($old,$income['scope']['allocation'],$income['scope']['clearing'],$r);$a=S::build($r,$carry['units'],$carry['allocations']+$rows,$carry['capital_carry']??[]);
        $w=W::build('synthetic',$r,$a['units'],$a['allocations'],['advances'=>[],'repayments'=>[]],$income['scope']['clearing']);$statement=ST::build($r,$a,$w);
        $r['assets']=A::build($r,$a['units'],$statement['units'],$shares,$prior);
        $income=I::rebuild(['schema'=>3,'kind'=>'club_income_reconciliation','club_id'=>'synthetic','source'=>$r,'allocation'=>$a,'clearing'=>$w,'review'=>['allocation'=>$ref,'clearing'=>$ref],'previous'=>$income['scope']]);
        $report=R::build($income,0,$calendarScope);$carry=T::carryForward($original,$priorValues,$report);
        $rv=['period'=>$period,'year'=>2026,'journal_sha256'=>$report['closed_sha256'],'complete'=>true,'opening_adjustments'=>$carry['entry']?[$carry['entry']]:[],
            'current_adjustments'=>[],'asset_tax_plans'=>$review['asset_tax_plans']];
        $out=$combined?Combined::build($income,$rv,$previous):F::build($income,$rv,$previous);
        if(!$successor||$index===1)return $out;
        $previous=$out;$original=$report;$priorValues=F::raw($out['taxable']??$out);unset($priorValues['period_source'],$priorValues['sha256'],$priorValues['transmission']);$priorValues+=['sha256'=>S::hash($priorValues),'transmission'=>'unavailable'];
    }
    throw new LogicException('Synthetic fixture did not return');
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__)echo json_encode(clubCalendarFixture($argv[1]??'tax'),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
